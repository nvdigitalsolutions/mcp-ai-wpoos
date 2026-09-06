<?php
/**
 * Model management port tests (Wave D3a) — catalog migration + integrity verifier.
 *
 * Characterization suite for `ModelCatalogMigration` and
 * `ModelIntegrityVerifier`. Assertions mirror the base plugin's model
 * catalog/integrity tests: legacy-id mapping, one-shot bookkeeping,
 * option/config/meta/settings rewriting, blocked models, vulnerability
 * filtering, and self-hosted endpoint TLS enforcement.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Model\ModelCatalogMigration;
use NvoosContentGraphAi\Model\ModelIntegrityVerifier;

/**
 * @group model
 */
class Test_Model_Management extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( ModelCatalogMigration::OPTION_KEY );
		\delete_option( ModelIntegrityVerifier::OPTION_BLOCKED_MODELS );
		\delete_option( ModelIntegrityVerifier::OPTION_INTEGRITY_LOG );
		\delete_option( 'wp_mcp_ai_model_configs' );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_ollama_endpoint' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_known_model_vulnerabilities' );
		\remove_all_filters( 'wp_mcp_ai_provider_endpoint' );

		\delete_option( ModelCatalogMigration::OPTION_KEY );
		\delete_option( ModelIntegrityVerifier::OPTION_BLOCKED_MODELS );
		\delete_option( ModelIntegrityVerifier::OPTION_INTEGRITY_LOG );
		\delete_option( 'wp_mcp_ai_model_configs' );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_ollama_endpoint' );

		parent::tearDown();
	}

	// ─── Model catalog migration ────────────────────────────────────

	public function test_legacy_id_map_shape(): void {
		$map = ModelCatalogMigration::get_legacy_id_map();

		$this->assertSame( 'gpt-4o-mini', $map['gpt-3.5-turbo'] );
		$this->assertSame( 'gpt-4.1', $map['gpt-4'] );
		$this->assertSame( 'o3-mini', $map['o1-mini'] );
		$this->assertSame( 'claude-sonnet-4-6', $map['claude-3-sonnet'] );
		$this->assertSame( 'gemini-2.5-pro', $map['gemini-pro'] );
		$this->assertSame( 'deepseek-v4-flash', $map['deepseek-chat'] );
		$this->assertSame( 'microsoft/phi-4', $map['microsoft/phi-3-mini-4k-instruct'] );
	}

	public function test_run_if_needed_is_one_shot_per_version(): void {
		$this->assertTrue( ModelCatalogMigration::run_if_needed( '2026.07.14' ) );
		$this->assertFalse( ModelCatalogMigration::run_if_needed( '2026.07.14' ) );
		$this->assertTrue( ModelCatalogMigration::run_if_needed( '2026.08.01' ) );
		$this->assertSame( '2026.08.01', \get_option( ModelCatalogMigration::OPTION_KEY ) );
	}

	public function test_migration_rewrites_default_model_setting(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option( 'wp_mcp_ai_settings', array( 'default_model' => 'gpt-3.5-turbo' ) );
		} else {
			\update_option( 'nvoos_content_graph_settings', array( 'ai_default_model' => 'gemini-pro' ) );
		}

		ModelCatalogMigration::run_if_needed( 'v-test' );

		$option  = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
		$key     = defined( 'WP_MCP_AI_PATH' ) ? 'default_model' : 'ai_default_model';
		$saved   = \get_option( $option, array() );

		$expected = defined( 'WP_MCP_AI_PATH' ) ? 'gpt-4o-mini' : 'gemini-2.5-pro';
		$this->assertSame( $expected, $saved[ $key ] );
	}

	public function test_migration_rewrites_model_configs_option(): void {
		\update_option(
			'wp_mcp_ai_model_configs',
			array(
				'gpt-4'           => array( 'provider' => 'openai', 'fallback_model' => 'gpt-3.5-turbo' ),
				'claude-3-sonnet' => array( 'provider' => 'anthropic' ),
			)
		);

		ModelCatalogMigration::run_if_needed( 'v-test' );

		$configs = \get_option( 'wp_mcp_ai_model_configs', array() );

		$this->assertArrayNotHasKey( 'gpt-4', $configs );
		$this->assertArrayHasKey( 'gpt-4.1', $configs );
		$this->assertSame( 'openai', $configs['gpt-4.1']['provider'] );
		$this->assertSame( 'gpt-4o-mini', $configs['gpt-4.1']['fallback_model'] );
		$this->assertArrayNotHasKey( 'claude-3-sonnet', $configs );
		$this->assertArrayHasKey( 'claude-sonnet-4-6', $configs );
	}

	public function test_migration_rewrites_assistant_post_meta(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, '_wp_mcp_ai_model', 'gpt-3.5-turbo' );

		ModelCatalogMigration::run_if_needed( 'v-test' );

		$this->assertSame( 'gpt-4o-mini', \get_post_meta( $post_id, '_wp_mcp_ai_model', true ) );
	}

	public function test_run_from_catalog_reads_bundled_version(): void {
		ModelCatalogMigration::run_from_catalog();

		$recorded = \get_option( ModelCatalogMigration::OPTION_KEY, '' );

		$this->assertSame( '2026.07.14', $recorded );
	}

	// ─── Model integrity verifier ───────────────────────────────────

	public function test_verify_model_passes_by_default(): void {
		$this->assertTrue( ModelIntegrityVerifier::verify_model( 'gpt-4o', 'openai' ) );
		$this->assertTrue( ModelIntegrityVerifier::verify_model( 'gemini-2.5-pro', 'gemini' ) );

		$log = ModelIntegrityVerifier::get_integrity_log( 10 );
		$this->assertNotEmpty( $log );
		// Newest first.
		$this->assertSame( 'passed', $log[0]['status'] );
		$this->assertSame( 'gemini-2.5-pro', $log[0]['model'] );
		$this->assertSame( 'gpt-4o', $log[1]['model'] );
	}

	public function test_blocked_model_rejected_and_unblocked(): void {
		$this->assertTrue( ModelIntegrityVerifier::block_model( 'gpt-4o', 'openai', 'policy violation' ) );

		$result = ModelIntegrityVerifier::verify_model( 'gpt-4o', 'openai' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_model_blocked', $result->get_error_code() );
		$this->assertStringContainsString( 'policy violation', $result->get_error_message() );

		$this->assertSame(
			array( 'openai/gpt-4o' => 'policy violation' ),
			ModelIntegrityVerifier::get_blocked_models()
		);

		$this->assertTrue( ModelIntegrityVerifier::unblock_model( 'gpt-4o', 'openai' ) );
		$this->assertTrue( ModelIntegrityVerifier::verify_model( 'gpt-4o', 'openai' ) );
	}

	public function test_known_vulnerability_filter(): void {
		\add_filter(
			'wp_mcp_ai_known_model_vulnerabilities',
			static function () {
				return array( 'openai/gpt-4o' => 'prompt-injection vector CVE-2026-0001' );
			}
		);

		$result = ModelIntegrityVerifier::verify_model( 'gpt-4o', 'openai' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_model_vulnerable', $result->get_error_code() );
	}

	public function test_self_hosted_endpoint_tls_enforcement(): void {
		// Seed the active endpoint store per matrix.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option( 'wp_mcp_ai_ollama_endpoint', 'http://remote.example.com:11434' );
		} else {
			\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'ollama_base_url', 'http://remote.example.com:11434' );
		}

		// Non-localhost over plain HTTP is rejected for self-hosted providers.
		$result = ModelIntegrityVerifier::verify_model( 'llama3.3', 'ollama' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_endpoint_not_https', $result->get_error_code() );

		// Hosted providers are trusted regardless.
		$this->assertTrue( ModelIntegrityVerifier::verify_model( 'gpt-4o', 'openai' ) );

		// Localhost is exempt.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option( 'wp_mcp_ai_ollama_endpoint', 'http://localhost:11434' );
		} else {
			\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'ollama_base_url', 'http://localhost:11434' );
		}
		$this->assertTrue( ModelIntegrityVerifier::verify_model( 'llama3.3', 'ollama' ) );
	}

	public function test_integrity_log_is_bounded_and_newest_first(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			ModelIntegrityVerifier::verify_model( "model-{$i}", 'openai' );
		}

		$log = ModelIntegrityVerifier::get_integrity_log( 2 );

		$this->assertCount( 2, $log );
		// Newest first.
		$this->assertSame( 'model-2', $log[0]['model'] );
		$this->assertSame( 'model-1', $log[1]['model'] );
	}
}
