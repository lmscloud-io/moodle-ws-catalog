<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Generates a JSON file with descriptions of available web services
 *
 * @package   core
 * @copyright 2025 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

$rawoptions = $_SERVER['argv'] ?? [];
// Remove anything after '--', options can not be there.
if (($key = array_search('--', $rawoptions)) !== false) {
    $rawoptions = array_slice($rawoptions, 0, $key);
}
array_shift($rawoptions);
if (!$rawoptions || $rawoptions[0] == "--help" || $rawoptions[0] == "-h") {
    echo "Usage:\n";
    echo "    /usr/bin/php buildws.php --plugin=PLUGINNAME /path/to/moodle\n";
    echo "\n";
    echo "Where PLUGINNAME is either 'core' or a full name of the plugin.\n";
    exit(0);
}
$pathtomoodle = $rawoptions[count($rawoptions) - 1];
if (preg_match('/^\\-/', $pathtomoodle) || !file_exists($pathtomoodle) || !is_dir($pathtomoodle)) {
    echo "Unrecognized command format. Run --help for usage.\n";
    exit(1);
}
$pathtomoodle = rtrim($pathtomoodle, "/\\");
if (!file_exists($pathtomoodle . "/config.php")) {
    echo "Moodle not found at $pathtomoodle.\n";
    exit(1);
}

require($pathtomoodle . '/config.php');
require_once("$CFG->libdir/clilib.php");
require_once($CFG->dirroot.'/webservice/lib.php');
require_once($CFG->dirroot.'/lib/externallib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'plugin' => '',
    ],
    ['h' => 'help']
);

$plugin = $options['plugin'];
if ($plugin == '') {
    cli_error("Argument --plugin is required.");
}
$plugin = core_component::normalize_componentname($plugin);

if (class_exists('\core\plugin_manager')) {
    $standardplugins = \core\plugin_manager::instance()->get_standard_plugins();
} else {
    $standardplugins = [];
    foreach (core_plugin_manager::instance()->get_plugin_types() as $type => $unused) {
        $list = core_plugin_manager::standard_plugins_list($type) ?: [];
        foreach ($list as $pluginname) {
            $standardplugins[] = $type . '_' . $pluginname;
        }
    }
}

if ($plugin === 'core') {
    $pluginversion = _buildws_get_core_version();
} else if (!preg_match('/^core_/', $plugin) && ($dir = core_component::get_component_directory($plugin))) {
    if (in_array($plugin, $standardplugins)) {
        cli_error("Plugin '$plugin' is a standard plugin, use --plugin=core instead.");
    }
    $pluginversion = _buildws_get_component_version($dir);
} else {
    cli_error("Plugin '$plugin' not found.");
}

$wsmanager = new \webservice();

$functions = $DB->get_records('external_functions',
    $plugin == 'core' ? [] : ['component' => $plugin],
    'component, name');
$tools = [];

$allfunctions = [];
foreach ($functions as $record) {
    if ($plugin == 'core' && $record->component != 'moodle' && !in_array($record->component, $standardplugins)) {
        continue;
    }
    $function = \external_api::external_function_info($record);
    $info = [];
    foreach (_buildws_convert($function) as $key => $value) {
        if (!preg_match('/_method$/', $key) && !in_array($key, ['classpath', 'classname', 'methodname', 'id'])) {
            $info[$key] = $value;
        }
    }
    $allfunctions[] = $info;
}
$result = json_encode([
    'component' => $plugin,
    'version' => $pluginversion,
    'functions' => $allfunctions,
], JSON_PRETTY_PRINT) . "\n";

if (!is_dir(__DIR__."/core") || !is_dir(__DIR__."/plugins")) {
    cli_error("Current directory '{".__DIR__."}' is not a moodle-ws directory.");
}
$file = __DIR__ . "/" .
    ($plugin == "core" ? "core" : "plugins/$plugin") .
    "/{$pluginversion}.json";
$dir = dirname($file);
[$lastversion, $nextversion] = _buildws_get_last_version($dir, $pluginversion);
if ($lastversion && file_get_contents($lastversion) == $result) {
    echo "List of web services is the same as in the existing file $lastversion . Exiting.\n";
    exit(0);
} else if ($nextversion && file_get_contents($nextversion) == $result) {
    echo "List of web services is the same as in the existing file $nextversion . Updating the version list.\n";
    file_put_contents($file, $result);
    unlink($nextversion);
} else {
    file_put_contents($file, $result);
}

// Re-build versions file.
$availableversions = _buildws_get_versions_list($dir);
file_put_contents($dir.'/versions.txt', implode("\n", $availableversions)."\n");

function _buildws_convert($value, $key = null, $parent = null) {
    if ($value instanceof external_description || is_array($value) || is_object($value)) {
        $rv = [];
        if ($value instanceof external_description) {
            $classparts = preg_split('/\\\\/', get_class($value));
            $rv['class'] = $classparts[count($classparts) - 1];
        }
        foreach ($value as $k => $v) {
            $rv[$k] = _buildws_convert($v, $k, $value);
        }
        return $rv;
    } else {
        if ($parent instanceof external_value) {
            if ($parent->type == PARAM_INT && $value >= time() - 1 && $value <= time() && $key == "default") {
                // Avoid including current time as default value, it messes up the diff.
                return 0;
            }
        }
        return $value;
    }
}

/**
 * Retrieves and returns plugin version from the 'version.php' file
 *
 * @param string $plugindir
 * @return string
 */
function _buildws_get_component_version($plugindir) {
    // Same code as in core_webservice_get_site_info web service.
    $versionpath = $plugindir . '/version.php';
    if (!is_readable($versionpath)) {
        cli_error('Can not read version.php file for the plugin.');
    }
    $plugin = new \stdClass();
    include($versionpath);
    return "" . $plugin->version;
}

function _buildws_get_core_version() {
    global $CFG;
    $maturity = null;
    $version = null;
    if (file_exists("$CFG->dirroot/public/version.php")) {
        require("$CFG->dirroot/public/version.php");
    } else {
        require("$CFG->dirroot/version.php");
    }
    if ($maturity == MATURITY_STABLE) {
        return floor($version / 100) * 100;
    } else {
        return sprintf("%.2f", $version);
    }
}

function _buildws_get_last_version($dir, $curversion) {
    if (!file_exists($dir . '/versions.txt')) {
        mkdir($dir);
        file_put_contents($dir . '/versions.txt', '');
        return [null, null];
    }
    $versions = _buildws_get_versions_list($dir);
    $lastversion = null;
    $nextversion = null;
    foreach ($versions as $version) {
        if ((float)$version > (float)$curversion) {
            $nextversion = $version;
            break;
        }
        $lastversion = $version;
    }
    return [
        $lastversion ? $dir . '/' . $lastversion . '.json' : null,
        $nextversion ? $dir . '/' . $nextversion . '.json' : null,
    ];
}

function _buildws_get_versions_list($dir) {
    $availableversions = scandir($dir);
    $availableversions = array_filter($availableversions, function ($version) {
        return preg_match('/\\.json$/', $version);
    });
    $availableversions = array_map(function ($version) {
        return preg_replace('/\\.json$/', '', $version);
    }, $availableversions);
    usort($availableversions, function($a, $b) {
        return (float)$a - (float)$b;
    });
    return $availableversions;
}
