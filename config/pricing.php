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
 * Pricing table used by Local Xlate to estimate translation costs.
 *
 * Returns an associative array of model -> prompt/completion token prices.
 *
 * @package    local_xlate
 * @category   config
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

return [
    // Model => [ 'prompt' => price per 1K prompt tokens, 'completion' => price per 1K completion tokens ]
    'gpt-4.1' => [
        'prompt' => 0.03,      // $0.03 per 1K prompt tokens
        'completion' => 0.06   // $0.06 per 1K completion tokens
    ],
    'gpt-3.5-turbo' => [
        'prompt' => 0.001,
        'completion' => 0.002
    ],
    'gpt-5' => [
        'prompt' => 0.10,      // $0.10 per 1K prompt tokens (example)
        'completion' => 0.20   // $0.20 per 1K completion tokens (example)
    ],
    // Add more models as needed
];
