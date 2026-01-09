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

        $conditions = [];
        if (!empty($lang)) {
            $conditions['lang'] = $lang;
        }

        $checked = 0;
        $updated = 0;
        $limitreached = false;
        $now = time();

        $fields = 'id, lang, text, mtime';
        $rs = $DB->get_recordset('local_xlate_tr', $conditions, 'id ASC', $fields);
        try {
            foreach ($rs as $record) {
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
                    break;
                }
            }
        } finally {
            $rs->close();
        }

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
        return stripos($text, '</\\') !== false || stripos($text, '<\\') !== false;
    }

    /**
     * Normalise malformed tags such as </\strong> or <\span>.
     *
     * @param string $text Translation text to clean.
     * @return string
     */
    public static function sanitize_html(string $text): string {
        $clean = $text;

        // Repair stray backslashes before tag names in closing tags, e.g. </\strong> -> </strong>.
        $clean = preg_replace('~</\\?([a-z][a-z0-9]*)\s*>~i', '</$1>', $clean);
        if ($clean === null) {
            $clean = $text;
        }

        // Repair stray backslashes before tag names in opening tags, e.g. <\span> -> <span>.
        $clean = preg_replace_callback('~<\\?([a-z][a-z0-9]*)([^>]*)>~i', function ($matches) {
            $tag = $matches[1];
            $attrs = $matches[2] ?? '';
            return '<' . $tag . $attrs . '>';
        }, $clean);

        if ($clean === null) {
            return $text;
        }

        return $clean;
    }
}
