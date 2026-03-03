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
 * Shared hash helpers for rehash_keys.php and rehash_keys_dryrun.php.
 *
 * These functions are an exact PHP port of translator.js simpleHash().
 * They must produce byte-for-byte identical output to the JavaScript for
 * every input string, or the migration will generate wrong xkeys.
 *
 * Algorithm applied to each stored key:
 *   $plain = html_entity_decode(strip_tags(trim($source)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
 *   $xkey  = xlate_simple_hash($plain);
 *
 * - strip_tags removes inline HTML (<strong>, <em>, etc.) so entity-encoded
 *   markup variants (e.g. &amp;) do not produce different keys for the same text.
 * - html_entity_decode converts &amp; → & etc., matching the browser's textContent
 *   which always returns decoded literals regardless of how the DOM was populated.
 * - Supplementary Unicode characters (U+10000+) are decomposed into UTF-16 surrogate
 *   pairs before hashing, because JS charCodeAt() returns surrogate code units while
 *   PHP mb_ord() returns single codepoints.
 */

/**
 * Simulate JavaScript Math.imul: signed 32-bit integer multiplication.
 * Splits operands into 16-bit halves to avoid 64-bit overflow in PHP.
 *
 * @param int $a First operand.
 * @param int $b Second operand.
 * @return int Low 32 bits of a×b.
 */
function imul32(int $a, int $b): int {
    $a  = $a & 0xFFFFFFFF;
    $b  = $b & 0xFFFFFFFF;
    $ah = ($a >> 16) & 0xFFFF;
    $al = $a & 0xFFFF;
    $bh = ($b >> 16) & 0xFFFF;
    $bl = $b & 0xFFFF;
    // Low 32 bits of a×b = al×bl + ((ah×bl + al×bh) & 0xFFFF) << 16
    return (($al * $bl) + (((($ah * $bl + $al * $bh) & 0xFFFF) << 16))) & 0xFFFFFFFF;
}

/**
 * Replicate translator.js simpleHash() exactly.
 *
 * Uses FNV-1a on h1 and a Murmur3-inspired mix on h2, both clamped to
 * unsigned 32-bit. Output: 12-char base36 string combining both accumulators.
 *
 * The string is iterated as UTF-16 code units (matching JS charCodeAt).
 * Supplementary characters (U+10000+) are decomposed into surrogate pairs.
 *
 * Uses preg_split('//u') to enumerate codepoints in O(n) rather than
 * the O(n²) mb_substr($str, $i, 1) index approach.
 *
 * @param string $str Input string (plain text, already decoded).
 * @return string 12-character base36 hash.
 */
function xlate_simple_hash(string $str): string {
    $h1 = 2166136261;  // FNV-1a 32-bit offset basis
    $h2 = 0x9e3779b1;  // 2654435761 — golden ratio constant

    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $ch) {
        $c = mb_ord($ch, 'UTF-8');

        // Supplementary characters (U+10000+) produce two UTF-16 code units in JS.
        if ($c >= 0x10000) {
            $cp    = $c - 0x10000;
            $units = [0xD800 + ($cp >> 10), 0xDC00 + ($cp & 0x3FF)];
        } else {
            $units = [$c];
        }

        foreach ($units as $cu) {
            // FNV-1a step on h1.
            $h1 = imul32($h1 ^ $cu, 16777619);

            // Murmur3-inspired avalanche mix on h2.
            $h2  = ($h2 + $cu) & 0xFFFFFFFF;
            $k   = ($h2 ^ ($h2 >> 16)) & 0xFFFFFFFF;
            $h2  = imul32($k, 2246822507);
            $k   = ($h2 ^ ($h2 >> 13)) & 0xFFFFFFFF;
            $h2  = imul32($k, 3266489909);
            $h2  = ($h2 ^ ($h2 >> 16)) & 0xFFFFFFFF;
        }
    }

    $s = base_convert((string)$h1, 10, 36) . base_convert((string)$h2, 10, 36);
    if (strlen($s) < 12) {
        $s = substr($s . 'qwertyuiopasdfghjklz', 0, 12);
    } elseif (strlen($s) > 12) {
        $s = substr($s, 0, 12);
    }
    return $s;
}
