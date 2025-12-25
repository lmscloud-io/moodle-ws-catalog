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
 * For each core branch checks for new commits in github and returns the list of branches
 * that were not yet analysed (not present in core/processed.txt).
 */

require_once __DIR__ . '/common.php';

$coredir = $maindir . '/core';
$pluginsdir = $maindir . '/plugins';
$pluginslistfile = $maindir . '/pluginslist.txt';

$minversion = 3.9;

$processedfile = $coredir . '/processed.txt';
if (file_exists($processedfile)) {
    $processedlines = preg_split('/\n/', file_get_contents($processedfile), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($processedlines as $line) {
        $parts = explode(':', $line);
        $processed[$parts[0]] = $parts[1];
    }
}

$toprocess = [];
$branches = [];
for ($page = 1; $page <= 5; $page++) {
    $pagebranches = json_decode(curl_get("https://api.github.com/repos/moodle/moodle/branches?per_page=100&page={$page}"), true);
    if (empty($pagebranches)) {
        break;
    }
    $branches = array_merge($branches, $pagebranches);
}
$moodleversions = json_decode(file_get_contents($maindir . '/moodleversions.json'), true);

foreach ($branches as $branchinfo) {
    $branchname = $branchinfo['name'];
    if ($branchname == "main") {
        $moodle = 0;
    } else if (preg_match('/^MOODLE_(\d+)_STABLE$/', $branchname, $matches)) {
        $moodle = (int)$matches[1];
        if ($moodle < 39) {
           continue;
        }
    } else {
        // Ignore non Moodle branches.
        continue;
    }

    $commit = $branchinfo['commit']['sha'];
    if (isset($processed[$branchname]) && $processed[$branchname] == $commit) {
        // Ignore already processed versions.
        continue;
    }
    $phpversion = null;
    foreach ($moodleversions as $vinfo) {
        if (($branchname == 'main' && empty($vinfo['version'])) || ($branchname != 'main' && $vinfo['moodle'] == $moodle)) {
            $phpversion = (float)$vinfo['phpmin'] < 7.4 ? '7.4' : $vinfo['phpmin'];
            break;
        }
    }
    if ($phpversion === null) {
        // Most likely time to update moodleversions.json (i.e. after major release).
        // php requirements may also change, so take the max php version from the last known version.
        $vinfo = end($moodleversions);
        $phpversion = $vinfo['phpmax'];
    }
    $toprocess[] = [
        'corecommit' => $commit,
        'moodlebranch' => $branchname,
        'phpversion' => $phpversion,
    ];
}

foreach ($toprocess as $item) {
    echo json_encode($item) . "\n";
}