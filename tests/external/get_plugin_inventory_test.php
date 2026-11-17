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
        $this->assertContains($indexed['mod_assign']['installation_source'], ['git', 'zip', 'unknown']);
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
     * Omitting the parameter returns the full inventory.
     */
    public function test_full_inventory(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_plugin_inventory::execute();
        $indexed = $this->by_component($result);

        // A standard Moodle install has many plugins.
        $this->assertGreaterThan(10, count($result['plugins']));
        $this->assertArrayHasKey('mod_assign', $indexed);
        $this->assertArrayHasKey('local_statuscheck', $indexed);
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
        $this->assertSame('unknown', $indexed[self::ABSENT_COMPONENT]['installation_source']);
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
        $this->assertSame('unknown', $indexed[self::ABSENT_COMPONENT]['installation_source']);
    }

    /**
     * An explicitly empty list is a validation error.
     */
    public function test_empty_list_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(invalid_parameter_exception::class);
        get_plugin_inventory::execute([]);
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

        $entry = $clean['plugins'][0];
        $this->assertSame(
            ['component', 'displayname', 'version', 'status', 'installation_source'],
            array_keys($entry)
        );
    }

    /**
     * Installation source: an own Git checkout (.git directory) is detected.
     */
    public function test_source_git_checkout(): void {
        $dir = make_request_directory();
        mkdir($dir . '/.git');

        $this->assertSame('git', $this->call_detect_source($dir));
    }

    /**
     * Installation source: a Git submodule (.git file pointer) is detected.
     */
    public function test_source_git_submodule(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/.git', "gitdir: ../../.git/modules/local/statuscheck\n");

        $this->assertSame('git', $this->call_detect_source($dir));
    }

    /**
     * Installation source: a trusted ZIP deployment manifest is detected.
     */
    public function test_source_zip_manifest(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/.moodle_plugin_source.json', json_encode(['source' => 'zip']));

        $this->assertSame('zip', $this->call_detect_source($dir));
    }

    /**
     * Installation source: a manifest that does not confirm ZIP is not trusted.
     */
    public function test_source_manifest_without_zip_is_unknown(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/.moodle_plugin_source.json', json_encode(['source' => 'something']));

        $this->assertSame('unknown', $this->call_detect_source($dir));
    }

    /**
     * Installation source: no Git metadata and no manifest is unknown, never zip.
     */
    public function test_source_unknown_when_no_evidence(): void {
        $dir = make_request_directory();

        $this->assertSame('unknown', $this->call_detect_source($dir));
    }

    /**
     * Installation source: a non-existent directory resolves to unknown.
     */
    public function test_source_unknown_for_missing_dir(): void {
        $this->assertSame('unknown', $this->call_detect_source('/this/path/does/not/exist'));
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
}
