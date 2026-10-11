<?php
/**
 * Tests for the Model Foundry Corpus Governance engine (Pro, Phase 1).
 *
 * Exercises the consent gate (fail-closed meta + filter override), schema
 * validation, three-tier dedup, the deterministic holdout split, PII
 * scrubbing, the R12 provenance manifest, and the guarded sharded write.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Corpus governance tests.
 */
class Test_Model_Foundry_Governance extends WP_UnitTestCase {

	/**
	 * Assistant post ID used by tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Foundry Governance Assistant',
			)
		);

		$this->grant_consent();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_model_foundry_assistant_consented' );
		remove_all_filters( 'wp_mcp_ai_model_foundry_embed_text' );
		parent::tearDown();
	}

	/**
	 * Load the governance class.
	 */
	private function load_governance() {
		if ( ! class_exists( 'WP_MCP_AI_Corpus_Governance' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-corpus-governance.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Corpus_Schema_Registry' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-corpus-schema-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Corpus_Deduper' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-corpus-deduper.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Corpus_Pii' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-corpus-pii.php';
		}
	}

	/**
	 * Grant training consent on the fixture assistant.
	 */
	private function grant_consent() {
		update_post_meta( $this->assistant_id, WP_MCP_AI_Corpus_Governance::CONSENT_META, '1' );
	}

	/**
	 * Build a minimal chat_v1 row.
	 *
	 * @param string $text User content.
	 * @return array
	 */
	private function chat_row( $text ) {
		return array(
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $text,
				),
			),
		);
	}

	/**
	 * Class loads.
	 */
	public function test_class_exists() {
		$this->load_governance();
		$this->assertTrue( class_exists( 'WP_MCP_AI_Corpus_Governance' ) );
	}

	/**
	 * Consent meta absent → denied, fail closed.
	 */
	public function test_consent_absent_is_denied() {
		$this->load_governance();
		delete_post_meta( $this->assistant_id, WP_MCP_AI_Corpus_Governance::CONSENT_META );

		$result = ( new WP_MCP_AI_Corpus_Governance() )->assert_consent( $this->assistant_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_training_consent_required', $result->get_error_code() );
	}

	/**
	 * Consent meta '1' → granted.
	 */
	public function test_consent_granted_passes() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->assert_consent( $this->assistant_id );
		$this->assertTrue( $result );
	}

	/**
	 * Filter override false denies even with meta '1'.
	 */
	public function test_consent_filter_override_denies() {
		$this->load_governance();
		add_filter( 'wp_mcp_ai_model_foundry_assistant_consented', '__return_false' );

		$result = ( new WP_MCP_AI_Corpus_Governance() )->assert_consent( $this->assistant_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_training_consent_required', $result->get_error_code() );
	}

	/**
	 * Filter override true allows without meta.
	 */
	public function test_consent_filter_override_allows() {
		$this->load_governance();
		delete_post_meta( $this->assistant_id, WP_MCP_AI_Corpus_Governance::CONSENT_META );
		add_filter( 'wp_mcp_ai_model_foundry_assistant_consented', '__return_true' );

		$result = ( new WP_MCP_AI_Corpus_Governance() )->assert_consent( $this->assistant_id );

		$this->assertTrue( $result );
	}

	/**
	 * Unknown schema is rejected.
	 */
	public function test_unknown_schema_rejected() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build( $this->assistant_id, 'nonsense_v9', array( $this->chat_row( 'hi' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_unknown_corpus_schema', $result->get_error_code() );
	}

	/**
	 * Rows missing required keys are skipped with a named reason.
	 */
	public function test_invalid_rows_skipped() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array(
				array( 'no_messages_here' => true ),
				$this->chat_row( 'valid row' ),
			),
			array( 'holdout_split' => false )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows']['skipped']['skipped_invalid_row'] );
		$this->assertSame( 1, $result['rows']['train'] );
	}

	/**
	 * Exact and fuzzy dedup remove duplicates with correct tier accounting.
	 */
	public function test_exact_and_fuzzy_dedup() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array(
				$this->chat_row( 'Hello World!' ),
				$this->chat_row( 'Hello World!' ),
				$this->chat_row( 'hello world' ),
				$this->chat_row( 'Completely different content' ),
			),
			array( 'holdout_split' => false )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['dedup']['by_tier']['exact'] );
		$this->assertSame( 1, $result['dedup']['by_tier']['fuzzy'] );
		$this->assertSame( 2, $result['dedup']['removed'] );
		$this->assertSame( 2, $result['rows']['train'] );
	}

	/**
	 * Semantic dedup (opt-in) removes rows whose embeddings collide.
	 */
	public function test_semantic_dedup_with_filter() {
		$this->load_governance();
		add_filter(
			'wp_mcp_ai_model_foundry_embed_text',
			static function () {
				// Deterministic identical vectors → any two rows are duplicates.
				return function () {
					return array( 1.0, 0.0, 0.0 );
				};
			}
		);

		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array(
				$this->chat_row( 'alpha content' ),
				$this->chat_row( 'beta content' ),
			),
			array(
				'semantic' => true,
				'holdout_split' => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['dedup']['by_tier']['semantic'] );
		$this->assertSame( 1, $result['rows']['train'] );
	}

	/**
	 * Holdout split is deterministic: same rows → identical holdout shard.
	 */
	public function test_holdout_split_is_deterministic() {
		$this->load_governance();
		$rows = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$rows[] = $this->chat_row( 'row number ' . $i );
		}

		$first  = ( new WP_MCP_AI_Corpus_Governance() )->build( $this->assistant_id, 'chat_v1', $rows, array() );
		$second = ( new WP_MCP_AI_Corpus_Governance() )->build( $this->assistant_id, 'chat_v1', $rows, array() );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( $first['rows']['holdout'], $second['rows']['holdout'] );
		$this->assertGreaterThan( 0, $first['rows']['holdout'] );
		$this->assertSame( 40, $first['rows']['train'] + $first['rows']['holdout'] );
	}

	/**
	 * Holdout rows never appear in the train shard (decontamination).
	 */
	public function test_holdout_rows_never_in_train() {
		$this->load_governance();
		$rows = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$rows[] = $this->chat_row( 'decontam row ' . $i );
		}

		$result = ( new WP_MCP_AI_Corpus_Governance() )->build( $this->assistant_id, 'chat_v1', $rows, array() );
		$this->assertNotWPError( $result );

		$train_path   = '';
		$holdout_path = '';
		foreach ( $result['files'] as $file ) {
			if ( 'train.jsonl' === $file['name'] ) {
				$train_path = $file['path'];
			}
			if ( 'holdout.jsonl' === $file['name'] ) {
				$holdout_path = $file['path'];
			}
		}

		$this->assertNotSame( '', $train_path );
		$this->assertNotSame( '', $holdout_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading test-fixture corpus shards.
		$train_lines   = array_filter( explode( "\n", (string) file_get_contents( $train_path ) ) );
		$holdout_lines = array_filter( explode( "\n", (string) file_get_contents( $holdout_path ) ) );

		$this->assertSame( array(), array_intersect( $train_lines, $holdout_lines ) );
	}

	/**
	 * PII is scrubbed before writing; counts surface in the envelope.
	 */
	public function test_pii_scrubbed_in_output() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array(
				$this->chat_row( 'Contact me at person@example.com please' ),
			),
			array( 'holdout_split' => false )
		);

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['pii']['redactions'] );

		$train_path = '';
		foreach ( $result['files'] as $file ) {
			if ( 'train.jsonl' === $file['name'] ) {
				$train_path = $file['path'];
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading test-fixture corpus shard.
		$content = (string) file_get_contents( $train_path );
		$this->assertStringContainsString( '[REDACTED_EMAIL]', $content );
		$this->assertStringNotContainsString( 'person@example.com', $content );
	}

	/**
	 * The manifest carries the R12 provenance fields.
	 */
	public function test_manifest_contains_provenance_fields() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array( $this->chat_row( 'manifest row' ) ),
			array(
				'holdout_split' => false,
				'provenance_tool' => 'test_tool',
			)
		);

		$this->assertNotWPError( $result );

		$manifest_path = '';
		foreach ( $result['files'] as $file ) {
			if ( 'manifest.json' === $file['name'] ) {
				$manifest_path = $file['path'];
			}
		}
		$this->assertNotSame( '', $manifest_path );
		$this->assertFileExists( $manifest_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading test-fixture manifest.
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );

		foreach ( array( 'schema', 'corpus_id', 'assistant_id', 'created_at', 'rows', 'dedup', 'pii', 'holdout', 'consent', 'sources', 'shards', 'retention', 'provenance' ) as $key ) {
			$this->assertArrayHasKey( $key, $manifest, "Manifest missing key {$key}" );
		}
		$this->assertSame( 'granted', $manifest['consent']['status'] );
		$this->assertSame( 'test_tool', $manifest['provenance']['tool'] );
		$this->assertNotEmpty( $manifest['shards'] );
		$this->assertArrayHasKey( 'sha256', $manifest['shards'][0] );
	}

	/**
	 * Corpus guard files are placed in the output directory.
	 */
	public function test_guard_files_placed() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			array( $this->chat_row( 'guarded row' ) ),
			array( 'holdout_split' => false )
		);

		$this->assertNotWPError( $result );
		$dir = dirname( $result['files'][0]['path'] );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
	}

	/**
	 * Empty rows after the pipeline → error, not an empty write.
	 */
	public function test_empty_rows_returns_error() {
		$this->load_governance();
		$result = ( new WP_MCP_AI_Corpus_Governance() )->build( $this->assistant_id, 'chat_v1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_corpus_empty', $result->get_error_code() );
	}

	/**
	 * Rows above the cap are truncated with an honest count.
	 */
	public function test_row_cap_truncates() {
		$this->load_governance();
		$rows = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$rows[] = $this->chat_row( 'cap row ' . $i );
		}

		$result = ( new WP_MCP_AI_Corpus_Governance() )->build(
			$this->assistant_id,
			'chat_v1',
			$rows,
			array(
				'max_rows' => 10,
				'holdout_split' => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 15, $result['rows']['truncated_over_cap'] );
		$this->assertSame( 25, $result['rows']['input'] );
		$this->assertSame( 10, $result['rows']['train'] );
	}
}
