<?php
// This file is part of moodle-ws-catalog.
//
// moodle-ws-catalog is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// moodle-ws-catalog is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with moodle-ws-catalog. If not, see <https://www.gnu.org/licenses/>.

/**
 * For each core branch checks for new versions in github and returns the list of versions
 * that were not yet analysed (not present in core/processed.txt).
 */

require_once __DIR__ . '/common.php';

$coredir = $maindir . '/core';
$pluginsdir = $maindir . '/plugins';
$pluginslistfile = $maindir . '/pluginslist.txt';

$minversion = 3.9;

$toprocess = [];
$moodleversions = json_decode(file_get_contents($maindir . '/moodleversions.json'), true);
foreach ($moodleversions as $vinfo) {
    $branch = get_core_branch($vinfo);
    $coreversion = get_core_version($vinfo);
    $processedfile = $coredir . '/processed.txt';
    $processed = [];
    if (file_exists($processedfile)) {
        $processed = preg_split('/\n/', file_get_contents($processedfile), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (in_array($coreversion, $processed)) {
        // Ignore already processed versions.
        continue;
    }
    $toprocess[] = [
        'coreversion' => $coreversion,
        'moodlebranch' => $branch,
        'phpversion' => (float)$vinfo['phpmin'] < 7.4 ? '7.4' : $vinfo['phpmin'],
    ];
}

// Output.
foreach ($toprocess as $item) {
    echo json_encode($item) . "\n";
}

function get_core_branch($vinfo) {
    return empty($vinfo['version']) ? 'main' : "MOODLE_{$vinfo['moodle']}_STABLE";
}

function get_core_version($vinfo) {
    $branch = get_core_branch($vinfo);
    $public = $vinfo['moodle'] >= 501 ? 'public/' : '';
    $url = "https://raw.githubusercontent.com/moodle/moodle/refs/heads/{$branch}/{$public}version.php";
// https://raw.githubusercontent.com/moodle/moodle/refs/heads/MOODLE_405_STABLE/version.php
// https://raw.githubusercontent.com/moodle/moodle/refs/heads/MOODLE_500_STABLE/version.php
// https://raw.githubusercontent.com/moodle/moodle/refs/heads/MOODLE_501_STABLE/public/version.php
// https://raw.githubusercontent.com/moodle/moodle/refs/heads/main/public/version.php

    $contents = curl_get($url);
    if (!preg_match('/\$version\s*=\s*([\d\.]+);/', $contents, $matches)) {
        throw new Exception("Error: Could not find version in core version.php for branch {$branch}.\n");
    }
    return $matches[1];
}
