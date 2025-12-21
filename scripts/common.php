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
 * Common functions communicating with moodle.org and handling version.php files.
 */

// Moodle constants that may be used in version.php files.
define('MOODLE_INTERNAL', 1);
define('MATURITY_ALPHA', 50);
define('MATURITY_BETA', 100);
define('MATURITY_RC', 150);
define('MATURITY_STABLE', 200);
define('ANY_VERSION', 'any');

$maindir = dirname(__DIR__);
$CFG = (object)[];

function get_moodle_branch($version) {
    $v = (float)$version;
    if ($version == 3.9) {
        return 'MOODLE_39_STABLE';
    } elseif ($version == 3.10) {
        return 'MOODLE_310_STABLE';
    } elseif ($version == 3.11) {
        return 'MOODLE_311_STABLE';
    } else {
        $a = floor($v);
        $b = (int)($v * 10 - $a * 10);
        return "MOODLE_${a}0{$b}_STABLE";
    }
}

function curl_get($url) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Curl']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) {
        throw new Exception("Error requesting $url: " . curl_error($ch));
    }

    return $response;
}

function get_all_plugins_info() {
    $url = "https://download.moodle.org/api/1.3/pluglist.php";
    $response = curl_get($url);
    $data = json_decode($response, true);
    if (empty($data['plugins'])) {
        echo 'Error: download.moodle.org did not return any plugins.';
        exit(1);
    }
    return $data['plugins'];
}

function read_version_file($filepath) {
    $plugin = new stdClass();
    $module = new stdClass();
    include($filepath);

    if (!empty((array)$module)) {
        throw new Exception('Error: version.php file uses $module which is not supported: ' . $filepath . "\n");
    }
    if (empty($plugin->version)) {
        throw new Exception('Error: version.php file does not define $plugin->version: ' . $filepath . "\n");
    }
    return $plugin;
}

// function get_plugin_url($pluginname, $branch) {
//     $url = "https://download.moodle.org/api/1.3/pluginfo.php?plugin=${pluginname}&format=json" .
//       "&minversion=0&branch=${branch}";

//     $response = curl_get($url);
//     $data = json_decode($response, true);
//     if (empty($data['pluginfo']['version']['downloadurl'])) {
//         throw new Exception('Error: download.moodle.org did not return any information on ' . $pluginname . ".\n");
//     }
//     return $data['pluginfo']['version']['downloadurl'];
// }

/**
 * Reads version.php file in the plugin, extract the $plugin->requires and detects Moodle branch.
 *
 * @param string $plugindir
 * @return int i.e. 39, 310, 311, 400, 401, etc.
 */
function get_required_branch($plugindir) {
    global $maindir;
    $plugin = read_version_file($plugindir . '/version.php');
    $requires = floor(((float)($plugin->requires ?? 0))/100) * 100;

    $moodleversions = json_decode(file_get_contents($maindir . '/moodleversions.json'), true);
    $lastrow = null;
    $branch = null;
    foreach ($moodleversions as $moodleversion) {
        if ($lastrow != null && (float)$requires <= (float)$moodleversion['version']) {
            $branch = $moodleversion['moodle'];
            break;
        }
        $lastrow = $moodleversion;
    }
    if ($branch === null) {
        throw new Exception("No branch found for requires={$requires}");
    }

    return $branch;
}
