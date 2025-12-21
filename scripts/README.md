# Scripts for the Moodle WS Catalog

These scripts used in the github workflows.

## fixlistings.php

Fixes listings of core/plugin versions, list of plugins and function names that do not start with the plugin name.
Removes version files that are identical to the previous version.

````
php scripts/fixlistings.php
```

## processjson.php

Reads content generated with `admin/tool/wsdiscovery/cli/generate_json.php --group-by-component`
and splits it into separate files in relevant core/ and plugins/ directories.


```
# ... Check out Moodle code into _moodle directory, add tool_wsdiscovery and necessary plugins
pushd _moodle
[ -d "public" ] && PUBLIC="public/" || PUBLIC=""
php ${PUBLIC}admin/tool/wsdiscovery/cli/generate_json.php --include=addons --group-by-component > ./temp.json || exit 1
popd
php scripts/processjson.php < _moodle/temp.json
rm _moodle/temp.json
```

## checknewversions.php

For each plugin listed in pluginslist.txt checks for new versions in
Moodle plugins directory (moodle.org/plugins) and returns the list of versions
that were not yet analysed (not present in plugins/PLUGIN/processed.txt).

Each line of the output is a JSON object similar to this:

```
{"plugin":"tool_forcedcache","pluginversion":"2024093000:0e304e52cd0c6c1ba8edb7e4a06d8274","downloadurl":"https:\/\/moodle.org\/plugins\/download.php\/33252\/tool_forcedcache_moodle44_2024093000.zip","moodlebranch":"MOODLE_404_STABLE","phpversion":"8.1"}
```

This script is executed in the `.github/workflows/check_plugin_updates.yml` workflow on a schedule and for each
line in the output a new workflow `.github/workflows/process_plugin_version.yml` is triggered to process the new version.

## findmoodlebranch.php

Given the plugin directory, analyses version.php and finds the minimum Moodle branch required.

Unforutnately, the "supportedmoodles" information in the plugins directory is not reliable enough, however
the branch indicated in plugins directory is used as a minimum bound.

Usage: `php findmoodlebranch.php <plugin-dir> <minbranch>`

## getdependencies.php

Reads version.php file in the plugin, extract the list of dependencies,
downloads them from moodle.org/plugins and unpacks to the specified directory.

Usage: `php getdependencies.php <plugin-dir> <branch> <destination-dir>`
