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
 * Reads version.php file in the plugin, extract the list of dependencies,
 * downloads them from moodle.org/plugins and unpacks to the specified directory.
 */

// Moodle constants that may be used in version.php files.
define('MOODLE_INTERNAL', 1);
define('MATURITY_ALPHA', 50);
define('MATURITY_BETA', 100);
define('MATURITY_RC', 150);
define('MATURITY_STABLE', 200);
define('ANY_VERSION', 'any');

// Get from arguments: plugin directory, branch, destination directory.
if ($argc != 4) {
    echo "Usage: php getdependencies.php <plugin-dir> <branch> <destination-dir>\n";
    exit(1);
}
$plugindir = rtrim($argv[1], '/\\');
$branch = $argv[2];
$branch = preg_replace("/^MOODLE_/", "", $branch);
$branch = preg_replace("/_STABLE$/", "", $branch);
$destdir = rtrim($argv[3], '/\\');
$tmpdir = $destdir . '/temp';

if (!is_dir($plugindir) || !is_dir($destdir)) {
    echo "Plugin directory or destination directory do not exist.\n";
    exit(1);
}

$plugin = read_version_file($plugindir . '/version.php');
$dependencies = array_keys($plugin->dependencies ?? []);
foreach ($dependencies as $dependency) {
    echo "Processing dependency: {$dependency}\n";
    $zipfilepath = $destdir . '/' . $dependency . '.zip';
    try {
        $url = get_plugin_url($dependency, $branch);
        file_put_contents($zipfilepath, curl_get($url));
        $extractedfolder = unzip_file($zipfilepath, $tmpdir);
        rename($extractedfolder, $destdir . '/' . $dependency);
        echo "Done\n";
    } catch (Exception $e) {
        echo $e->getMessage() . "\n";
    }
    remove_dir_or_file($tmpdir);
    remove_dir_or_file($zipfilepath);
}

function remove_dir_or_file($dir) {
    if (!is_dir($dir)) {
        if (is_file($dir)) {
            unlink($dir);
        }
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $filepath = $dir . '/' . $file;
        if (is_dir($filepath)) {
            remove_dir_or_file($filepath);
        } else {
            unlink($filepath);
        }
    }
    rmdir($dir);
}

/**
 * Unzips archive, checks that there is only 1 folder inside and returns the full path to this folder
 *
 * @param string $zipfilepath Path to the zip file
 * @param string $extractpath Path where it should be extracted to
 * @throws Exception
 * @return string Path to the extracted subfolder
 */
function unzip_file($zipfilepath, $extractpath) {
    remove_dir_or_file($extractpath);
    $zip = new ZipArchive;
    $res = $zip->open($zipfilepath);
    if ($res === true) {
        $zip->extractTo($extractpath);
        $zip->close();
    } else {
        throw new Exception('Error: Could not unzip file ' . $zipfilepath . "\n");
    }

    $files = array_diff(scandir($extractpath), ['.', '..']);
    if (count($files) == 1) {
        return $extractpath . '/' . reset($files);
    } else {
        throw new Exception("Error: Unexpected content in the zip file, found " . count($files) . " files, expected 1.\n");
    }
}

function read_version_file($filepath) {
    $plugin = new stdClass();
    include($filepath);
    return $plugin;
}

function curl_get($url) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Curl']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    if (curl_errno($ch)) {
        throw new Exception('Error:' . curl_error($ch));
    }

    return $response;
}

function get_plugin_url($pluginname, $branch) {
    $url = "https://download.moodle.org/api/1.3/pluginfo.php?plugin=${pluginname}&format=json" .
      "&minversion=0&branch=${branch}";

    $response = curl_get($url);
    $data = json_decode($response, true);
    if (empty($data['pluginfo']['version']['downloadurl'])) {
        throw new Exception('Error: download.moodle.org did not return any information on ' . $pluginname . ".\n");
    }
    return $data['pluginfo']['version']['downloadurl'];
}