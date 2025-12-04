<?php

/**
 * Fixes listings of core/plugin versions, list of plugins and function names that do not start with the plugin name.
 */

$coredir = __DIR__ . '/core';
$pluginsdir = __DIR__ . '/plugins';
$pluginslistfile = __DIR__ . '/pluginslist.txt';

# Organise core directory.
$oddfunctions = organise_dir($coredir, 'core_');

# Scan directory plugins/ and find all subdirectories.
$plugins = array_filter(scandir($pluginsdir), function ($item) use ($pluginsdir) {
    return is_dir($pluginsdir . '/' . $item) && !in_array($item, ['.', '..']);
});
sort($plugins);
$lines = [];
foreach ($plugins as $plugin) {
    $oddfunctions = organise_dir($pluginsdir . '/' . $plugin, $plugin . '_');
    $lines[] = $plugin . (empty($oddfunctions) ? '' : ':' . implode(',', $oddfunctions));
}
file_put_contents($pluginslistfile, implode("\n", $lines) . "\n");


function organise_dir($dir, $prefix) {
    # Scan directory and find all .json files in it, remove extensions, sort the list as (float).
    $files = scandir($dir);
    $files = array_filter($files, function ($file) {
        return preg_match('/\.json$/', $file);
    });
    $files = array_map(function ($file) {
        return preg_replace('/\.json$/', '', $file);
    }, $files);
    usort($files, function ($a, $b) {
        return (float)$a - (float)$b;
    });

    # If any file is the same as the previous file - remove it.
    $lastfilecontents = null;
    $remainingfiles = [];
    $functionnames = [];
    foreach ($files as $file) {
        $contents = file_get_contents($dir . '/' . $file . '.json');
        if ($contents !== $lastfilecontents) {
            $remainingfiles[] = $file;
            $lastfilecontents = $contents;
            $json = json_decode($contents, true);
            $thisfunctionnames = array_map(function ($func) {
                return $func['name'];
            }, $json['functions']);
            $functionnames = array_merge($functionnames, $thisfunctionnames);
        } else {
            unlink($dir . '/' . $file . '.json');
        }
    }

    # Output the list of remaining files (without extensions) to versions.txt.
    file_put_contents($dir . '/versions.txt', implode("\n", $remainingfiles) . "\n");
    $functionnames = array_unique($functionnames);
    sort($functionnames);
    return array_filter($functionnames, function($name) use ($prefix) {
        return strpos($name, $prefix) !== 0;
    });
}