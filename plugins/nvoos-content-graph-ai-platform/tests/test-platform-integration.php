<?php
/**
 * Platform addon smoke test — verifies all 10 subsystems register.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Plugin;

/**
 * @group integration
 */
class Test_Platform_Integration extends \WP_UnitTestCase {

	public function test_plugin_registers_without_fatal(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin );
		$plugin->register();
		$this->assertTrue( true );
	}

	public function test_all_subsystem_services_exist(): void {
		$services = array(
			'Agents'           => 'NvoosContentGraphAiPlatform\Agents\Agents',
			'Skills'           => 'NvoosContentGraphAiPlatform\Skills\SkillService',
			'SlashCommands'    => 'NvoosContentGraphAiPlatform\SlashCommands\SlashCommandService',
			'Harness'          => 'NvoosContentGraphAiPlatform\Harness\HarnessService',
			'Measurement'      => 'NvoosContentGraphAiPlatform\Measurement\MeasurementService',
			'Professions'      => 'NvoosContentGraphAiPlatform\Professions\ProfessionService',
			'A2A'              => 'NvoosContentGraphAiPlatform\A2A\A2AService',
			'ACP'              => 'NvoosContentGraphAiPlatform\ACP\ACPService',
			'Federation'       => 'NvoosContentGraphAiPlatform\Federation\FederationService',
			'Blueprints'       => 'NvoosContentGraphAiPlatform\Blueprints\BlueprintService',
		);

		foreach ( $services as $name => $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"$name service class ($class) should exist"
			);
		}
	}

	public function test_settings_registry_receives_tabs(): void {
		$this->assertTrue(
			class_exists( 'NvoosContentGraph\Admin\SettingsRegistry' ),
			'SettingsRegistry should be available from core'
		);

		// Register the platform admin.
		if ( class_exists( 'NvoosContentGraphAiPlatform\Admin\PlatformSettings' ) ) {
			( new \NvoosContentGraphAiPlatform\Admin\PlatformSettings() )->register();
		}

		do_action( 'nvoos_content_graph/admin/register_sections' );

		$tabs = \NvoosContentGraph\Admin\SettingsRegistry::get_tabs();
		$this->assertIsArray( $tabs );

		$expected = array( 'agents', 'skills', 'slash_commands', 'harness', 'measurement', 'professions', 'a2a', 'acp', 'federation', 'blueprints' );
		foreach ( $expected as $tab ) {
			$this->assertArrayHasKey( $tab, $tabs, "Tab '$tab' should be registered" );
		}
	}
}
