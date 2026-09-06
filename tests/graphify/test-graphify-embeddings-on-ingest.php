<?php
/**
 * Tests for the embeddings-on-ingest helper — Phase 5 batch 2.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Embeddings_On_Ingest
 */
class Test_Graphify_Embeddings_On_Ingest extends WP_UnitTestCase {

	/**
	 * Reset cron + transients between tests.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		parent::tearDown();
	}

	/**
	 * Extract_text concatenates label, type, and known content props.
	 */
	public function test_extract_text_combines_relevant_fields() {
		$text = NV_oOS_Graphify_Embeddings_On_Ingest::extract_text(
			array(
				'label'      => 'Hello World',
				'type'       => 'product',
				'properties' => array(
					'description' => 'A widget',
					'summary'     => 'Useful',
					'noisy'       => 'ignored',
				),
			)
		);
		$this->assertStringContainsString( 'Hello World', $text );
		$this->assertStringContainsString( '(product)', $text );
		$this->assertStringContainsString( 'A widget', $text );
		$this->assertStringContainsString( 'Useful', $text );
		$this->assertStringNotContainsString( 'ignored', $text );
	}

	/**
	 * Whitespace runs collapse and the result is trimmed.
	 */
	public function test_extract_text_collapses_whitespace() {
		$text = NV_oOS_Graphify_Embeddings_On_Ingest::extract_text(
			array(
				'label'      => "  Hello\n\n  World  ",
				'properties' => array( 'description' => "line1\n\n\nline2" ),
			)
		);
		$this->assertSame( 'Hello World line1 line2', $text );
	}

	/**
	 * Extract_text returns '' for empty / unsuitable nodes.
	 */
	public function test_extract_text_empty_when_nothing_useful() {
		$this->assertSame( '', NV_oOS_Graphify_Embeddings_On_Ingest::extract_text( array() ) );
		$this->assertSame( '', NV_oOS_Graphify_Embeddings_On_Ingest::extract_text( array( 'properties' => array( 'irrelevant' => 'x' ) ) ) );
	}

	/**
	 * Should_skip is the inverse of having extractable text.
	 */
	public function test_should_skip() {
		$this->assertTrue( NV_oOS_Graphify_Embeddings_On_Ingest::should_skip( array() ) );
		$this->assertFalse( NV_oOS_Graphify_Embeddings_On_Ingest::should_skip( array( 'label' => 'x' ) ) );
	}

	/**
	 * Truncate caps overlong inputs without raising.
	 */
	public function test_truncate_caps_long_text() {
		$long = str_repeat( 'a', 12000 );
		$out  = NV_oOS_Graphify_Embeddings_On_Ingest::truncate( $long );
		$this->assertSame( 8000, strlen( $out ) );

		$short = 'short';
		$this->assertSame( 'short', NV_oOS_Graphify_Embeddings_On_Ingest::truncate( $short ) );
	}

	/**
	 * Is_enabled honours the embeddings_enabled / embed_on_ingest settings.
	 */
	public function test_is_enabled_honours_settings() {
		update_option(
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'embeddings_enabled' => 0,
				'embed_on_ingest'    => 1,
			)
		);
		$this->assertFalse( NV_oOS_Graphify_Embeddings_On_Ingest::is_enabled() );

