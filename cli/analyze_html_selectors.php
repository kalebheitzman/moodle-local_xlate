<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Quick helper to summarize DOM selectors inside rendered Moodle HTML snapshots.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$paths = collect_html_files($CFG->dirroot . '/local/xlate/html');
$topn = 25;

$output = array_map(function(string $path) use ($topn): string {
    if (!is_readable($path)) {
        return "File: {$path}\n  [skipped: not readable]\n";
    }

    $html = file_get_contents($path);
    if ($html === false) {
        return "File: {$path}\n  [skipped: unable to read]\n";
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $msg = empty($errors) ? 'unknown parse error' : trim($errors[0]->message ?? 'parse error');
        return "File: {$path}\n  [skipped: {$msg}]\n";
    }
    libxml_clear_errors();

    $stats = [
        'ids' => [],
        'classes' => [],
        'regions' => [],
        'roles' => [],
    ];

    $totalNodes = 0;
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*') as $node) {
        ++$totalNodes;
        if ($node->hasAttributes()) {
            $id = $node->attributes->getNamedItem('id');
            if ($id && $id->nodeValue !== '') {
                $stats['ids'][$id->nodeValue] = ($stats['ids'][$id->nodeValue] ?? 0) + 1;
            }
            $class = $node->attributes->getNamedItem('class');
            if ($class && $class->nodeValue !== '') {
                $tokens = preg_split('/\s+/', trim($class->nodeValue));
                foreach ($tokens as $token) {
                    if ($token !== '') {
                        $stats['classes'][$token] = ($stats['classes'][$token] ?? 0) + 1;
                    }
                }
            }
            $region = $node->attributes->getNamedItem('data-region');
            if ($region && $region->nodeValue !== '') {
                $stats['regions'][$region->nodeValue] = ($stats['regions'][$region->nodeValue] ?? 0) + 1;
            }
            $role = $node->attributes->getNamedItem('role');
            if ($role && $role->nodeValue !== '') {
                $stats['roles'][$role->nodeValue] = ($stats['roles'][$role->nodeValue] ?? 0) + 1;
            }
        }
    }

    $report = [];
    $report[] = "File: {$path}";
    $report[] = "  Nodes scanned: {$totalNodes}";
    foreach ([
        'IDs' => 'ids',
        'Classes' => 'classes',
        'Data regions' => 'regions',
        'ARIA roles' => 'roles',
    ] as $label => $key) {
        $report[] = format_selector_block($label, $stats[$key], $topn);
    }

    return implode("\n", $report) . "\n";
}, $paths);

cli_writeln(implode("\n", $output));

function collect_html_files(string $dir): array {
    if (!is_dir($dir)) {
        cli_error("Directory not found: {$dir}");
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $paths = [];
    foreach ($rii as $file) {
        if ($file->isDir()) {
            continue;
        }
        if (preg_match('/\.html?$/i', $file->getFilename())) {
            $paths[] = $file->getPathname();
        }
    }
    if (empty($paths)) {
        cli_error("No HTML files found in {$dir}");
    }
    $paths = array_values(array_unique($paths));
    sort($paths);
    return $paths;
}

function format_selector_block(string $label, array $data, int $limit): string {
    if (empty($data)) {
        return "  {$label}: (none)";
    }
    arsort($data, SORT_NUMERIC);
    $lines = array_slice($data, 0, $limit, true);
    $items = [];
    foreach ($lines as $key => $count) {
        $items[] = sprintf('    %-35s %5d', $key, $count);
    }
    return "  {$label}:\n" . implode("\n", $items);
}
