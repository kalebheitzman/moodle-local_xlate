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

/**
 * Records translator activity for payroll/auditing purposes.
 */
class activity_logger {
    public const ACTION_CREATE = 'translation_create';
    public const ACTION_UPDATE = 'translation_update';
    public const ACTION_AUTOTRANSLATE = 'translation_autotranslate';
    public const ACTION_REVIEW_MARK = 'translation_review_mark';
    public const ACTION_REVIEW_CLEAR = 'translation_review_clear';
    public const ACTION_STATUS_ACTIVE = 'translation_status_active';
    public const ACTION_STATUS_INACTIVE = 'translation_status_inactive';

    /** @var array<int,int> */
    protected static array $charcache = [];
    /** @var array<int,int> */
    protected static array $coursecache = [];
    /** @var bool|null */
    protected static ?bool $tableavailable = null;

    /**
     * Persist an activity row.
     *
     * @param int $keyid
     * @param int $translationid
     * @param string $lang
     * @param string $action
     * @param array<string,mixed> $meta
     * @param int|null $courseid Optional explicit course context for the action.
     * @return void
     */
    public static function log(int $keyid, int $translationid, string $lang, string $action,
            array $meta = [], ?int $courseid = null): void {
        global $DB, $USER;

        if ($keyid <= 0 || $translationid <= 0 || $action === '' || !self::is_table_available()) {
            return;
        }

        $resolvedcourseid = (int)($courseid ?? 0);
        if ($resolvedcourseid > 0) {
            self::$coursecache[$keyid] = $resolvedcourseid;
        } else {
            $resolvedcourseid = self::resolve_courseid($keyid);
        }

        $record = (object) [
            'keyid' => $keyid,
            'translationid' => $translationid,
            'lang' => $lang,
            'userid' => isset($USER->id) ? (int)$USER->id : 0,
            'courseid' => $resolvedcourseid,
            'action' => $action,
            'chars' => self::get_source_char_count($keyid),
            'notes' => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ];

        try {
            $DB->insert_record('local_xlate_activity', $record);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Check once per request whether the activity table is present.
     */
    protected static function is_table_available(): bool {
        global $DB;

        if (self::$tableavailable !== null) {
            return self::$tableavailable;
        }

        try {
            $dbman = $DB->get_manager();
            self::$tableavailable = $dbman->table_exists(new \xmldb_table('local_xlate_activity'));
        } catch (\Throwable $e) {
            self::$tableavailable = false;
        }

        return self::$tableavailable;
    }

    /**
     * Return the per-key source character count, cached for reuse.
     */
    protected static function get_source_char_count(int $keyid): int {
        global $DB;

        if (isset(self::$charcache[$keyid])) {
            return self::$charcache[$keyid];
        }

        $chars = 0;
        try {
            $source = $DB->get_field('local_xlate_key', 'source', ['id' => $keyid]);
            if (is_string($source) && $source !== '') {
                $text = html_to_text($source, 0, false);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = preg_replace('/\s+/u', ' ', trim($text));
                if ($text !== '') {
                    $chars = (int)\core_text::strlen($text, 'UTF-8');
                }
            }
        } catch (\Throwable $e) {
            $chars = 0;
        }

        self::$charcache[$keyid] = $chars;
        return $chars;
    }

    /**
     * Resolve the first course id associated with the key.
     */
    protected static function resolve_courseid(int $keyid): int {
        global $DB;

        if (isset(self::$coursecache[$keyid])) {
            return self::$coursecache[$keyid];
        }

        $courseid = 0;
        try {
            $sql = 'SELECT courseid FROM {local_xlate_key_course} WHERE keyid = :keyid ORDER BY mtime DESC, id DESC';
            $value = $DB->get_field_sql($sql, ['keyid' => $keyid], IGNORE_MISSING);
            if ($value !== false && $value !== null) {
                $courseid = (int)$value;
            }
        } catch (\Throwable $e) {
            $courseid = 0;
        }

        self::$coursecache[$keyid] = $courseid;
        return $courseid;
    }
}
