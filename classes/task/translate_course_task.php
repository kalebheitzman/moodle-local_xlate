<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Course-level autotranslate adhoc task for Local Xlate.
 *
 * Consumes queued course jobs, invokes the backend translator, and persists
 * returned strings while tracking progress across batches.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

/**
 * Adhoc task to process a course-scoped autotranslate job in batches.
 *
 * The task expects custom data containing `jobid`, which identifies a record in
 * `local_xlate_course_job`. Each execution processes a slice of the job and
 * requeues itself until all associated keys are translated.
 *
 * @package local_xlate\task
 */
class translate_course_task extends adhoc_task {
    /**
     * Return the language string for this adhoc task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('translatecoursejobtask', 'local_xlate');
    }

    /**
     * Execute an autotranslate course job.
     *
     * Loads the job definition, pulls the next chunk of key-course associations,
     * calls the translation backend (supporting multiple target languages), and
     * persists the responses through the Local Xlate API. Progress metadata is
     * updated after each batch and the task is requeued until completion.
     *
     * @return void
     * @throws \Exception Propagates backend failures to trigger core retries.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data) || !is_object($data)) {
            return;
        }

        $jobid = isset($data->jobid) ? (int)$data->jobid : 0;
        if (!$jobid) {
            return;
        }

        $job = $DB->get_record('local_xlate_course_job', ['id' => $jobid]);
        if (!$job) {
            return;
        }

        if ($job->status === 'complete') {
            return;
        }

        if ($job->status === 'pending') {
            $job->status = 'running';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
        }

        // Decode options if set.
        $options = [];
        if (!empty($job->options)) {
            $opts = json_decode((string)$job->options, true);
            if (is_array($opts)) {
                $options = $opts;
            }
        }

        $batchsize = (int)($job->batchsize ?: 50);
        $lastid = (int)$job->lastid;

        $targetlangs = [];
        if (!empty($options['targetlangs'])) {
            $targetlangs = (array)$options['targetlangs'];
        } elseif (!empty($options['targetlang'])) {
            $targetlangs = (array)$options['targetlang'];
        }
        $targetlangs = array_values(array_unique(array_filter(array_map('trim', $targetlangs))));

        $onlymissing = !empty($options['onlymissing']);

        // Select next set of key-course associations for this course.
        $sql = "SELECT kc.id as kc_id, kc.keyid, k.component, k.xkey, k.source
                  FROM {local_xlate_key_course} kc
                  JOIN {local_xlate_key} k ON k.id = kc.keyid";

        $params = ['courseid' => (int)$job->courseid, 'lastid' => $lastid];

        $sql .= " WHERE kc.courseid = :courseid AND kc.id > :lastid";
        $sql .= " ORDER BY kc.id ASC";

        $records = $DB->get_records_sql($sql, $params, 0, $batchsize);

        if (empty($records)) {
            // Nothing more to do; mark job complete.
            $job->status = ((int)$job->total > 0 && (int)$job->processed < (int)$job->total)
                ? 'complete_partial'
                : 'complete';
            if ($job->status === 'complete') {
                $job->processed = (int)$job->total;
            }
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
            return;
        }

        // Build items for backend translator.
        $items = [];
        $lastProcessedId = $lastid;
        foreach ($records as $rec) {
            $items[] = [
                'id' => (string)$rec->xkey,
                'component' => (string)$rec->component,
                'key' => (string)$rec->xkey,
                'source_text' => (string)$rec->source,
                'courseid' => (int)$job->courseid,
                'context' => ''
            ];
            $lastProcessedId = max($lastProcessedId, (int)$rec->kc_id);
        }

        // Determine source and target languages from course custom fields first, then fall back to options.
        $config = \local_xlate\customfield_helper::get_course_config((int)$job->courseid);
        if ($config === null) {
            // Course has no xlate configuration - skip translation
            mtrace("Course {$job->courseid} has no xlate language configuration. Marking job complete.");
            $job->status = 'complete';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
            return;
        }

        $sourcelang = $options['sourcelang'] ?? $config['source'];

        if (empty($targetlangs)) {
            $targetlangs = $config['targets'];
        }

        $targetlangs = array_values(array_unique(array_filter($targetlangs, function ($code) use ($sourcelang) {
            return $code && $code !== $sourcelang;
        })));

        if (empty($targetlangs)) {
            // No target languages configured - nothing to translate
            mtrace("Course {$job->courseid} has no target languages configured. Marking job complete.");
            $job->status = ((int)$job->total > 0 && (int)$job->processed < (int)$job->total)
                ? 'complete_partial'
                : 'complete';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
            return;
        }

        $itemsbylang = [];
        foreach ($targetlangs as $targetlang) {
            if ($targetlang === '' || $targetlang === $sourcelang) {
                continue;
            }

            foreach ($items as $item) {
                if ($onlymissing) {
                    $existing = $DB->get_record('local_xlate_tr', [
                        'keyid' => (int)$item['keyid'],
                        'lang' => $targetlang,
                        'status' => 1,
                    ], 'id', IGNORE_MISSING);
                    if ($existing) {
                        continue;
                    }
                }

                $payload = $item;
                unset($payload['keyid']);
                $itemsbylang[$targetlang][] = $payload;
            }
        }

        $successfulunits = 0;
        foreach ($itemsbylang as $targetlang => $langitems) {
            if (empty($langitems)) {
                continue;
            }

            try {
                $result = \local_xlate\translation\backend::translate_batch(
                    'coursejob_' . $jobid . '_' . $targetlang,
                    $sourcelang,
                    $targetlang,
                    $langitems,
                    $options['glossary'] ?? [],
                    $options
                );
            } catch (\Exception $e) {
                // Let core retry policy handle transient backend failures.
                $job->mtime = time();
                $DB->update_record('local_xlate_course_job', $job);
                throw $e;
            }

            if (empty($result['ok']) || empty($result['results']) || !is_array($result['results'])) {
                continue;
            }

            foreach ($result['results'] as $r) {
                $id = $r['id'] ?? null;
                $translated = $r['translated'] ?? null;
                if ($translated === null) {
                    continue;
                }

                $orig = null;
                foreach ($langitems as $it) {
                    $itid = (string)($it['id'] ?? '');
                    $itkey = (string)($it['key'] ?? '');
                    if ($itid === (string)$id || $itkey === (string)$id) {
                        $orig = $it;
                        break;
                    }
                }
                if (!$orig || empty($orig['component']) || empty($orig['key'])) {
                    continue;
                }

                try {
                    debugging('[local_xlate] Persisting translation for ' . $orig['component'] . ':' . $orig['key'] . ' lang=' . $targetlang, DEBUG_DEVELOPER);
                    \local_xlate\local\api::save_key_with_translation(
                        (string)$orig['component'],
                        (string)$orig['key'],
                        (string)($orig['source_text'] ?? ''),
                        $targetlang,
                        (string)$r['translated'],
                        0,
                        (int)$orig['courseid'],
                        (string)$orig['context']
                    );
                    $successfulunits++;
                } catch (\Exception $e) {
                    // Keep processing the batch, but don't count failed saves as progress.
                }
            }
        }

        // Update job progress
        $job->lastid = $lastProcessedId;
        $job->processed = (int)$job->processed + $successfulunits;
        if ($job->total > 0 && $job->processed > $job->total) {
            $job->processed = (int)$job->total;
        }
        $job->mtime = time();
        $DB->update_record('local_xlate_course_job', $job);

        // Requeue this task if there are likely more items (we used a strict limit)
        if (count($records) >= $batchsize) {
            $newtask = new self();
            $newtask->set_custom_data((object)['jobid' => $jobid]);
            \core\task\manager::queue_adhoc_task($newtask);
        } else {
            // Mark complete
            $job->status = ((int)$job->total > 0 && (int)$job->processed < (int)$job->total)
                ? 'complete_partial'
                : 'complete';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
        }
    }
}
