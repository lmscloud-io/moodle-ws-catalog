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

require_once __DIR__ . '/common.php';

// Get from arguments: plugin directory, branch, destination directory.
if ($argc != 4) {
    echo "Usage: php getdependencies.php <plugin-dir> <branch> <destination-dir>\n";
    exit(1);
}
$plugindir = rtrim($argv[1], '/\\');
$branch = $argv[2];
$destdir = rtrim($argv[3], '/\\');
$tmpdir = $destdir . '/temp';

if (!is_dir($plugindir) || !is_dir($destdir)) {
    echo "Plugin directory or destination directory do not exist.\n";
    exit(1);
}

$plugin = read_version_file($plugindir . '/version.php');
$dependencies = $plugin->dependencies ?? [];
if (empty($dependencies)) {
    echo "No dependencies found.\n";
    exit(0);
}

$plugininfo = get_plugin_info($plugin->component);
$versioninfos = array_filter($plugininfo['versions'], function($versioninfo) use ($plugin) {
    return "" . $versioninfo['version'] === "" . $plugin->version;
});
if (count($versioninfos) != 1) {
    throw new Exception('Error: download.moodle.org did not return any information on version ' . $plugin->version . " of " . $plugin->component . ".\n");
}
$versioninfo = reset($versioninfos);
$timecreated = $versioninfo['timecreated'];

foreach ($dependencies as $dependency => $minversion) {
    echo "Processing dependency: {$dependency}\n";
    $zipfilepath = $destdir . '/' . $dependency . '.zip';
    try {
        $potentialversions = get_potential_versions($dependency, $minversion, $branch, $timecreated);
        $found = null;
        foreach ($potentialversions as $version) {
            $url = $version['downloadurl'];
            file_put_contents($zipfilepath, curl_get($url));
            $extractedfolder = unzip_file($zipfilepath, $tmpdir);
            if (is_version_ok($extractedfolder, $branch)) {
                $found = $version;
                rename($extractedfolder, $destdir . '/' . $dependency);
                break;
            } else {
                remove_dir_or_file($extractedfolder);
            }
        }
        if (!$found) {
            throw new Exception('Error: No suitable version found for ' . $dependency . " with minversion={$minversion} for branch={$branch}.\n");
        }
        echo "Done ({$found['version']})\n";
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

function get_plugin_info($pluginname) {
    static $allinfo = null;
    if ($allinfo === null) {
        $allinfo = get_all_plugins_info();
    }
    $plugininfos = array_filter($allinfo, function($plugin) use ($pluginname) {
        return $plugin['component'] === $pluginname;
    });
    if (count($plugininfos) == 1) {
        return reset($plugininfos);
    }
    throw new Exception('Error: download.moodle.org did not return any information on ' . $pluginname . ".\n");
}

function get_potential_versions($pluginname, $minversion, $branch, $timecreated) {
    $plugininfo = get_plugin_info($pluginname);
    // Find version where number is >= $minversion and timecreated <= $timecreated + 7 days and supportedmoodles includes $branch.
    $matchingversions = array_filter($plugininfo['versions'], function($versioninfo) use ($minversion, $branch, $timecreated) {
        if ((float)$minversion > 0 && (float)$versioninfo['version'] < (float)$minversion) {
            return false;
        }
        if ($versioninfo['timecreated'] > $timecreated + 7 * 24 * 3600) {
            // return false;
        }
        $supportedmoodles = array_map(function($x) {
            return get_moodle_branch($x['release']);
        }, $versioninfo['supportedmoodles']);
        if (!in_array($branch, $supportedmoodles)) {
            return false;
        }
        return true;
    });
    if (count($matchingversions) == 0) {
        throw new Exception('Error: No suitable version found for ' . $pluginname . " with minversion={$minversion} for branch={$branch}.\n");
    }
    return $matchingversions;
}

function is_version_ok($dependencyfolder, $branch) {
    try {
        $dependencybranch = get_required_branch($dependencyfolder);
    } catch (Exception $e) {
        return false;
    }
    $b = preg_replace("/^MOODLE_/", "", $branch);
    $b = (int)preg_replace("/_STABLE$/", "", $b);
    return $dependencybranch <= $b;
}