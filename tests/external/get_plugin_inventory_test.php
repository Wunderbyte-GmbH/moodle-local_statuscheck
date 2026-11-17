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
 * Tests for the plugin inventory web service function.
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_statuscheck\external;

use advanced_testcase;
use invalid_parameter_exception;
use required_capability_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for {@see get_plugin_inventory}.
 *
 * @package    local_statuscheck
 * @copyright  2025 David Bogner, Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_plugin_inventory::class)]
final class get_plugin_inventory_test extends advanced_testcase {

    /** @var string A component whose frankenstyle name is valid but is never installed. */
    const ABSENT_COMPONENT = 'mod_statuscheckabsentplugin';

    /**
     * Index the returned plugin list by component name.
     *
     * @param array $result Return value of {@see get_plugin_inventory::execute()}.
     * @return array component => entry
     */
    private function by_component(array $result): array {
        $this->assertArrayHasKey('plugins', $result);
        $indexed = [];
        foreach ($result['plugins'] as $entry) {
            $indexed[$entry['component']] = $entry;
        }
        return $indexed;
    }

    /**
     * The component names nested as subplugins of a top-level entry.
     *
     * @param array $entry A top-level plugin entry.
     * @return string[]
     */
    private function subplugin_components(array $entry): array {
        return array_map(static function ($sub) {
            return $sub['component'];
        }, $entry['subplugins']);
    }

    /**
     * A single installed plugin is reported with its details.
     */
    public function test_single_installed_plugin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute(['mod_assign']);
        $indexed = $this->by_component($result);

