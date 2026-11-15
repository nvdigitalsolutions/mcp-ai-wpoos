<?php
/**
 * PHPUnit tests for the bridge option seeding (banner, carousel, theme
 * policy — Proposal 105, shop globals — Proposal 106 §1.3).
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Plugin;
use NvoosAstroBridge\Schema;

/**
 * Option seeding tests.
 *
 * @covers \NvoosAstroBridge\Plugin
 * @covers \NvoosAstroBridge\Schema
 * @group nvoos-astro-bridge
 */
class TestOptionSeeding extends WP_UnitTestCase {

	/**
	 * Plugin singleton under test.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Clear the seeded options before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->plugin = Plugin::instance();

		foreach (
			array(
				Schema::OPTION_BANNER,
				Schema::OPTION_CAROUSEL,
				Schema::OPTION_THEME_POLICY,
				Schema::OPTION_SHOP,
			) as $option
		) {
			delete_option( $option );
		}
	}

	/**
	 * The seed_default_options() method creates every global option with
	 * its defaults.
	 */
	public function test_seed_default_options_creates_all_globals(): void {
		$this->plugin->seed_default_options();

		$banner = get_option( Schema::OPTION_BANNER );
		$this->assertIsArray( $banner );
		$this->assertArrayHasKey( 'image', $banner );
		$this->assertArrayHasKey( 'promotion', $banner );

		$carousel = get_option( Schema::OPTION_CAROUSEL );
		$this->assertIsArray( $carousel );
		$this->assertArrayHasKey( 'carousel', $carousel );

		$theme = get_option( Schema::OPTION_THEME_POLICY );
		$this->assertIsArray( $theme );
		$this->assertSame( 'auto', $theme['policy'] );
		$this->assertSame( 'auto', $theme['contrast'] );
		$this->assertSame( 'dark', $theme['defaultTheme'] );
		$this->assertSame( array(), $theme['$extensions'] );
		$this->assertNotEmpty( $theme['updatedAt'] );

		$shop = get_option( Schema::OPTION_SHOP );
		$this->assertIsArray( $shop );
		$this->assertStringContainsString( '/checkout/', $shop['checkoutUrl'] );
		$this->assertStringContainsString( '/cart/', $shop['cartUrl'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $shop['currency'] );
		$this->assertSame( '$', $shop['currencySymbol'] );
		$this->assertNotEmpty( $shop['updatedAt'] );
	}

	/**
	 * Seeding is additive — existing option values are never overwritten.
	 */
	public function test_seed_keeps_existing_values(): void {
		update_option(
			Schema::OPTION_THEME_POLICY,
			array(
				'policy'       => 'light',
				'contrast'     => 'high',
				'defaultTheme' => 'light',
				'updatedAt'    => '2026-11-03T09:00:00Z',
				'$extensions'  => array(),
			)
		);

		$this->plugin->seed_default_options();

		$theme = get_option( Schema::OPTION_THEME_POLICY );
		$this->assertSame( 'light', $theme['policy'] );
		$this->assertSame( 'high', $theme['contrast'] );
		$this->assertSame( '2026-11-03T09:00:00Z', $theme['updatedAt'] );
	}
}
