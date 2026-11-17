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
 * Read-only web service that reports an inventory of installed plugins.
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_statuscheck\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_plugin_manager;

/**
 * External API: plugin inventory (component, display name, version, source).
 *
 * The scope is plugin inventory only: it never runs shell commands, never
 * performs remote Git requests, and never exposes absolute paths, Git remote
 * URLs, configuration values or credentials.
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_plugin_inventory extends external_api {

    /** @var string Plugin code present on disk and registered in the database. */
    const STATUS_INSTALLED = 'installed';

    /** @var string A valid component that is not installed on this site. */
    const STATUS_NOT_INSTALLED = 'not_installed';

    /** @var string Recorded in the database but the code is missing from disk. */
    const STATUS_MISSING_FROM_DISK = 'missing_from_disk';

    /** @var string Deployed as its own Git checkout or a Git submodule. */
    const SOURCE_GIT = 'git';

    /** @var string Anything not tracked by Git: installed as a package / ZIP (the default). */
    const SOURCE_ZIP = 'zip';

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            // NOTE: both parameters use VALUE_DEFAULT so they are always present,
            // in a fixed order, when Moodle dispatches the (positional) call. A
            // VALUE_OPTIONAL parameter that the caller omits is dropped from the
            // validated set and would shift the remaining arguments.
            'plugins' => new external_multiple_structure(
                new external_value(PARAM_COMPONENT, 'Moodle component name, e.g. mod_booking or auth_saml2'),
                'Optional list of components to look up. Omit (or pass an empty list) for the full inventory.',
                VALUE_DEFAULT,
                []
            ),
            'includestandard' => new external_value(
                PARAM_BOOL,
                'Only affects the full inventory (when no plugins list is given): set to 1 to also include '
                    . 'Moodle standard (core-shipped) plugins. Defaults to 0, returning only the additional '
                    . '(third-party) plugins, matching the "Additional plugins" overview. Ignored when a '
                    . 'plugins list is supplied.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Return the plugin inventory.
     *
     * @param array|null $plugins Optional list of component names. Null means "all installed plugins".
     * @return array Inventory result.
     */
    public static function execute($plugins = [], $includestandard = false) {
        // Validate parameters. Both are always passed (they use VALUE_DEFAULT), so
        // the positional dispatch order stays stable.
        $params = self::validate_parameters(self::execute_parameters(), [
            'plugins' => $plugins,
            'includestandard' => $includestandard,
        ]);

        // Validate context and enforce the capability (standard token auth still applies).
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('report/status:view', $context);

        $requested = $params['plugins'];
        $includestandard = !empty($params['includestandard']);

        $pluginman = core_plugin_manager::instance();
        $results = [];

        if (empty($requested)) {
            // Full inventory. By default only additional (non-standard) plugins are
            // returned, matching the "Additional plugins" section of the Moodle
            // plugin overview; Moodle standard/core-shipped plugins are included
            // only when the caller sets includestandard.
            foreach ($pluginman->get_plugins() as $pluginsbytype) {
                foreach ($pluginsbytype as $info) {
                    if (!$includestandard && $info->is_standard()) {
                        continue;
                    }
                    $results[] = self::build_entry($info->component, $info, $pluginman);
                }
            }
        } else {
            // Specific components (deduplicated, order preserved). A named component
            // is always returned regardless of whether it is standard.
            foreach (array_values(array_unique($requested)) as $component) {
                $info = $pluginman->get_plugin_info($component);
                $results[] = self::build_entry($component, $info, $pluginman);
            }
        }

        return ['plugins' => $results];
    }

    /**
     * Build a single inventory entry for a component.
     *
     * @param string $component Component name.
     * @param \core\plugininfo\base|null $info Plugin manager info, or null if not resolvable.
     * @param core_plugin_manager $pluginman Plugin manager instance.
     * @return array Inventory entry limited to inventory fields.
     */
    private static function build_entry($component, $info, core_plugin_manager $pluginman) {
        // Version recorded in the database (what Moodle considers installed).
        $recorded = get_config($component, 'version');
        $recordedversion = ($recorded !== false && $recorded !== null && $recorded !== '') ? (int) $recorded : null;

        // Version present in the code on disk, if any.
        $versiondisk = ($info !== null && $info->versiondisk !== null) ? (int) $info->versiondisk : null;
        if ($recordedversion === null && $info !== null && $info->versiondb !== null) {
            $recordedversion = (int) $info->versiondb;
        }

        // Standard = shipped with core Moodle; additional = third-party / installed.
        $isstandard = ($info !== null) ? (bool) $info->is_standard() : false;

        if ($recordedversion === null && $versiondisk === null) {
            // Valid component name but nothing installed for it.
            return [
                'component' => $component,
                'displayname' => '',
                'version' => 0,
                'status' => self::STATUS_NOT_INSTALLED,
                'installation_source' => self::SOURCE_ZIP,
                'git_branch' => '',
                'is_standard' => $isstandard,
            ];
        }

        $displayname = ($info !== null && (string) $info->displayname !== '') ? $info->displayname : $component;

        if ($versiondisk === null) {
            // Recorded as installed in the database, but the code is gone.
            return [
                'component' => $component,
                'displayname' => $displayname,
                'version' => $recordedversion !== null ? $recordedversion : 0,
                'status' => self::STATUS_MISSING_FROM_DISK,
                'installation_source' => self::SOURCE_ZIP,
                'git_branch' => '',
                'is_standard' => $isstandard,
            ];
        }

        $rootdir = (string) $info->rootdir;
        $source = self::detect_installation_source($rootdir);
        $branch = ($source === self::SOURCE_GIT) ? self::detect_git_branch($rootdir) : '';

        return [
            'component' => $component,
            'displayname' => $displayname,
            'version' => $recordedversion !== null ? $recordedversion : $versiondisk,
            'status' => self::STATUS_INSTALLED,
            'installation_source' => $source,
            'git_branch' => $branch,
            'is_standard' => $isstandard,
        ];
    }

    /**
     * Determine the installation source of a plugin using local evidence only.
     *
     * Rules:
     * - 'git' when the plugin directory is its own Git checkout (a .git directory)
     *         or a Git submodule (a .git file). The enclosing Moodle repository
     *         alone is not evidence, because its .git lives at the Moodle root, not
     *         inside the plugin directory.
     * - 'zip' for everything else: code present on disk but not tracked by Git
     *         (installed as a package / ZIP). This is also the default when the
     *         directory cannot be inspected.
     *
     * No shell commands are executed and no remote Git requests are made; only
     * local filesystem metadata resolved through the plugin manager is read.
     *
     * @param string $rootdir Absolute plugin directory resolved via the plugin manager.
     * @return string SOURCE_GIT or SOURCE_ZIP.
     */
    private static function detect_installation_source($rootdir) {
        $rootdir = (string) $rootdir;

        // Own Git checkout (.git dir) or Git submodule (.git file) in the plugin dir.
        if ($rootdir !== '' && is_dir($rootdir) && file_exists($rootdir . DIRECTORY_SEPARATOR . '.git')) {
            return self::SOURCE_GIT;
        }

        // Not under Git: treat as a package/ZIP installation.
        return self::SOURCE_ZIP;
    }

    /**
     * Read the checked-out Git branch of a plugin directory from local metadata.
     *
     * Reads only .git/HEAD (following a submodule's .git-file gitdir pointer).
     * No shell commands, no remote requests. Returns the branch name only — never
     * a path, remote URL or commit hash. Empty when the branch cannot be
     * determined (e.g. a detached HEAD) or when the directory is not a Git checkout.
     *
     * @param string $rootdir Absolute plugin directory resolved via the plugin manager.
     * @return string Branch name, or '' when not determinable.
     */
    private static function detect_git_branch($rootdir) {
        $rootdir = (string) $rootdir;
        if ($rootdir === '' || !is_dir($rootdir)) {
            return '';
        }

        $gitpath = $rootdir . DIRECTORY_SEPARATOR . '.git';
        $gitdir = '';

        if (is_dir($gitpath)) {
            // Own checkout: .git is the git directory.
            $gitdir = $gitpath;
        } else if (is_file($gitpath) && is_readable($gitpath)) {
            // Submodule: .git is a file pointing at the real git directory.
            $raw = (string) file_get_contents($gitpath, false, null, 0, 4096);
            if (preg_match('/^gitdir:\s*(.+)$/m', $raw, $m)) {
                $target = trim($m[1]);
                // Resolve a relative gitdir against the plugin directory.
                if (!self::is_absolute_path($target)) {
                    $target = $rootdir . DIRECTORY_SEPARATOR . $target;
                }
                $resolved = realpath($target);
                $gitdir = ($resolved !== false) ? $resolved : '';
            }
        }

        if ($gitdir === '' || !is_dir($gitdir)) {
            return '';
        }

        $head = $gitdir . DIRECTORY_SEPARATOR . 'HEAD';
        if (!is_file($head) || !is_readable($head)) {
            return '';
        }

        $content = trim((string) file_get_contents($head, false, null, 0, 4096));
        // "ref: refs/heads/<branch>" for an attached HEAD; a bare hash means detached.
        if (preg_match('#^ref:\s*refs/heads/(.+)$#', $content, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Whether a filesystem path is absolute (POSIX or Windows drive/UNC).
     *
     * @param string $path Path to test.
     * @return bool
     */
    private static function is_absolute_path($path) {
        return $path !== '' && (
            $path[0] === '/'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || strncmp($path, '\\\\', 2) === 0
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'plugins' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(PARAM_COMPONENT, 'Moodle component name'),
                    'displayname' => new external_value(PARAM_TEXT, 'Human-readable plugin name ("" if not installed)'),
                    'version' => new external_value(PARAM_INT, 'Installed version (0 if not installed)'),
                    'status' => new external_value(
                        PARAM_ALPHAEXT,
                        'One of: installed, not_installed, missing_from_disk'
                    ),
                    'installation_source' => new external_value(
                        PARAM_ALPHA,
                        'One of: git, zip'
                    ),
                    'git_branch' => new external_value(
                        PARAM_TEXT,
                        'Checked-out Git branch when installation_source is git; empty otherwise'
                    ),
                    'is_standard' => new external_value(
                        PARAM_BOOL,
                        'True if the plugin ships with core Moodle (standard); false if additional/third-party'
                    ),
                ])
            ),
        ]);
    }
}
