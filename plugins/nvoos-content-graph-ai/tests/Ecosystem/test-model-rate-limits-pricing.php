<?php
/**
 * Model rate-limits CCT + pricing checker port tests (Wave D3b).
 *
 * Characterization suite for `ModelRateLimitsCct` and
 * `ModelPricingChecker`. Assertions mirror the base plugin's catalog /
 * pricing tests: bundled catalog loading, cache behaviour, catalog and
 * source-path filters, JetEngine-less graceful degradation, registration
 * payload shape, and pricing-change detection across checks.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Model\ModelPricingChecker;
use NvoosContentGraphAi\Model\ModelRateLimitsCct;

/**
 * Test double exposing protected registration payload builders.
 */
class Testable_Model_Rate_Limits_Cct extends ModelRateLimitsCct {

	public static function expose_registration_request() {
		return self::get_registration_request();
	}

	public static function expose_meta_fields() {
		return self::get_meta_fields();
	}

	public static function expose_cct_args( $label ) {
		return self::get_cct_args( $label );
	}
}

/**
 * @group model
 */
class Test_Model_Rate_Limits_Pricing extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		ModelRateLimitsCct::flush_catalog_cache();
		\delete_option( ModelPricingChecker::OPTION_LAST_CHECK );
		\delete_option( ModelPricingChecker::OPTION_PRICE_CHANGES );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_model_catalog' );
		\remove_all_filters( 'wp_mcp_ai_model_catalog_source_path' );

		ModelRateLimitsCct::flush_catalog_cache();
		\delete_option( ModelPricingChecker::OPTION_LAST_CHECK );
		\delete_option( ModelPricingChecker::OPTION_PRICE_CHANGES );

		parent::tearDown();
	}

	// ─── Catalog loading ────────────────────────────────────────────

	public function test_catalog_loads_from_bundled_json(): void {
		$catalog = ModelRateLimitsCct::load_catalog();

		$this->assertNotEmpty( $catalog );
		$this->assertGreaterThan( 50, count( $catalog ) );

		$first = reset( $catalog );
		$this->assertArrayHasKey( 'model_name', $first );
		$this->assertArrayHasKey( 'provider', $first );

		$names = wp_list_pluck( $catalog, 'model_name' );
		$this->assertContains( 'gpt-4o', $names );
	}

	public function test_catalog_is_cached_and_flushable(): void {
		$filter_runs = 0;
		\add_filter(
			'wp_mcp_ai_model_catalog',
			static function ( $catalog ) use ( &$filter_runs ) {
				++$filter_runs;
				return $catalog;
			}
		);

		ModelRateLimitsCct::load_catalog();
		ModelRateLimitsCct::load_catalog();
		$this->assertSame( 1, $filter_runs ); // Cache hit — filter not re-run.

		ModelRateLimitsCct::flush_catalog_cache();
		ModelRateLimitsCct::load_catalog();
		$this->assertSame( 2, $filter_runs ); // Flush — filter re-runs.
	}

	public function test_catalog_filter_can_add_entries(): void {
		\add_filter(
			'wp_mcp_ai_model_catalog',
			static function ( $catalog ) {
				$catalog[] = array(
					'model_name' => 'custom-model-xyz',
					'provider'   => 'openai',
				);
				return $catalog;
			}
		);

		$catalog = ModelRateLimitsCct::load_catalog();
		$names   = wp_list_pluck( $catalog, 'model_name' );

		$this->assertContains( 'custom-model-xyz', $names );
	}

	public function test_catalog_source_path_filter(): void {
		$file = \wp_tempnam( 'd3b-catalog' );
		\file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$file,
			'{"version":"test","models":[{"model_name":"custom-file-model","provider":"test"}]}'
		);

		\add_filter(
			'wp_mcp_ai_model_catalog_source_path',
			static function () use ( $file ) {
				return $file;
			}
		);

		$catalog = ModelRateLimitsCct::load_catalog();

		$this->assertCount( 1, $catalog );
		$this->assertSame( 'custom-file-model', $catalog[0]['model_name'] );

		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// ─── JetEngine-less degradation ────────────────────────────────

	public function test_jetengine_absent_paths_degrade_gracefully(): void {
		$this->assertSame( 'ai_model_rate_limits', ModelRateLimitsCct::get_slug() );
		$this->assertNull( ModelRateLimitsCct::get_item_handler() );
		$this->assertNull( ModelRateLimitsCct::get_model_limits( 'gpt-4o' ) );
		$this->assertNull( ModelRateLimitsCct::get_model_fallback( 'gpt-4o' ) );

		// No-op without JetEngine.
		ModelRateLimitsCct::maybe_enable_data_stores();
		ModelRateLimitsCct::maybe_register_cct();
		ModelRateLimitsCct::maybe_populate_default_models();
	}

	// ─── Registration payload shape ────────────────────────────────

	public function test_registration_request_shape(): void {
		$request = Testable_Model_Rate_Limits_Cct::expose_registration_request();

		$this->assertSame( 'ai_model_rate_limits', $request['slug'] );
		$this->assertSame( 'AI Model Rate Limits', $request['name'] );

		$args = $request['args'];
		$this->assertSame( 'manage_options', $args['capability'] );
		$this->assertTrue( $args['rest_get_enabled'] );
		$this->assertArrayHasKey( 'model_name', $args['admin_columns'] );
		$this->assertArrayHasKey( 'tpm_limit', $args['admin_columns'] );
		$this->assertTrue( $args['admin_columns']['tpm_limit']['is_num'] );

		$fields = $request['meta_fields'];
		$this->assertCount( 14, $fields );
		$this->assertSame( 20001, $fields[0]['id'] );
		$this->assertSame( 'model_name', $fields[0]['name'] );
		$this->assertTrue( $fields[0]['is_required'] );
		$this->assertSame( 20014, $fields[13]['id'] );
		$this->assertSame( 'fallback_model', $fields[13]['name'] );

		foreach ( $fields as $field ) {
			$this->assertTrue( $field['show_in_rest'] );
		}
	}

	// ─── Pricing checker ────────────────────────────────────────────

	public function test_pricing_constants(): void {
		$this->assertSame( 'wp_mcp_ai_check_model_pricing', ModelPricingChecker::CRON_HOOK );
		$this->assertSame( 'wp_mcp_ai_last_pricing_check', ModelPricingChecker::OPTION_LAST_CHECK );
		$this->assertSame( 'wp_mcp_ai_price_changes', ModelPricingChecker::OPTION_PRICE_CHANGES );
		$this->assertSame( 0.0, ModelPricingChecker::MIN_PRICING_VALUE );
		$this->assertSame( 10.0, ModelPricingChecker::MAX_PRICING_VALUE );
	}

	public function test_pricing_check_baseline_then_detects_changes(): void {
		// First check establishes the baseline from the bundled catalog.
		// (In monolith runs the base plugin may already have populated the
		// same bookkeeping options during bootstrap, so the first ported
		// check can legitimately detect pre-existing drift for legacy
		// aliases — the target model's baseline is what matters below.)
		ModelPricingChecker::trigger_check();

		$baseline = \get_option( ModelPricingChecker::OPTION_LAST_CHECK, array() );
		$this->assertNotEmpty( $baseline );

		// Pick a model name that appears exactly once in the catalog — the
		// bookkeeping is keyed by model_name, so duplicate names would make
		// change attribution ambiguous (e.g. gpt-4o appears for both azure
		// and openai).
		$catalog = ModelRateLimitsCct::load_catalog();
		$counts  = array_count_values( wp_list_pluck( $catalog, 'model_name' ) );
		$target  = array_key_first( array_filter( $counts, static fn ( $count ) => 1 === $count ) );

		$this->assertNotNull( $target, 'Catalog must contain at least one unique model name.' );
		$this->assertArrayHasKey( $target, $baseline );

		$target_provider = '';
		foreach ( $catalog as $entry ) {
			if ( isset( $entry['model_name'] ) && $entry['model_name'] === $target ) {
				$target_provider = isset( $entry['provider'] ) ? $entry['provider'] : '';
				break;
			}
		}

		$old_input = $baseline[ $target ]['input'];

		// Simulate a catalog refresh changing the target model's input price.
		\add_filter(
			'wp_mcp_ai_model_catalog',
			static function ( $catalog ) use ( $target, $old_input ) {
				foreach ( $catalog as &$entry ) {
					if ( isset( $entry['model_name'] ) && $target === $entry['model_name'] ) {
						$entry['cost_per_1k_input_tokens'] = $old_input + 0.0001;
					}
				}
				return $catalog;
			}
		);

		ModelRateLimitsCct::flush_catalog_cache();
		ModelPricingChecker::trigger_check();

		$changes = ModelPricingChecker::get_price_changes();
		$this->assertNotEmpty( $changes );

		$target_change = null;
		foreach ( $changes as $change ) {
			if ( $target === $change['model'] ) {
				$target_change = $change;
				break;
			}
		}

		$this->assertNotNull( $target_change );
		$this->assertSame( $old_input, $target_change['old_input'] );
		$this->assertSame( $old_input + 0.0001, $target_change['new_input'] );
		$this->assertSame( $target_provider, $target_change['provider'] );
	}

	public function test_clear_price_changes(): void {
		\update_option( ModelPricingChecker::OPTION_PRICE_CHANGES, array( array( 'model' => 'x' ) ) );

		$this->assertTrue( ModelPricingChecker::clear_price_changes() );
		$this->assertSame( array(), ModelPricingChecker::get_price_changes() );
	}
}
