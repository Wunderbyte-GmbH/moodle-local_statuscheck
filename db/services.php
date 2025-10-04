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
 * Web service definitions for status checks
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_statuscheck_get_system_status' => [
        'classname'     => 'local_statuscheck\external\get_system_status',
        'methodname'    => 'execute',
        'description'   => 'Get system status checks',
        'type'          => 'read',
        'capabilities'  => 'report/status:view',
        'ajax'          => true,
        'loginrequired' => true,
    ],
];

$services = [
    'Status Check Service' => [
        'functions' => ['local_statuscheck_get_system_status'],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'statuscheck',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
