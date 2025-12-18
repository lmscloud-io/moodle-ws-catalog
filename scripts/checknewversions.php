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
 * For each plugin listed in pluginslist.txt checks for new versions in
 * Moodle plugins directory (moodle.org/plugins) and returns the list of versions
 * that were not yet analysed (not present in plugins/PLUGIN/processed.txt).
 */

$maindir = dirname(__DIR__);
$coredir = $maindir . '/core';
$pluginsdir = $maindir . '/plugins';
$pluginslistfile = $maindir . '/pluginslist.txt';

$minversion = 3.9;

$url = "https://download.moodle.org/api/1.3/pluglist.php";

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Curl']);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo 'Error:' . curl_error($ch);
    exit(1);
} else {
    $data = json_decode($response, true);
    if (empty($data['plugins'])) {
        echo 'Error: download.moodle.org did not return any plugins.';
        exit(1);
    }
}

$pluginlines = preg_split('/\n/', file_get_contents($pluginslistfile), -1, PREG_SPLIT_NO_EMPTY);
$plugins = array_map(function($line) {
    return explode(':', $line)[0];
}, $pluginlines);

$plugininfos = array_filter($data['plugins'], function($plugin) use ($plugins) {
    return in_array($plugin['component'], $plugins);
});

$toprocess = [];
foreach ($data['plugins'] as $plugininfo) {
    if (!in_array($plugininfo['component'], $plugins)) {
        continue;
    }
    $processedfile = $pluginsdir . '/' . $plugininfo['component'] . '/processed.txt';
    $processed = [];
    if (file_exists($processedfile)) {
        $processed = preg_split('/\n/', file_get_contents($processedfile), -1, PREG_SPLIT_NO_EMPTY);
    }

    foreach ($plugininfo['versions'] as $versioninfo) {
        if (in_array($versioninfo['version'].':'.$versioninfo['downloadmd5'], $processed)) {
            // Ignore already processed versions.
            continue;
        }
        $supportedmoodles = $versioninfo['supportedmoodles'];
        usort($supportedmoodles, function($a, $b) {
            return (float)$a['release'] - (float)$b['release'];
        });
        $supportedmin = $supportedmoodles[0]['release'];
        $supportedmax = end($supportedmoodles)['release'];
        if ((float)$supportedmax < $minversion) {
            // Ignore very old versions.
            continue;
        }
        $moodlebranch = get_moodle_branch(((float)$supportedmin < $minversion) ? "".$minversion : $supportedmin);
        $toprocess[] = [
            'plugin' => $plugininfo['component'],
            'pluginversion' => $versioninfo['version'].':'.$versioninfo['downloadmd5'],
            'downloadurl' => $versioninfo['downloadurl'],
            'moodlebranch' => $moodlebranch,
            'phpversion' => get_php_version($moodlebranch),
        ];

    }
}

foreach ($toprocess as $item) {
    echo json_encode($item) . "\n";
}

function get_moodle_branch($version) {
    if ($version == "3.9") {
        return 'MOODLE_39_STABLE';
    } elseif ($version == "3.10") {
        return 'MOODLE_310_STABLE';
    } elseif ($version == "3.11") {
        return 'MOODLE_311_STABLE';
    } else {
        $a = floor($version);
        $b = (int)(($version - $a) * 10);
        return "MOODLE_${a}0{$b}_STABLE";
    }
}

function get_php_version($branch) {
    global $maindir;
    static $versionrequirements = null;
    if ($versionrequirements === null)  {
        $versionrequirements = json_decode(file_get_contents($maindir . '/moodleversions.json'), true);
    }
    $branch = (int)preg_replace('/[^\d]/', '', $branch);
    foreach ($versionrequirements as $versioninfo) {
        if ($versioninfo['moodle'] == $branch) {
            if (in_array($versioninfo['phpmin'], ['7.2', '7.3'])) {
                // 7.4 is still supported but 7.2 and 7.3 do not work.
                return '7.4';
            }
            return $versioninfo['phpmin'];
        }
    }
    $lastversion = end($versionrequirements);
    return $lastversion['phpmin'];
}
