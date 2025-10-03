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
* Settings for status check plugin
*
* @package    local_statuscheck
* @copyright  2025 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
$settings = new admin_settingpage('local_statuscheck', get_string('pluginname', 'local_statuscheck'));

// Add heading.
$settings->add(new admin_setting_heading(
'local_statuscheck/generalheading',
get_string('settings', 'local_statuscheck'),
get_string('plugindescription', 'local_statuscheck')
));

// Get all available checks.
$manager = new \core\check\manager();
$allchecks = array_merge(
$manager->get_checks('status'),
$manager->get_checks('security'),
$manager->get_checks('performance')
);

// Build choices array for multiselect.
$choices = [];
$checkinfo = [];

foreach ($allchecks as $check) {
$ref = $check->get_ref();
$name = $check->get_name();

// Determine type.
$type = 'status';
if (method_exists($check, 'get_type')) {
$type = $check->get_type();
} else {
$classname = get_class($check);
if (strpos($classname, 'security') !== false) {
$type = 'security';
} else if (strpos($classname, 'performance') !== false) {
$type = 'performance';
}
}

$component = $check->get_component();

// Create readable label with type and component info.
$label = $name . ' (' . $type . ' - ' . $component . ')';
$choices[$ref] = $label;

// Store for help text.
$checkinfo[$ref] = [
'name' => $name,
'type' => $type,
'component' => $component,
];
}

// Sort alphabetically by name.
asort($choices);

// Add multiselect setting for excluded checks.
$settings->add(new admin_setting_configmulticheckbox(
'local_statuscheck/excludedchecks',
get_string('excludedchecks', 'local_statuscheck'),
get_string('excludedchecks_desc', 'local_statuscheck'),
[],
$choices
));

// Add cache settings.
$settings->add(new admin_setting_configcheckbox(
'local_statuscheck/enablecaching',
get_string('enablecaching', 'local_statuscheck'),
get_string('enablecaching_desc', 'local_statuscheck'),
0
));

$settings->add(new admin_setting_configduration(
'local_statuscheck/cachettl',
get_string('cachettl', 'local_statuscheck'),
get_string('cachettl_desc', 'local_statuscheck'),
300,
60
));

$ADMIN->add('localplugins', $settings);
}