        $this->assertCount(1, $result['plugins']);
        $this->assertArrayHasKey('mod_assign', $indexed);
        $this->assertSame('installed', $indexed['mod_assign']['status']);
        $this->assertGreaterThan(0, $indexed['mod_assign']['version']);
        $this->assertNotSame('', $indexed['mod_assign']['displayname']);
        $this->assertContains($indexed['mod_assign']['installation_source'], ['git', 'zip']);
        // mod_assign's subplugins are nested inside it.
        $this->assertContains('assignsubmission_file', $this->subplugin_components($indexed['mod_assign']));
    }

    /**
     * Several plugins can be requested in one call.
     */
    public function test_multiple_plugins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute(['mod_assign', 'mod_forum']);
        $indexed = $this->by_component($result);

        $this->assertCount(2, $result['plugins']);
        $this->assertSame('installed', $indexed['mod_assign']['status']);
        $this->assertSame('installed', $indexed['mod_forum']['status']);
    }

    /**
     * Duplicated requested components are collapsed to a single entry.
     */
    public function test_duplicate_components_deduplicated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute(['mod_assign', 'mod_assign']);

        $this->assertCount(1, $result['plugins']);
    }

    /**
     * By default the full inventory returns the Moodle overview's "Additional
     * plugins" set: non-standard plugins only, at the top level.
     */
    public function test_full_inventory_additional_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute();
        $indexed = $this->by_component($result);

        // The plugin under test is additional, so it is present at the top level...
        $this->assertArrayHasKey('local_statuscheck', $indexed);
        $this->assertFalse($indexed['local_statuscheck']['is_subplugin']);
        $this->assertSame([], $indexed['local_statuscheck']['subplugins']);
        // ...while core/standard plugins (and their subplugins) are excluded.
        $this->assertArrayNotHasKey('mod_assign', $indexed);
        $this->assertArrayNotHasKey('assignsubmission_file', $indexed);
        // Every top-level entry is non-standard, and every nested subplugin too.
        foreach ($result['plugins'] as $entry) {
            $this->assertFalse($entry['is_standard'], $entry['component'] . ' should not be standard');
            foreach ($entry['subplugins'] as $sub) {
                $this->assertFalse($sub['is_standard'], $sub['component'] . ' should not be standard');
                $this->assertTrue($sub['is_subplugin'], $sub['component'] . ' should be a subplugin');
            }
        }
    }

    /**
     * includestandard=true returns every plugin, including standard plugins and
     * subplugins.
     */
    public function test_full_inventory_includes_standard_on_request(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute([], true);
        $indexed = $this->by_component($result);

        $this->assertGreaterThan(10, count($result['plugins']));
        $this->assertArrayHasKey('mod_assign', $indexed);
        $this->assertArrayHasKey('local_statuscheck', $indexed);
        $this->assertTrue($indexed['mod_assign']['is_standard']);
        $this->assertFalse($indexed['local_statuscheck']['is_standard']);
        // Subplugins are nested inside their parent, not listed at the top level.
        $this->assertArrayNotHasKey('assignsubmission_file', $indexed);
        $this->assertContains('assignsubmission_file', $this->subplugin_components($indexed['mod_assign']));
    }

    /**
     * A valid component that is not installed is reported as not_installed.
     */
    public function test_not_installed_component(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute([self::ABSENT_COMPONENT]);
        $indexed = $this->by_component($result);

        $this->assertSame('not_installed', $indexed[self::ABSENT_COMPONENT]['status']);
        $this->assertSame(0, $indexed[self::ABSENT_COMPONENT]['version']);
        $this->assertSame('', $indexed[self::ABSENT_COMPONENT]['displayname']);
        $this->assertSame('zip', $indexed[self::ABSENT_COMPONENT]['installation_source']);
    }

    /**
     * Installed and not-installed results are returned together.
     */
    public function test_mixed_installed_and_absent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute(['mod_assign', self::ABSENT_COMPONENT]);
        $indexed = $this->by_component($result);

        $this->assertSame('installed', $indexed['mod_assign']['status']);
        $this->assertSame('not_installed', $indexed[self::ABSENT_COMPONENT]['status']);
    }

    /**
     * A plugin recorded in the database but missing from disk is reported
     * explicitly, with its recorded version preserved.
     */
    public function test_missing_from_disk(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Record a version for a component that has no code on disk.
        set_config('version', 2023010100, self::ABSENT_COMPONENT);

        $result = get_plugin_inventory::execute([self::ABSENT_COMPONENT]);
        $indexed = $this->by_component($result);

        $this->assertSame('missing_from_disk', $indexed[self::ABSENT_COMPONENT]['status']);
        $this->assertSame(2023010100, $indexed[self::ABSENT_COMPONENT]['version']);
        $this->assertSame('zip', $indexed[self::ABSENT_COMPONENT]['installation_source']);
    }

    /**
     * An empty list behaves like omitting the parameter: the additional-only inventory.
     */
    public function test_empty_list_returns_full_inventory(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute([]);
        $indexed = $this->by_component($result);

        $this->assertArrayHasKey('local_statuscheck', $indexed);
        $this->assertArrayNotHasKey('mod_assign', $indexed);
    }

    /**
     * A malformed component name is rejected by parameter validation.
     */
    public function test_invalid_component_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(invalid_parameter_exception::class);
        get_plugin_inventory::execute(['Not A Component!']);
    }

    /**
     * A user without report/status:view cannot call the function.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        get_plugin_inventory::execute(['mod_assign']);
    }

    /**
     * The result conforms to the declared external structure (no stray fields,
     * values clean). This also guards against leaking non-inventory data.
     */
    public function test_return_structure_is_clean(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $raw = get_plugin_inventory::execute(['mod_assign']);
        $clean = \core_external\external_api::clean_returnvalue(get_plugin_inventory::execute_returns(), $raw);

        $basefields = ['component', 'displayname', 'version', 'status', 'installation_source',
            'git_branch', 'git_remote', 'is_standard', 'is_subplugin'];

        // A top-level entry has the base fields plus a nested subplugins list.
        $entry = $clean['plugins'][0];
        $this->assertSame(array_merge($basefields, ['subplugins']), array_keys($entry));

        // A nested subplugin entry has only the base fields (no further nesting).
        $this->assertNotEmpty($entry['subplugins']);
        $this->assertSame($basefields, array_keys($entry['subplugins'][0]));
    }

    /**
     * Installation source + branch: an own Git checkout (.git directory).
     */
    public function test_source_git_checkout(): void {
        $dir = make_request_directory();
        mkdir($dir . '/.git');
        file_put_contents($dir . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir . '/.git/config',
            "[remote \"origin\"]\n\turl = https://github.com/example/plugin.git\n");

        $this->assertSame('git', $this->call_detect_source($dir));
        $this->assertSame('main', $this->call_git_branch($dir));
        $this->assertSame('https://github.com/example/plugin.git', $this->call_git_remote($dir));
    }

    /**
     * The git remote URL has any embedded credentials stripped.
     */
    public function test_git_remote_strips_credentials(): void {
        $dir = make_request_directory();
        mkdir($dir . '/.git');
        file_put_contents($dir . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir . '/.git/config',
            "[remote \"origin\"]\n\turl = https://user:s3cr3t-token@github.com/example/plugin.git\n");

        $this->assertSame('https://github.com/example/plugin.git', $this->call_git_remote($dir));
    }

    /**
     * An SSH short-syntax remote (no secret) is returned unchanged.
     */
    public function test_git_remote_ssh_unchanged(): void {
        $dir = make_request_directory();
        mkdir($dir . '/.git');
        file_put_contents($dir . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir . '/.git/config',
            "[remote \"origin\"]\n\turl = git@github.com:example/plugin.git\n");

        $this->assertSame('git@github.com:example/plugin.git', $this->call_git_remote($dir));
    }

    /**
     * A detached HEAD (bare commit hash) is still git, but reports no branch.
     */
    public function test_source_git_detached_head(): void {
        $dir = make_request_directory();
        mkdir($dir . '/.git');
        file_put_contents($dir . '/.git/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

        $this->assertSame('git', $this->call_detect_source($dir));
        $this->assertSame('', $this->call_git_branch($dir));
    }

    /**
     * Installation source + branch: a Git submodule (.git file gitdir pointer).
     */
    public function test_source_git_submodule(): void {
        $dir = make_request_directory();
        $realgit = make_request_directory();
        file_put_contents($realgit . '/HEAD', "ref: refs/heads/feature/x\n");
        file_put_contents($realgit . '/config',
            "[remote \"origin\"]\n\turl = git@example.org:team/sub.git\n");
        file_put_contents($dir . '/.git', "gitdir: $realgit\n");

        $this->assertSame('git', $this->call_detect_source($dir));
        $this->assertSame('feature/x', $this->call_git_branch($dir));
        $this->assertSame('git@example.org:team/sub.git', $this->call_git_remote($dir));
    }

    /**
     * Code present on disk but not under Git is reported as a zip/package install.
     */
    public function test_source_zip_when_not_git(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/version.php', "<?php\n");

        $this->assertSame('zip', $this->call_detect_source($dir));
        $this->assertSame('', $this->call_git_branch($dir));
        $this->assertSame('', $this->call_git_remote($dir));
    }

    /**
     * A non-existent directory defaults to zip (never unknown), with no branch/remote.
     */
    public function test_source_zip_for_missing_dir(): void {
        $this->assertSame('zip', $this->call_detect_source('/this/path/does/not/exist'));
        $this->assertSame('', $this->call_git_branch('/this/path/does/not/exist'));
        $this->assertSame('', $this->call_git_remote('/this/path/does/not/exist'));
    }

    /**
     * The service identity is preserved and now exposes both the existing and the
     * new function, so pre-upgrade tokens keep working. This guards against a
     * regression in db/services.php.
     */
    public function test_service_and_functions_registered(): void {
        global $DB;
        $this->resetAfterTest();

        // The existing function is still registered.
        $this->assertTrue($DB->record_exists('external_functions',
            ['name' => 'local_statuscheck_get_system_status']));
        // The new function is registered.
        $this->assertTrue($DB->record_exists('external_functions',
            ['name' => 'local_statuscheck_get_plugin_inventory']));

        // The service keeps its shortname and exposes both functions.
        $service = $DB->get_record('external_services', ['shortname' => 'statuscheck']);
        $this->assertNotEmpty($service);
        $this->assertTrue($DB->record_exists('external_services_functions',
            ['externalserviceid' => $service->id, 'functionname' => 'local_statuscheck_get_system_status']));
        $this->assertTrue($DB->record_exists('external_services_functions',
            ['externalserviceid' => $service->id, 'functionname' => 'local_statuscheck_get_plugin_inventory']));
    }

    /**
     * Exercise Moodle's positional dispatch (validate_parameters then a positional
     * call via array_values), not just a direct execute() call. This is exactly
     * how the web service server invokes the function, and it is what catches
     * parameter-order regressions a direct call cannot (e.g. a VALUE_OPTIONAL
     * parameter that, when omitted, shifts the remaining arguments).
     */
    public function test_positional_dispatch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $info = \core_external\external_api::external_function_info('local_statuscheck_get_plugin_inventory');
        $callable = [$info->classname, $info->methodname];

        $dispatch = function (array $args) use ($info, $callable) {
            $cleaned = \core_external\external_api::validate_parameters($info->parameters_desc, $args);
            return call_user_func_array($callable, array_values($cleaned));
        };

        // Full inventory with no arguments at all (the call that previously failed).
        $result = $dispatch([]);
        $this->assertArrayHasKey('plugins', $result);

        // includestandard only, still no plugins list.
        $result = $dispatch(['includestandard' => 1]);
        $this->assertArrayHasKey('plugins', $result);

        // A specific plugin via dispatch.
        $result = $dispatch(['plugins' => ['mod_assign']]);
        $this->assertSame('mod_assign', $result['plugins'][0]['component']);
    }

    /**
     * The pre-existing system status endpoint still executes after this change.
     */
    public function test_existing_system_status_still_works(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_system_status::execute('all');

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('total', $result['summary']);
    }

    /**
     * The pre-existing health status endpoint still executes after this change.
     */
    public function test_existing_health_status_still_works(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_health_status::execute();

        $this->assertArrayHasKey('healthy', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Invoke the private static detect_installation_source() for a directory.
     *
     * @param string $rootdir Directory to classify.
     * @return string
     */
    private function call_detect_source(string $rootdir): string {
        $method = new \ReflectionMethod(get_plugin_inventory::class, 'detect_installation_source');
        $method->setAccessible(true);
        return $method->invoke(null, $rootdir);
    }

    /**
     * Invoke the private static detect_git_branch() for a directory.
     *
     * @param string $rootdir Directory to inspect.
     * @return string
     */
    private function call_git_branch(string $rootdir): string {
        $method = new \ReflectionMethod(get_plugin_inventory::class, 'detect_git_branch');
        $method->setAccessible(true);
        return $method->invoke(null, $rootdir);
    }

    /**
     * Invoke the private static detect_git_remote() for a directory.
     *
     * @param string $rootdir Directory to inspect.
     * @return string
     */
    private function call_git_remote(string $rootdir): string {
        $method = new \ReflectionMethod(get_plugin_inventory::class, 'detect_git_remote');
        $method->setAccessible(true);
        return $method->invoke(null, $rootdir);
    }
}
