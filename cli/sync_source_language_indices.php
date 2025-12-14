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
 * CLI helper to realign the source language select indices with the current option order.
 *
 * Whenever the xlate source-language select options are reordered (for example, alphabetised),
 * existing courses continue to store the previous integer indices. This tool converts each
 * stored value back to a proper language code, locates the matching option in the *current*
 * select list, and updates the stored integer/value fields so Moodle selects the right item
 * on the course settings form. It also ensures the locale code is cached in the short/char
 * columns for faster follow-up repairs.
 *
 * Usage: sudo -u www-data php local/xlate/cli/sync_source_language_indices.php
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$shortname = 'xlate_source_lang';
$field = $DB->get_record('customfield_field', ['shortname' => $shortname], '*');

if (!$field) {
    mtrace("Field '{$shortname}' not found. Nothing to do.");
    exit(0);
}

$fieldcontroller = \core_customfield\field_controller::create($field->id);
$options = $fieldcontroller->get_options(); // Includes the empty placeholder at index 0.
$installedlangs = get_string_manager()->get_list_of_translations();

$records = $DB->get_records('customfield_data', ['fieldid' => $field->id], 'instanceid ASC');
if (empty($records)) {
    mtrace('No course data found for the source language field.');
    exit(0);
}

$updated = 0;
$skipped = 0;

foreach ($records as $record) {
    $code = resolve_code_from_record($record, $options, $installedlangs);
    if ($code === null) {
        $skipped++;
        mtrace("Skipping data {$record->id} (course {$record->instanceid}): unable to determine language code.");
        continue;
    }

    $expectedindex = find_option_index_for_code($code, $options, $installedlangs);
    if ($expectedindex === null) {
        $skipped++;
        mtrace("Skipping data {$record->id} (course {$record->instanceid}): option for '{$code}' not found.");
        continue;
    }

    $currentindex = (string)($record->intvalue ?? $record->value ?? '');
    $needindexupdate = ((string)$expectedindex !== $currentindex);
    $needcodecache = (trim((string)($record->shortcharvalue ?? '')) !== $code)
        || (trim((string)($record->charvalue ?? '')) !== $code);

    if (!$needindexupdate && !$needcodecache) {
        continue;
    }

    $update = (object)['id' => $record->id];
    if ($needindexupdate) {
        $update->intvalue = $expectedindex;
        $update->value = $expectedindex;
    }
    if ($needcodecache) {
        $update->shortcharvalue = $code;
        $update->charvalue = $code;
    }

    $DB->update_record('customfield_data', $update);
    $updated++;
    mtrace("Updated data {$record->id} (course {$record->instanceid}) to index {$expectedindex} ({$code}).");
}

mtrace("Done. Updated {$updated} records; skipped {$skipped}.");

/**
 * Derive the intended language code for a stored customfield_data row.
 *
 * @param \stdClass $record
 * @param array<int,string> $options
 * @param array<string,string> $installedlangs
 * @return string|null
 */
function resolve_code_from_record(\stdClass $record, array $options, array $installedlangs): ?string {
    $code = trim((string)($record->shortcharvalue ?? ''));
    if ($code === '' && !empty($record->charvalue)) {
        $code = trim((string)$record->charvalue);
    }

    if ($code !== '') {
        return strtolower($code);
    }

    $rawvalue = (string)($record->intvalue ?? $record->value ?? '');
    if ($rawvalue === '') {
        return null;
    }

    $label = resolve_select_index_to_option($rawvalue, $options);
    if ($label === null || $label === '') {
        return null;
    }

    return normalize_lang_value($label, $installedlangs);
}

/**
 * Locate the current option index for a given language code.
 *
 * @param string $code
 * @param array<int,string> $options
 * @param array<string,string> $installedlangs
 * @return int|null
 */
function find_option_index_for_code(string $code, array $options, array $installedlangs): ?int {
    $code = strtolower($code);
    $label = $installedlangs[$code] ?? '';
    $sanitizedlabel = sanitize_label($label);

    foreach ($options as $index => $optionlabel) {
        if ($optionlabel === '' && (int)$index === 0) {
            continue; // Skip the placeholder entry.
        }

        $sanitizedoption = sanitize_label($optionlabel);
        if ($sanitizedlabel !== '' && $sanitizedlabel === $sanitizedoption) {
            return (int)$index;
        }

        if ($optionlabel !== '' && stripos($optionlabel, '(' . $code . ')') !== false) {
            return (int)$index;
        }
    }

    return null;
}

/**
 * Map the stored integer (or fallback value) to the option label.
 *
 * @param string $rawvalue
 * @param array<int,string> $options
 * @return string|null
 */
function resolve_select_index_to_option(string $rawvalue, array $options): ?string {
    if ($rawvalue === '') {
        return null;
    }

    if (!ctype_digit((string)$rawvalue)) {
        return $rawvalue;
    }

    $index = (int)$rawvalue;
    if (array_key_exists($index, $options)) {
        return $options[$index];
    }

    $onebased = $index - 1;
    if ($onebased >= 0 && array_key_exists($onebased, $options)) {
        return $options[$onebased];
    }

    return null;
}

/**
 * Convert an option label back into a Moodle language code.
 *
 * @param string $value
 * @param array<string,string> $installedlangs
 * @return string|null
 */
function normalize_lang_value(string $value, array $installedlangs): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (isset($installedlangs[$value])) {
        return strtolower($value);
    }

    foreach ($installedlangs as $code => $name) {
        if (strcasecmp($name, $value) === 0) {
            return strtolower($code);
        }
    }

    if (preg_match('/\(([a-z]{2,10}(?:_[a-z]{2})?)\)\s*$/i', $value, $matches)) {
        $candidate = strtolower($matches[1]);
        if (isset($installedlangs[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Sanitize a label for comparison (lowercase, strip whitespace and directional marks).
 *
 * @param string $label
 * @return string
 */
function sanitize_label(string $label): string {
    if ($label === '') {
        return '';
    }

    $label = str_replace(["\u{200e}", "\u{200f}"], '', $label);
    $label = preg_replace('/\s+/u', '', $label ?? '');
    return mb_strtolower($label ?? '', 'UTF-8');
}
