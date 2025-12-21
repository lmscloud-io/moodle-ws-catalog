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
 * Given the plugin, finds the minimum Moodle branch required.
 */

require_once __DIR__ . '/common.php';

// Get from arguments: plugin directory
if ($argc != 3) {
    echo "Usage: php findmoodlebranch.php <plugin-dir> <minbranch>\n";
    exit(1);
}
$plugindir = rtrim($argv[1], '/\\');
$minbranch = $argv[2];
if (!is_dir($plugindir)) {
    echo "Plugin directory does not exist.\n";
    exit(1);
}

$branch = get_required_branch($plugindir);

$minbranch = preg_replace("/^MOODLE_/", "", $minbranch);
$minbranch = preg_replace("/_STABLE$/", "", $minbranch);
if ((int)$minbranch > (int)$branch) {
    $branch = $minbranch;
}
echo "MOODLE_{$branch}_STABLE";
