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

namespace local_xlate\local;

defined('MOODLE_INTERNAL') || die();

use local_xlate\customfield_helper;
use local_xlate\task\translate_course_task;
use core\task\manager as task_manager;

/**
 * Helper responsible for inserting course autotranslate jobs and queuing tasks.
 */
class course_job_manager {
    /**
     * Enqueue an autotranslate job for a course.
     *
     * @param int $courseid Course identifier.
     * @param array<string,mixed> $options Job options (batchsize, targetlangs, onlymissing, etc.).
     * @param int $userid User originating the job (0 for scheduled tasks).
     * @param int|null $totaloverride Optional override for the job total count.
     * @return array{success:bool,jobid?:int,taskid?:int,error?:string}
     */
    public static function enqueue_course_job(int $courseid, array $options = [], int $userid = 0, ?int $totaloverride = null): array {
        global $DB, $USER;

        $config = customfield_helper::get_course_config($courseid);
        if ($config === null) {
            return ['success' => false, 'error' => 'Course has no xlate language configuration. Please set source and target languages in course settings.'];
        }

        $installedlangs = array_keys(get_string_manager()->get_list_of_translations());
        $sourcelang = $config['source'];
        $targetlangs = self::resolve_targetlangs($options, $config, $installedlangs, $sourcelang);

        if (empty($targetlangs)) {
            return ['success' => false, 'error' => 'Course has no target languages configured. Please select at least one target language in course settings or the Manage UI card.'];
        }

        $batchsize = isset($options['batchsize']) ? (int)$options['batchsize'] : 50;
        if ($batchsize <= 0) {
            $batchsize = 50;
        }

        if ($userid <= 0 && !empty($USER->id)) {
            $userid = (int)$USER->id;
        }

        $normalizedoptions = $options;
        unset($normalizedoptions['targetlang']);
        $normalizedoptions['sourcelang'] = $sourcelang;
        $normalizedoptions['targetlangs'] = $targetlangs;
        $normalizedoptions['batchsize'] = $batchsize;

        $onlymissing = !empty($normalizedoptions['onlymissing']);
        $onlyunreviewed = !empty($normalizedoptions['onlyunreviewed']);

        $total = $totaloverride ?? self::count_course_work_units($courseid, $targetlangs, $onlymissing, $onlyunreviewed);

        // Purge old completed/complete_partial jobs for this course so the queue
        // stays clean automatically. Keep the 5 most recent completed jobs per
        // course for debugging; delete the rest. Zero-progress complete_partial
        // jobs (API failures) are deleted unconditionally since they carry no
        // useful history.
        try {
            // Always delete zero-progress complete_partial jobs — they are noise.
            $DB->delete_records_select(
                'local_xlate_course_job',
                'courseid = :courseid AND status = :status AND processed = 0',
                ['courseid' => $courseid, 'status' => 'complete_partial']
            );

            // Keep only the 5 most recent completed/complete_partial jobs per course.
            list($statusql, $statusparams) = $DB->get_in_or_equal(
                ['complete', 'complete_partial'], SQL_PARAMS_NAMED, 'cls'
            );
            $keepids = $DB->get_fieldset_select(
                'local_xlate_course_job',
                'id',
                "courseid = :courseid AND status $statusql",
                array_merge(['courseid' => $courseid], $statusparams),
                'id DESC',
                5
            );
            if (!empty($keepids)) {
                list($notinsql, $notinparams) = $DB->get_in_or_equal($keepids, SQL_PARAMS_NAMED, 'ki', false);
                $DB->delete_records_select(
                    'local_xlate_course_job',
                    "courseid = :courseid AND status $statusql AND id $notinsql",
                    array_merge(['courseid' => $courseid], $statusparams, $notinparams)
                );
            } else {
                $DB->delete_records_select(
                    'local_xlate_course_job',
                    "courseid = :courseid AND status $statusql",
                    array_merge(['courseid' => $courseid], $statusparams)
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal: proceed even if cleanup fails.
        }

        $record = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'status' => 'pending',
            'total' => (int)$total,
            'processed' => 0,
            'batchsize' => $batchsize,
            'options' => json_encode($normalizedoptions),
            'lastid' => 0,
            'ctime' => time(),
            'mtime' => time(),
        ];

        // Re-check for a duplicate inside a transaction to close the TOCTOU race
        // window: two concurrent callers (e.g. scheduled task + manual UI trigger)
        // could both pass has_pending_job() before either inserts. Serialising the
        // insert inside a delegated transaction makes the check+insert atomic from
        // the application's perspective.
        try {
            $transaction = $DB->start_delegated_transaction();

            if (self::has_pending_job($courseid, $targetlangs, $onlymissing, $onlyunreviewed)) {
                list($stinSQL, $stinparams) = $DB->get_in_or_equal(['pending', 'running'], SQL_PARAMS_NAMED, 'ejst');
                $existingjobs = $DB->get_records_select(
                    'local_xlate_course_job',
                    "courseid = :courseid AND status $stinSQL",
                    array_merge(['courseid' => $courseid], $stinparams),
                    'id DESC',
                    '*', 0, 1
                );
                $transaction->allow_commit();
                if ($existingjob = reset($existingjobs)) {
                    return ['success' => true, 'jobid' => (int)$existingjob->id, 'taskid' => 0];
                }
            }

            $jobid = $DB->insert_record('local_xlate_course_job', $record);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $task = new translate_course_task();
        $task->set_custom_data((object)['jobid' => $jobid]);
        $taskid = task_manager::queue_adhoc_task($task);

        return ['success' => true, 'jobid' => $jobid, 'taskid' => $taskid];
    }

    /**
     * Determine whether a pending job already exists for the supplied parameters.
     *
     * @param int $courseid Course identifier.
     * @param array<int,string> $targetlangs Target languages requested.
    * @param bool $onlymissing True when the job is scoped to missing translations only.
    * @param bool $onlyunreviewed True when the job should skip human-reviewed translations.
     * @return bool
     */
    public static function has_pending_job(int $courseid, array $targetlangs, bool $onlymissing, bool $onlyunreviewed = false): bool {
        global $DB;

        // Check both pending AND running: a running job is already doing the work
        // and must not be duplicated any more than a freshly-queued one would be.
        list($statusql, $statusparams) = $DB->get_in_or_equal(['pending', 'running'], SQL_PARAMS_NAMED, 'st');
        $jobs = $DB->get_records_select(
            'local_xlate_course_job',
            "courseid = :courseid AND status $statusql",
            array_merge(['courseid' => $courseid], $statusparams)
        );
        if (empty($jobs)) {
            return false;
        }

        // Running jobs that have not been updated recently are considered dead:
        // the adhoc task likely failed permanently and was abandoned by Moodle's
        // retry policy. 4 hours is well beyond any realistic single-task runtime.
        $stalenessthreshold = time() - (4 * HOURSECS);

        sort($targetlangs);
        foreach ($jobs as $job) {
            if ($job->status === 'running' && (int)$job->mtime < $stalenessthreshold) {
                // Auto-heal: mark the orphaned job complete_partial so the queue
                // stays clean and new jobs can be enqueued for these courses.
                try {
                    $DB->set_field('local_xlate_course_job', 'status', 'complete_partial', ['id' => $job->id]);
                } catch (\Throwable $e) {
                    // Non-fatal — proceed even if the update fails.
                }
                continue;
            }

            $opts = [];
            if (!empty($job->options)) {
                $decoded = json_decode((string)$job->options, true);
                if (is_array($decoded)) {
                    $opts = $decoded;
                }
            }
            $jobtargetlangs = [];
            if (!empty($opts['targetlangs'])) {
                $jobtargetlangs = (array)$opts['targetlangs'];
            } else if (!empty($opts['targetlang'])) {
                $jobtargetlangs = (array)$opts['targetlang'];
            }
            $jobtargetlangs = array_values(array_unique(array_map('strval', $jobtargetlangs)));
            sort($jobtargetlangs);

            $jobonlymissing = !empty($opts['onlymissing']);
            $jobonlyunreviewed = !empty($opts['onlyunreviewed']);

            // Treat as a duplicate if all requested langs are covered by this
            // job's target langs (superset match). Switching to a subset of an
            // already-queued job's languages must not spawn a redundant job.
            $coveredlangs = array_values(array_intersect($targetlangs, $jobtargetlangs));
            sort($coveredlangs);
            if ($jobonlymissing === $onlymissing && $jobonlyunreviewed === $onlyunreviewed && $coveredlangs === $targetlangs) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the list of target languages from options/course configuration.
     *
     * @param array<string,mixed> $options Incoming options.
     * @param array<string,mixed> $courseconfig Course configuration (source/targets).
     * @param array<int,string> $installedlangs Installed Moodle languages.
     * @param string $sourcelang Course source language.
     * @return array<int,string>
     */
    protected static function resolve_targetlangs(array $options, array $courseconfig, array $installedlangs, string $sourcelang): array {
        $requestedtargets = [];
        if (!empty($options['targetlangs'])) {
            $requestedtargets = (array)$options['targetlangs'];
        } else if (!empty($options['targetlang'])) {
            $requestedtargets = (array)$options['targetlang'];
        }

        $requestedtargets = array_values(array_unique(array_filter(array_map('trim', $requestedtargets), static function ($code) {
            return $code !== '';
        })));

        if (!empty($requestedtargets)) {
            $requestedtargets = array_values(array_intersect($requestedtargets, $installedlangs));
        }

        if (empty($requestedtargets)) {
            $requestedtargets = $courseconfig['targets'];
        }

        return array_values(array_filter($requestedtargets, static function ($code) use ($sourcelang) {
            return $code && $code !== $sourcelang;
        }));
    }

    /**
     * Count key-course associations for the supplied course.
     *
     * @param int $courseid Course identifier.
     * @return int
     */
    protected static function count_course_keys(int $courseid): int {
        global $DB;

        try {
            return (int)$DB->count_records('local_xlate_key_course', ['courseid' => $courseid]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Count translation work units for a course job.
     *
     * Work units are keyed by target language:
     * - default mode: all key-course associations for each target language
     * - missing-only mode: only keys lacking active translations per target language
     *
     * @param int $courseid Course identifier.
     * @param array<int,string> $targetlangs Target languages included in the job.
    * @param bool $onlymissing Whether to count only missing active translations.
    * @param bool $onlyunreviewed Whether to include only missing or not-human-reviewed active translations.
     * @return int
     */
    protected static function count_course_work_units(int $courseid, array $targetlangs, bool $onlymissing, bool $onlyunreviewed = false): int {
        global $DB;

        if ($courseid <= 0 || empty($targetlangs)) {
            return 0;
        }

        $targetlangs = array_values(array_unique(array_filter(array_map('trim', $targetlangs), static function ($lang) {
            return $lang !== '';
        })));

        if (empty($targetlangs)) {
            return 0;
        }

        try {
            // All modes use a per-lang query so `total` matches what the task
            // will actually process. The fast-path (key_count × lang_count) was
            // removed because the task unconditionally skips reviewed=1 translations
            // regardless of mode, causing `processed` to never reach `total`.
            $total = 0;
            foreach ($targetlangs as $lang) {
                $sql = "SELECT COUNT(1)
                          FROM {local_xlate_key_course} kc
                          JOIN {local_xlate_key} k ON k.id = kc.keyid
                     LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = :targetlang AND t.status = 1
                         WHERE kc.courseid = :courseid";

                if ($onlymissing) {
                    // Only keys that have no active translation at all.
                    $sql .= ' AND t.id IS NULL';
                } else {
                    // Default and onlyunreviewed modes: the task always skips
                    // reviewed=1 translations, so exclude them from the total.
                    $sql .= ' AND (t.id IS NULL OR t.reviewed <> 1)';
                }
                $total += (int)$DB->get_field_sql($sql, ['courseid' => $courseid, 'targetlang' => $lang]);
            }

            return $total;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
