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
 * English language strings for status check plugin
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'System Status Check';
$string['privacy:metadata'] = 'The System Status Check plugin does not store any personal data.';
$string['plugindescription'] = 'Provides web service API for retrieving system status checks.';
$string['settings'] = 'Status Check Settings';
$string['enablecaching'] = 'Enable caching';
$string['enablecaching_desc'] = 'Cache status check results for improved performance. Recommended for production environments.';
$string['cachettl'] = 'Cache time-to-live';
$string['cachettl_desc'] = 'How long to cache status check results (in seconds). Default is 300 seconds (5 minutes).';
$string['excludedchecks'] = 'Excluded checks';
$string['excludedchecks_desc'] = 'Select checks to exclude from the web service API results. Excluded checks will not appear in API responses. This is useful for hiding checks that are not relevant to your monitoring setup or that produce false positives.';

