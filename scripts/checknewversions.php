<?php

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

echo json_encode($plugininfos, JSON_PRETTY_PRINT);

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
        $toprocess[] = [
            'plugin' => $plugininfo['component'],
            'version' => $versioninfo['version'],
            'branch' => get_moodle_branch(((float)$supportedmin < $minversion) ? "".$minversion : $supportedmin),
            'downloadurl' => $versioninfo['downloadurl'],
            'downloadmd5' => $versioninfo['downloadmd5'],
        ];
    }
}

echo json_encode($toprocess, JSON_PRETTY_PRINT);

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