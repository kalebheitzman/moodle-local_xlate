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
 */
class translate_course_task extends adhoc_task {
    public function get_name(): string {
        return get_string('translatecoursejobtask', 'local_xlate');
    }

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

        // Select next set of key-course associations for this course.
        $sql = "SELECT kc.id as kc_id, kc.keyid, k.component, k.xkey, k.source
                  FROM {local_xlate_key_course} kc
                  JOIN {local_xlate_key} k ON k.id = kc.keyid
                 WHERE kc.courseid = :courseid AND kc.id > :lastid
              ORDER BY kc.id ASC";

        $params = ['courseid' => (int)$job->courseid, 'lastid' => $lastid];
        $records = $DB->get_records_sql($sql, $params, 0, $batchsize);

        if (empty($records)) {
            // Nothing more to do; mark job complete.
            $job->status = 'complete';
            $job->processed = (int)$job->total;
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

        // Determine source and target languages from options or sensible defaults.
        $sourcelang = isset($options['sourcelang']) ? (string)$options['sourcelang'] : 'en';
        $targetlangs = [];
        if (!empty($options['targetlangs']) && is_array($options['targetlangs'])) {
            $targetlangs = $options['targetlangs'];
        } elseif (!empty($options['targetlang'])) {
            $targetlangs = is_array($options['targetlang']) ? $options['targetlang'] : [$options['targetlang']];
        }
        if (empty($targetlangs)) {
            // Nothing to translate for this job; mark complete.
            $job->status = 'complete';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
            return;
        }

        // Call backend to translate this batch (backend handles multiple target langs)
        try {
            $result = \local_xlate\translation\backend::translate_batch(
                'coursejob_' . $jobid,
                $sourcelang,
                $targetlangs,
                $items,
                $options['glossary'] ?? [],
                $options
            );
        } catch (\Exception $e) {
            // On backend failure, update mtime and requeue later by throwing
            // an exception so core task system can retry according to its policy.
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
            throw $e;
        }

        // Persist translations when backend returned results.
        if (!empty($result['ok']) && !empty($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as $r) {
                $id = $r['id'] ?? null;
                $translated = $r['translated'] ?? null;
                if ($translated === null) {
                    continue;
                }

                // Find original item by xkey in this batch.
                $orig = null;
                foreach ($items as $it) {
                    $itid = (string)($it['id'] ?? '');
                    $itkey = (string)($it['key'] ?? '');
                    if ($itid === (string)$id || $itkey === (string)$id) {
                        $orig = $it;
                        break;
                    }
                }
                if (!$orig) {
                    continue;
                }

                if (!empty($orig['component']) && !empty($orig['key'])) {
                    try {
                        // Debug logging: record what we are about to persist for diagnosis.
                        debugging('[local_xlate] Persisting translation for ' . $orig['component'] . ':' . $orig['key'] . ' lang=' . (isset($r['lang']) ? $r['lang'] : (is_array($targetlangs) ? $targetlangs[0] : '')), DEBUG_DEVELOPER);
                        \local_xlate\local\api::save_key_with_translation(
                            (string)$orig['component'],
                            (string)$orig['key'],
                            (string)($orig['source_text'] ?? ''),
                            isset($r['lang']) ? (string)$r['lang'] : (is_array($targetlangs) ? (string)$targetlangs[0] : ''),
                            (string)$r['translated'],
                            0,
                            (int)$orig['courseid'],
                            (string)$orig['context']
                        );
                    } catch (\Exception $e) {
                        // swallow save errors to avoid failing the whole batch
                    }
                }
            }
        }

        // Update job progress
        $job->lastid = $lastProcessedId;
        $job->processed = (int)$job->processed + count($records);
        $job->mtime = time();
        $DB->update_record('local_xlate_course_job', $job);

        // Requeue this task if there are likely more items (we used a strict limit)
        if (count($records) >= $batchsize) {
            $newtask = new self();
            $newtask->set_custom_data((object)['jobid' => $jobid]);
            \core\task\manager::queue_adhoc_task($newtask);
        } else {
            // Mark complete
            $job->status = 'complete';
            $job->mtime = time();
            $DB->update_record('local_xlate_course_job', $job);
        }
    }
}
