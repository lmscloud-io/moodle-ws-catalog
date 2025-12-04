<?php

/**
 * Reads content generated with admin/tool/wsdiscovery/cli/generate_json.php --group-by-component
 * and splits it into separate files in relevant core/ and plugins/ directories.
 */

$maindir = dirname(__DIR__);
$coredir = $maindir . '/core';
$pluginsdir = $maindir . '/plugins';

if (!is_dir($coredir) || !is_dir($pluginsdir)) {
    echo "Can not find the json files location.\n";
    exit(1);
}

# Read file from the stdin
$input = file_get_contents('php://stdin');
$json = json_decode($input, true);
foreach ($json['components'] as $componentname => $component) {
    $version = $component['version'];
    if ($componentname === "moodle") {
        $dir = $coredir;
        $file = $dir . '/' . sprintf("%.2f", $version) . '.json';
    } else {
        $dir = $pluginsdir . '/' . $componentname;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/' . sprintf("%d", $version) . '.json';
    }
    file_put_contents($file, json_encode(['functions' => $component['functions']]));
}