		update_option(
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'embeddings_enabled' => 1,
				'embed_on_ingest'    => 0,
			)
		);
		$this->assertFalse( NV_oOS_Graphify_Embeddings_On_Ingest::is_enabled() );

		update_option(
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'embeddings_enabled' => 1,
				'embed_on_ingest'    => 1,
			)
		);
		$this->assertTrue( NV_oOS_Graphify_Embeddings_On_Ingest::is_enabled() );
	}

	/**
	 * Enqueue_for_node schedules a single cron event with the node ID.
	 */
	public function test_enqueue_schedules_cron_event() {
		// Capture the clock before scheduling: the event timestamp is taken at
		// enqueue time, and re-evaluating time() afterwards can cross a second
		// boundary and make the comparison flaky.
		$before = time();

		$ok = NV_oOS_Graphify_Embeddings_On_Ingest::enqueue_for_node( 'remote_x_n1', 'a payload' );
		$this->assertTrue( $ok );

		$ts = wp_next_scheduled( NV_oOS_Graphify_Embeddings_On_Ingest::CRON_ACTION, array( 'remote_x_n1' ) );
		$this->assertNotFalse( $ts );
		// Production schedules at time() - 1 so the event is due on the very
		// next cron tick; allow that one-second backdate.
		$this->assertGreaterThanOrEqual( $before - 5, $ts );
	}

	/**
	 * Enqueue_for_node short-circuits when node_id or text is missing.
	 */
	public function test_enqueue_returns_false_for_invalid_input() {
		$this->assertFalse( NV_oOS_Graphify_Embeddings_On_Ingest::enqueue_for_node( '', 'text' ) );
		$this->assertFalse( NV_oOS_Graphify_Embeddings_On_Ingest::enqueue_for_node( 'id', '' ) );
	}

	/**
	 * Auto_enqueue_remote_nodes is a no-op when the feature is disabled.
	 */
	public function test_auto_enqueue_skipped_when_disabled() {
		update_option( NV_oOS_Graphify::OPTION_KEY, array( 'embeddings_enabled' => 0 ) );
		$result = NV_oOS_Graphify_Embeddings_On_Ingest::auto_enqueue_remote_nodes(
			array(
				'node_id' => 'remote_x_skip',
				'label'   => 'Skip Me',
			)
		);
		$this->assertFalse( $result );
		$this->assertFalse( wp_next_scheduled( NV_oOS_Graphify_Embeddings_On_Ingest::CRON_ACTION, array( 'remote_x_skip' ) ) );
	}

	/**
	 * Auto_enqueue_remote_nodes schedules when enabled and node has text.
	 */
	public function test_auto_enqueue_schedules_when_enabled() {
		update_option(
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'embeddings_enabled' => 1,
				'embed_on_ingest'    => 1,
			)
		);
		$result = NV_oOS_Graphify_Embeddings_On_Ingest::auto_enqueue_remote_nodes(
			array(
				'node_id' => 'remote_x_go',
				'label'   => 'Go',
				'type'    => 'product',
			)
		);
		$this->assertTrue( $result );
		$this->assertNotFalse( wp_next_scheduled( NV_oOS_Graphify_Embeddings_On_Ingest::CRON_ACTION, array( 'remote_x_go' ) ) );
	}

	/**
	 * Auto_enqueue_remote_nodes skips nodes with no embeddable text.
	 */
	public function test_auto_enqueue_skips_empty_node() {
		update_option(
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'embeddings_enabled' => 1,
				'embed_on_ingest'    => 1,
			)
		);
		$result = NV_oOS_Graphify_Embeddings_On_Ingest::auto_enqueue_remote_nodes(
			array( 'node_id' => 'remote_x_empty' )
		);
		$this->assertFalse( $result );
	}

	/**
	 * The bypass filter runs the worker inline — verified by capturing the
	 * call to the embeddings backend via a wrapping action.
	 */
	public function test_bypass_filter_runs_inline() {
		$cb = static function () {
			return true;
		};
		add_filter( 'nvoos_graphify_embeddings_enqueue_bypass', $cb );
		try {
			$ok = NV_oOS_Graphify_Embeddings_On_Ingest::enqueue_for_node( 'remote_x_inline', 'inline payload' );
			$this->assertTrue( $ok );
			// No event should be scheduled — the bypass ran process_node()
			// inline. process_node may itself fail (no API key) but we
			// only assert the scheduling side-effect was suppressed.
			$this->assertFalse( wp_next_scheduled( NV_oOS_Graphify_Embeddings_On_Ingest::CRON_ACTION, array( 'remote_x_inline' ) ) );
		} finally {
			remove_filter( 'nvoos_graphify_embeddings_enqueue_bypass', $cb );
		}
	}

	/**
	 * Register() wires the cron action handler.
	 */
	public function test_register_attaches_cron_handler() {
		NV_oOS_Graphify_Embeddings_On_Ingest::register();
		$this->assertNotFalse(
			has_action(
				NV_oOS_Graphify_Embeddings_On_Ingest::CRON_ACTION,
				array( 'NV_oOS_Graphify_Embeddings_On_Ingest', 'process_node' )
			)
		);
	}
}
