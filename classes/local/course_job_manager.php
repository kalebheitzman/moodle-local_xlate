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

        $total = $totaloverride ?? self::count_course_keys($courseid);

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

        try {
            $jobid = $DB->insert_record('local_xlate_course_job', $record);
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
     * @return bool
     */
    public static function has_pending_job(int $courseid, array $targetlangs, bool $onlymissing): bool {
        global $DB;

        $jobs = $DB->get_records('local_xlate_course_job', ['courseid' => $courseid, 'status' => 'pending']);
        if (empty($jobs)) {
            return false;
        }

        sort($targetlangs);
        foreach ($jobs as $job) {
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

            if ($jobonlymissing === $onlymissing && $jobtargetlangs === $targetlangs) {
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
}
