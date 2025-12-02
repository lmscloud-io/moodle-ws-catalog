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

$curdir = $_SERVER['PWD'] ?: __DIR__;

require($curdir . '/config.php');
require_once("$CFG->libdir/clilib.php");
require_once($CFG->dirroot.'/webservice/lib.php');
require_once($CFG->dirroot.'/lib/externallib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'plugin' => '',
        'outputdir' => '',
    ],
    ['h' => 'help']
);

// TODO display help.

$plugin = $options['plugin'];
if ($plugin == '') {
    cli_error("Argument --plugin is required.");
}
$plugin = core_component::normalize_componentname($plugin);
$standardplugins = \core\plugin_manager::instance()->get_standard_plugins();
if ($plugin === 'core') {
    $pluginversion = "". floor($CFG->version);
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

if ($options['outputdir']) {
    if (!is_dir($options['outputdir'])) {
        cli_error("Output directory '{$options['outputdir']}' does not exist.");
    }
    $file = $options['outputdir'] . "/" .
        ($plugin == "core" ? "core" : "plugins/$plugin") .
        "/{$pluginversion}.json";
    $dir = dirname($file);
    if (!file_exists($dir)) {
        mkdir($dir);
    }
    file_put_contents($file, $result);
} else {
    echo $result;
}

function _buildws_convert($value) {
    if ($value instanceof external_description || is_array($value) || is_object($value)) {
        $rv = [];
        if ($value instanceof external_description) {
            $classparts = preg_split('/\\\\/', get_class($value));
            $rv['class'] = $classparts[count($classparts) - 1];
        }
        foreach ($value as $k => $v) {
            $rv[$k] = _buildws_convert($v);
        }
        return $rv;
    } else {
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
