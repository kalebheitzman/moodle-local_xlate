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
 * Utilities for normalising HTML fragments stored in translation texts.
 */
class translation_cleanup {
    private const BATCH_SIZE = 500;
    private const CONTROL_CHAR_PATTERN = '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u';
    private const SEQUENCE_REPLACEMENTS = [
        '\\/' => '/',
        '</\\' => '</',
        '<\\' => '<',
        '\\>' => '>',
    ];
    /**
     * Run the cleanup process across stored translations.
     *
     * @param bool $dryrun When true, do not update the database.
     * @param int|null $maxupdates Optional limit on the number of updates to apply.
     * @param string|null $lang Optional language code filter.
     * @param callable|null $onupdate Callback invoked when a row would be updated. Signature: function(\stdClass $record, string $cleaned).
     * @return array{checked:int,updated:int,dryrun:bool,limitreached:bool}
     */
    public static function cleanup_translations(bool $dryrun = false, ?int $maxupdates = null, ?string $lang = null, ?callable $onupdate = null): array {
        global $DB;

        $checked = 0;
        $updated = 0;
        $limitreached = false;
        $now = time();

        $fields = 'id, lang, text, mtime';
        $select = 'id > :lastid';
        $params = ['lastid' => 0];
        if (!empty($lang)) {
            $select .= ' AND lang = :lang';
            $params['lang'] = $lang;
        }

        do {
            $records = $DB->get_records_select('local_xlate_tr', $select, $params, 'id ASC', $fields, 0, self::BATCH_SIZE);
            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                $checked++;

                if (!self::needs_cleanup($record->text)) {
                    continue;
                }

                $cleaned = self::sanitize_html($record->text);
                if ($cleaned === $record->text) {
                    continue;
                }

                if ($onupdate) {
                    $onupdate($record, $cleaned);
                }

                if (!$dryrun) {
                    $record->text = $cleaned;
                    $record->mtime = $now;
                    $DB->update_record('local_xlate_tr', $record);
                }

                $updated++;
                if ($maxupdates !== null && $updated >= $maxupdates) {
                    $limitreached = true;
                    break 2;
                }
            }

            $last = array_key_last($records);
            $params['lastid'] = $last ?? $params['lastid'];
        } while (!$limitreached);

        return [
            'checked' => $checked,
            'updated' => $updated,
            'dryrun' => $dryrun,
            'limitreached' => $limitreached,
        ];
    }

    /**
     * Determine whether the supplied text contains malformed tag markers we can fix.
     *
     * @param string $text Translation text to inspect.
     * @return bool
     */
    protected static function needs_cleanup(string $text): bool {
        return str_contains($text, '\\/')
            || str_contains($text, '</\\')
            || str_contains($text, '<\\')
            || self::contains_control_chars($text);
    }

    /**
     * Normalise malformed tags such as </\strong> or <\span>.
     *
     * @param string $text Translation text to clean.
     * @return string
     */
    public static function sanitize_html(string $text): string {
        $clean = str_replace(array_keys(self::SEQUENCE_REPLACEMENTS), array_values(self::SEQUENCE_REPLACEMENTS), $text);

        $stripped = preg_replace(self::CONTROL_CHAR_PATTERN, '', $clean);
        if ($stripped !== null) {
            $clean = $stripped;
        }

        return $clean;
    }

    /**
     * Detects whether the string contains ASCII control characters we should remove.
     */
    protected static function contains_control_chars(string $text): bool {
        return (bool)preg_match(self::CONTROL_CHAR_PATTERN, $text);
    }
}
