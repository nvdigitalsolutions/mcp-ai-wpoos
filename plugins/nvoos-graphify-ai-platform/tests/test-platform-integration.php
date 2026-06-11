<?php
/**
 * Platform addon smoke test — verifies all 10 subsystems register.
 *
 * @package NvoosGraphifyAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Tests;

use NvoosGraphifyAiPlatform\Plugin;

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
			'Agents'           => 'NvoosGraphifyAiPlatform\Agents\Agents',
			'Skills'           => 'NvoosGraphifyAiPlatform\Skills\SkillService',
			'SlashCommands'    => 'NvoosGraphifyAiPlatform\SlashCommands\SlashCommandService',
			'Harness'          => 'NvoosGraphifyAiPlatform\Harness\HarnessService',
			'Measurement'      => 'NvoosGraphifyAiPlatform\Measurement\MeasurementService',
			'Professions'      => 'NvoosGraphifyAiPlatform\Professions\ProfessionService',
			'A2A'              => 'NvoosGraphifyAiPlatform\A2A\A2AService',
			'ACP'              => 'NvoosGraphifyAiPlatform\ACP\ACPService',
			'Federation'       => 'NvoosGraphifyAiPlatform\Federation\FederationService',
			'Blueprints'       => 'NvoosGraphifyAiPlatform\Blueprints\BlueprintService',
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
			class_exists( 'NvoosGraphify\Admin\SettingsRegistry' ),
			'SettingsRegistry should be available from core'
		);

		// Register the platform admin.
		if ( class_exists( 'NvoosGraphifyAiPlatform\Admin\PlatformSettings' ) ) {
			( new \NvoosGraphifyAiPlatform\Admin\PlatformSettings() )->register();
		}

		do_action( 'nvoos_graphify/admin/register_sections' );

		$tabs = \NvoosGraphify\Admin\SettingsRegistry::get_tabs();
		$this->assertIsArray( $tabs );

		$expected = array( 'agents', 'skills', 'slash_commands', 'harness', 'measurement', 'professions', 'a2a', 'acp', 'federation', 'blueprints' );
		foreach ( $expected as $tab ) {
			$this->assertArrayHasKey( $tab, $tabs, "Tab '$tab' should be registered" );
		}
	}
}
