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
 * Web service for system status checks
 *
 * @package    local_statuscheck (or your plugin name)
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
use external_multiple_structure;
use core\check\manager;
use core\check\result;

/**
 * External API for system status checks
 *
 * @package    local_statuscheck
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_system_status extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
                'type' => new external_value(
                        PARAM_ALPHA,
                        'Type of checks to retrieve: all, status, security, performance',
                        VALUE_DEFAULT,
                        'all'
                ),
        ]);
    }

    /**
     * Execute the system status check
     *
     * @param string $type Type of checks to retrieve
     * @return array System status information
     */
    public static function execute($type = 'all') {
        global $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
                'type' => $type,
        ]);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Require capability to view system status.
        require_capability('report/status:view', $context);

        // Get checks from manager.
        $manager = new manager();
        $checks = [];

        switch ($params['type']) {
            case 'status':
                $checks = $manager->get_checks('status');
                break;
            case 'security':
                $checks = $manager->get_checks('security');
                break;
            case 'performance':
                $checks = $manager->get_checks('performance');
                break;
            case 'all':
            default:
                // Get all types of checks.
                $checks = array_merge(
                        $manager->get_checks('status'),
                        $manager->get_checks('security'),
                        $manager->get_checks('performance')
                );
                break;
        }

        // Get excluded checks from settings.
        $excludedchecks = get_config('local_statuscheck', 'excludedchecks');
        $excludedarray = [];
        if (!empty($excludedchecks)) {
            // Config stores as comma-separated string.
            $excludedarray = explode(',', $excludedchecks);
        }

        $results = [];
        $summary = [
                'total' => 0,
                'ok' => 0,
                'warning' => 0,
                'error' => 0,
                'critical' => 0,
                'info' => 0,
                'unknown' => 0,
        ];

        foreach ($checks as $check) {
            try {
                // Skip excluded checks.
                $ref = $check->get_ref();
                if (in_array($ref, $excludedarray)) {
                    debugging('Skipping excluded check: ' . $ref, DEBUG_DEVELOPER);
                    continue;
                }

                $result = $check->get_result();
                $status = self::get_status_string($result->get_status());

                // Determine check type - some checks might not have get_type() method.
                $checktype = 'status'; // Default type.
                if (method_exists($check, 'get_type')) {
                    $checktype = $check->get_type();
                } else {
                    // Try to determine type from class namespace or other properties.
                    $classname = get_class($check);
                    if (strpos($classname, 'security') !== false) {
                        $checktype = 'security';
                    } else if (strpos($classname, 'performance') !== false) {
                        $checktype = 'performance';
                    }
                }

                // Get action link safely - method may not exist on all result objects.
                $actionlink = null;
                if (method_exists($result, 'get_action_link')) {
                    $linkobj = $result->get_action_link();
                    if ($linkobj && method_exists($linkobj, 'out')) {
                        $actionlink = $linkobj->out(false);
                    }
                }

                // Get details safely - may return null.
                $details = $result->get_details();
                if ($details === null) {
                    $details = '';
                }

                $checkdata = [
                        'id' => $check->get_ref(),
                        'name' => $check->get_name(),
                        'type' => $checktype,
                        'status' => $status,
                        'summary' => $result->get_summary(),
                        'details' => $details,
                        'component' => $check->get_component(),
                        'actionlink' => $actionlink,
                ];

                $results[] = $checkdata;
                $summary['total']++;
                $summary[$status]++;

            } catch (Exception $e) {
                // Log the error but continue processing other checks.
                debugging('Error processing check: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
        }

        return [
                'summary' => $summary,
                'checks' => $results,
                'timestamp' => time(),
                'moodleversion' => $CFG->version,
                'moodlerelease' => $CFG->release,
        ];
    }

    /**
     * Returns description of method result value
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
                'summary' => new external_single_structure([
                        'total' => new external_value(PARAM_INT, 'Total number of checks'),
                        'ok' => new external_value(PARAM_INT, 'Number of OK checks'),
                        'warning' => new external_value(PARAM_INT, 'Number of warnings'),
                        'error' => new external_value(PARAM_INT, 'Number of errors'),
                        'critical' => new external_value(PARAM_INT, 'Number of critical issues'),
                        'info' => new external_value(PARAM_INT, 'Number of info messages'),
                        'unknown' => new external_value(PARAM_INT, 'Number of unknown status'),
                ]),
                'checks' => new external_multiple_structure(
                        new external_single_structure([
                                'id' => new external_value(PARAM_TEXT, 'Check identifier'),
                                'name' => new external_value(PARAM_TEXT, 'Check name'),
                                'type' => new external_value(PARAM_ALPHA, 'Check type'),
                                'status' => new external_value(PARAM_ALPHA, 'Check status'),
                                'summary' => new external_value(PARAM_RAW, 'Check summary'),
                                'details' => new external_value(PARAM_RAW, 'Check details'),
                                'component' => new external_value(PARAM_COMPONENT, 'Component name'),
                                'actionlink' => new external_value(PARAM_URL, 'Action link', VALUE_OPTIONAL),
                        ])
                ),
                'timestamp' => new external_value(PARAM_INT, 'Timestamp of the check'),
                'moodleversion' => new external_value(PARAM_TEXT, 'Moodle version number'),
                'moodlerelease' => new external_value(PARAM_TEXT, 'Moodle release version'),
        ]);
    }

    /**
     * Convert status code to string
     *
     * @param int $status Status code
     * @return string Status as string
     */
    private static function get_status_string($status) {
        switch ($status) {
            case result::OK:
                return 'ok';
            case result::WARNING:
                return 'warning';
            case result::ERROR:
                return 'error';
            case result::CRITICAL:
                return 'critical';
            case result::INFO:
                return 'info';
            case result::UNKNOWN:
            default:
                return 'unknown';
        }
    }
}