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
 * Simplified version for quick health check (optional)
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_statuscheck\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use core\check\manager;
use core\check\result;

/**
 * Simplified health check endpoint
 *
 * @package    local_statuscheck
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_health_status extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Execute simple health check
     *
     * @return array Health status
     */
    public static function execute() {
        // Validate parameters.
        self::validate_parameters(self::execute_parameters(), []);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Require capability.
        require_capability('report/status:view', $context);

        $manager = new manager();
        $checks = array_merge(
                $manager->get_checks('status'),
                $manager->get_checks('security')
        );

        // Get excluded checks from settings.
        $excludedchecks = get_config('local_statuscheck', 'excludedchecks');
        $excludedarray = [];
        if (!empty($excludedchecks)) {
            $excludedarray = explode(',', $excludedchecks);
        }

        $hascritical = false;
        $haserror = false;
        $haswarning = false;

        foreach ($checks as $check) {
            // Skip excluded checks.
            $ref = $check->get_ref();
            if (in_array($ref, $excludedarray)) {
                continue;
            }

            $result = $check->get_result();
            $status = $result->get_status();

            if ($status === result::CRITICAL) {
                $hascritical = true;
                break;
            } else if ($status === result::ERROR) {
                $haserror = true;
            } else if ($status === result::WARNING) {
                $haswarning = true;
            }
        }

        // Determine overall health.
        if ($hascritical) {
            $health = 'critical';
            $healthy = false;
        } else if ($haserror) {
            $health = 'error';
            $healthy = false;
        } else if ($haswarning) {
            $health = 'warning';
            $healthy = true; // System is operational but needs attention.
        } else {
            $health = 'ok';
            $healthy = true;
        }

        return [
                'healthy' => $healthy,
                'status' => $health,
                'timestamp' => time(),
        ];
    }

    /**
     * Returns description of method result value
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
                'healthy' => new external_value(PARAM_BOOL, 'Overall system health status'),
                'status' => new external_value(PARAM_ALPHA, 'Status: ok, warning, error, critical'),
                'timestamp' => new external_value(PARAM_INT, 'Timestamp of the check'),
        ]);
    }
}