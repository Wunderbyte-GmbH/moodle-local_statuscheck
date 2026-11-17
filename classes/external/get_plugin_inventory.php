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
            // Full inventory. By default this returns the Moodle plugin overview's
            // "Additional plugins" set: every non-standard plugin, with subplugins
            // nested inside their parent plugin. includestandard=1 returns every
            // plugin (core included) in the same nested shape.
            $nested = [];

            // Pass 1: top-level (non-subplugin) plugins, each with its subplugins nested.
            foreach ($pluginman->get_plugins() as $pluginsbytype) {
                foreach ($pluginsbytype as $info) {
                    if ($info->is_subplugin()) {
                        continue;
                    }
                    if (!$includestandard && $info->is_standard()) {
                        continue;
                    }
                    $entry = self::build_entry($info->component, $info, $pluginman);
                    $entry['subplugins'] = self::build_subplugins(
                        $info->component, $pluginman, $includestandard, $nested);
                    $results[] = $entry;
                }
            }

            // Pass 2: any shown subplugin whose parent was not listed (e.g. a
            // third-party subplugin of a core plugin) is returned at the top level
            // so nothing in the "Additional plugins" set is lost.
            foreach ($pluginman->get_plugins() as $pluginsbytype) {
                foreach ($pluginsbytype as $info) {
                    if (!$info->is_subplugin()) {
                        continue;
                    }
                    if (!$includestandard && $info->is_standard()) {
                        continue;
                    }
                    if (isset($nested[$info->component])) {
                        continue;
                    }
                    $entry = self::build_entry($info->component, $info, $pluginman);
                    $entry['subplugins'] = [];
                    $results[] = $entry;
                }
            }
        } else {
            // Specific components (deduplicated, order preserved). A named component
            // is always returned regardless of whether it is standard, with its own
            // subplugins nested.
            foreach (array_values(array_unique($requested)) as $component) {
                $info = $pluginman->get_plugin_info($component);
                $entry = self::build_entry($component, $info, $pluginman);
                $ignored = [];
                $entry['subplugins'] = ($info !== null)
                    ? self::build_subplugins($component, $pluginman, true, $ignored)
                    : [];
                $results[] = $entry;
            }
        }

        return ['plugins' => $results];
    }

    /**
     * Build the nested subplugin entries for a parent plugin.
     *
     * @param string $component Parent plugin component.
     * @param core_plugin_manager $pluginman Plugin manager instance.
     * @param bool $includestandard Whether to include standard subplugins.
     * @param array $seen Collects component => true for every subplugin returned.
     * @return array List of inventory entries (base fields only).
     */
    private static function build_subplugins($component, core_plugin_manager $pluginman, $includestandard, array &$seen) {
        $subs = [];
        foreach ($pluginman->get_subplugins_of_plugin($component) as $subinfo) {
            if (!$includestandard && $subinfo->is_standard()) {
                continue;
            }
            $subs[] = self::build_entry($subinfo->component, $subinfo, $pluginman);
            $seen[$subinfo->component] = true;
        }
        return $subs;
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
        // Subplugin = belongs to a parent plugin (e.g. assignsubmission_* of mod_assign).
        $issubplugin = ($info !== null) ? (bool) $info->is_subplugin() : false;

        if ($recordedversion === null && $versiondisk === null) {
            // Valid component name but nothing installed for it.
            return [
                'component' => $component,
                'displayname' => '',
                'version' => 0,
                'status' => self::STATUS_NOT_INSTALLED,
                'installation_source' => self::SOURCE_ZIP,
                'git_branch' => '',
                'git_remote' => '',
                'is_standard' => $isstandard,
                'is_subplugin' => $issubplugin,
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
                'git_remote' => '',
                'is_standard' => $isstandard,
                'is_subplugin' => $issubplugin,
            ];
        }

        $rootdir = (string) $info->rootdir;
        $source = self::detect_installation_source($rootdir);
        $isgit = ($source === self::SOURCE_GIT);
        $branch = $isgit ? self::detect_git_branch($rootdir) : '';
        $remote = $isgit ? self::detect_git_remote($rootdir) : '';

        return [
            'component' => $component,
            'displayname' => $displayname,
            'version' => $recordedversion !== null ? $recordedversion : $versiondisk,
            'status' => self::STATUS_INSTALLED,
            'installation_source' => $source,
            'git_branch' => $branch,
            'git_remote' => $remote,
            'is_standard' => $isstandard,
            'is_subplugin' => $issubplugin,
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
        $gitdir = self::resolve_git_dir($rootdir);
        if ($gitdir === '') {
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
     * Read the "origin" (or first) Git remote URL of a plugin directory.
     *
     * Reads only .git/config locally (following a submodule's gitdir pointer). No
     * shell commands, no remote requests. Any embedded credentials in the URL
     * (scheme://user:secret@host/...) are stripped before returning, so tokens
     * and passwords never reach the response.
     *
     * @param string $rootdir Absolute plugin directory resolved via the plugin manager.
     * @return string Sanitised remote URL, or '' when not determinable.
     */
    private static function detect_git_remote($rootdir) {
        $gitdir = self::resolve_git_dir($rootdir);
        if ($gitdir === '') {
            return '';
        }

        $configfile = $gitdir . DIRECTORY_SEPARATOR . 'config';
        if (!is_file($configfile) || !is_readable($configfile)) {
            return '';
        }

        $raw = (string) file_get_contents($configfile, false, null, 0, 65536);

        // Prefer the "origin" remote; otherwise fall back to the first remote.
        $url = '';
        if (preg_match('/\[remote\s+"origin"\](.*?)(?=\n\[|\z)/s', $raw, $block)
                && preg_match('/^\s*url\s*=\s*(.+)$/m', $block[1], $m)) {
            $url = trim($m[1]);
        } else if (preg_match('/\[remote\s+"[^"]+"\](.*?)(?=\n\[|\z)/s', $raw, $block)
                && preg_match('/^\s*url\s*=\s*(.+)$/m', $block[1], $m)) {
            $url = trim($m[1]);
        }

        return self::sanitise_git_url($url);
    }

    /**
     * Strip any embedded credentials from a Git remote URL.
     *
     * Removes the userinfo component of a scheme URL (https://user:token@host/...
     * becomes https://host/...). SSH short syntax (git@host:org/repo.git) carries
     * no secret and is left intact.
     *
     * @param string $url Raw remote URL.
     * @return string URL without credentials.
     */
    private static function sanitise_git_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        return preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/@]*@#i', '$1', $url);
    }

    /**
     * Resolve the real Git directory for a plugin directory.
     *
     * Handles an own checkout (.git directory) and a submodule (.git file with a
     * "gitdir:" pointer). Local filesystem reads only.
     *
     * @param string $rootdir Absolute plugin directory resolved via the plugin manager.
     * @return string Absolute path to the git directory, or '' when not a checkout.
     */
    private static function resolve_git_dir($rootdir) {
        $rootdir = (string) $rootdir;
        if ($rootdir === '' || !is_dir($rootdir)) {
            return '';
        }

        $gitpath = $rootdir . DIRECTORY_SEPARATOR . '.git';

        if (is_dir($gitpath)) {
            // Own checkout: .git is the git directory.
            return $gitpath;
        }

        if (is_file($gitpath) && is_readable($gitpath)) {
            // Submodule: .git is a file pointing at the real git directory.
            $raw = (string) file_get_contents($gitpath, false, null, 0, 4096);
            if (preg_match('/^gitdir:\s*(.+)$/m', $raw, $m)) {
                $target = trim($m[1]);
                if (!self::is_absolute_path($target)) {
                    $target = $rootdir . DIRECTORY_SEPARATOR . $target;
                }
                $resolved = realpath($target);
                if ($resolved !== false && is_dir($resolved)) {
                    return $resolved;
                }
            }
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
     * The common inventory fields shared by top-level plugins and subplugins.
     *
     * @return array field name => external_value
     */
    private static function entry_fields() {
        return [
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
            'git_remote' => new external_value(
                PARAM_RAW_TRIMMED,
                'Git remote URL (credentials stripped) when installation_source is git; empty otherwise'
            ),
            'is_standard' => new external_value(
                PARAM_BOOL,
                'True if the plugin ships with core Moodle (standard); false if additional/third-party'
            ),
            'is_subplugin' => new external_value(
                PARAM_BOOL,
                'True if the plugin is a subplugin of a parent plugin'
            ),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        $toplevel = self::entry_fields();
        $toplevel['subplugins'] = new external_multiple_structure(
            new external_single_structure(self::entry_fields()),
            'Subplugins belonging to this plugin (empty when it has none)'
        );

        return new external_single_structure([
            'plugins' => new external_multiple_structure(
                new external_single_structure($toplevel)
            ),
        ]);
    }
}
