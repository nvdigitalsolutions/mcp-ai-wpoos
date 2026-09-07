<?php
/**
 * Media-library columns ported-class tests (Wave E-UI-2, sub-cluster 6).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Media_Library_Columns` preserves the public
 * behaviour: the byte-identical meta key, the singleton contract, the
 * AI Usage column, the no-usage dash and the tokens/cost/operations
 * badge render surface, the token-count and cost formatters, the
 * attachment-usage reader (null degradations), the per-attachment
 * usage tracker (image-tool allowlist, argument/result
 * attachment-id extraction, usage accumulation with per-tool counts,
 * provider/model stamps, per-mode cost contribution), the
 * admin-context hook wiring, and the media-page-only stylesheet
 * enqueue. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\MediaLibraryColumns;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected helpers are published as
 * public wrappers, with its own singleton so the parent's shared
 * instance stays untouched.
 */
class MediaLibraryColumnsExposer extends MediaLibraryColumns {

	/**
	 * Exposer-owned singleton instance.
	 *
	 * @var MediaLibraryColumnsExposer|null
	 */
	protected static $instance = null;

	/**
	 * Get the exposer singleton (its constructor inherits the parent
	 * wiring).
	 *
	 * @return MediaLibraryColumnsExposer
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			static::$instance = new self();
		}
		return static::$instance;
	}

	public function exposed_format_token_count( $tokens ): string {
		return $this->format_token_count( $tokens );
	}

	public function exposed_format_cost( $cost ): string {
		return $this->format_cost( $cost );
	}

	public function exposed_extract_attachment_id( $arguments, $result ) {
		return $this->extract_attachment_id( $arguments, $result );
	}

	public function exposed_render_badges( array $usage, int $attachment_id ): string {
		\ob_start();
		try {
			$this->render_usage_badges( $usage, $attachment_id );
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}

	/**
	 * Re-arm the constructor wiring (wp-phpunit restores the hook
	 * snapshot at each tearDown — see the class docblock).
	 *
	 * @return void
	 */
	public function exposed_rewire(): void {
		$this->wire_hooks();
	}
}

/**
 * Media-library columns characterisation suite (Wave E-UI-2, sub-cluster 6).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Media_Library_Columns extends \WP_UnitTestCase {

	/**
	 * Columns instance under test.
	 *
	 * @var MediaLibraryColumnsExposer
	 */
	private $columns;

	public function setUp(): void {
		parent::setUp();

		// Mock is_admin for tests — the singleton constructor wires its
		// hooks only in admin context.
		\set_current_screen( 'upload.php' );

		$this->columns = MediaLibraryColumnsExposer::get_instance();

		// wp-phpunit snapshots the hook registry at setUp and restores it
		// at tearDown — re-arm the singleton's constructor wiring so every
		// test observes the registered hooks (add_filter dedupes).
		$this->columns->exposed_rewire();

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}
	}

	public function tearDown(): void {
		$this->columns = null;
		parent::tearDown();
	}

	/**
	 * Create a test attachment.
	 *
	 * @return int
	 */
	private function create_attachment(): int {
		return self::factory()->attachment->create();
	}

	// ─── Public surface ─────────────────────────────────────────

	public function test_constants_byte_identical(): void {
		$this->assertSame( '_wp_mcp_ai_usage', MediaLibraryColumns::USAGE_META_KEY );
	}

	public function test_singleton_contract(): void {
		$instance1 = MediaLibraryColumnsExposer::get_instance();
		$instance2 = MediaLibraryColumnsExposer::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance.' );

		// The parent init() contract returns the parent singleton.
		$this->assertInstanceOf( MediaLibraryColumns::class, MediaLibraryColumns::init() );
	}

	// ─── Column + render surface ────────────────────────────────

	public function test_add_usage_column(): void {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$modified = $this->columns->add_usage_column( $columns );

		$this->assertArrayHasKey( 'wp_mcp_ai_usage', $modified );
		$this->assertSame( 'AI Usage', $modified['wp_mcp_ai_usage'] );
	}

	public function test_get_attachment_usage_null_when_no_meta(): void {
		$attachment_id = $this->create_attachment();

		$this->assertNull( $this->columns->get_attachment_usage( $attachment_id ) );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_get_attachment_usage_degradations(): void {
		$attachment_id = $this->create_attachment();

		// Empty array + non-array values degrade to null.
		\update_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, array() );
		$this->assertNull( $this->columns->get_attachment_usage( $attachment_id ) );

		\update_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, 'garbage' );
		$this->assertNull( $this->columns->get_attachment_usage( $attachment_id ) );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_get_attachment_usage_returns_data(): void {
		$attachment_id = $this->create_attachment();

		\update_post_meta(
			$attachment_id,
			MediaLibraryColumns::USAGE_META_KEY,
			array(
				'total_tokens' => 1500,
				'total_cost'   => 0.0023,
				'tool_count'   => 2,
				'last_used'    => '2024-01-15 10:00:00',
			)
		);

		$usage = $this->columns->get_attachment_usage( $attachment_id );

		$this->assertSame( 1500, $usage['total_tokens'] );
		$this->assertSame( 0.0023, $usage['total_cost'] );
		$this->assertSame( 2, $usage['tool_count'] );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_render_usage_column_ignores_wrong_column(): void {
		$attachment_id = $this->create_attachment();

		\ob_start();
		$this->columns->render_usage_column( 'title', $attachment_id );
		$output = \ob_get_clean();

		$this->assertSame( '', $output );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_render_usage_column_shows_dash_when_no_usage(): void {
		$attachment_id = $this->create_attachment();

		\ob_start();
		$this->columns->render_usage_column( 'wp_mcp_ai_usage', $attachment_id );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'wp-mcp-ai-no-usage', $output );
		$this->assertStringContainsString( '—', $output );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_render_usage_column_shows_badges(): void {
		$attachment_id = $this->create_attachment();

		\update_post_meta(
			$attachment_id,
			MediaLibraryColumns::USAGE_META_KEY,
			array(
				'total_tokens' => 2500,
				'total_cost'   => 0.0050,
				'tool_count'   => 3,
			)
		);

		\ob_start();
		$this->columns->render_usage_column( 'wp_mcp_ai_usage', $attachment_id );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'wp-mcp-ai-usage-badges', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-tokens', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-cost', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-tools', $output );
		$this->assertStringContainsString( '2.5k tok', $output );
		$this->assertStringContainsString( '3 ops', $output );
		$this->assertStringContainsString( 'data-attachment-id="' . $attachment_id . '"', $output );

		\wp_delete_attachment( $attachment_id, true );
	}

	// ─── Formatters ─────────────────────────────────────────────

	public function test_format_token_count(): void {
		$this->assertSame( '1.2M tok', $this->columns->exposed_format_token_count( 1200000 ) );
		$this->assertSame( '2.5k tok', $this->columns->exposed_format_token_count( 2500 ) );
		$this->assertSame( '750 tok', $this->columns->exposed_format_token_count( 750 ) );
	}

	public function test_format_cost(): void {
		$this->assertSame( '$0.0050', $this->columns->exposed_format_cost( 0.0050 ) );
		$this->assertSame( '$0.250', $this->columns->exposed_format_cost( 0.25 ) );
		$this->assertSame( '$5.50', $this->columns->exposed_format_cost( 5.5 ) );
	}

	public function test_extract_attachment_id(): void {
		// Arguments win.
		$this->assertSame(
			42,
			$this->columns->exposed_extract_attachment_id(
				array( 'attachment_id' => 42 ),
				array( 'attachment_id' => 99 )
			)
		);

		// Result fallback for generation tools.
		$this->assertSame(
			99,
			$this->columns->exposed_extract_attachment_id(
				array( 'prompt' => 'A sunset' ),
				array( 'attachment_id' => 99 )
			)
		);

		// No id anywhere.
		$this->assertNull(
			$this->columns->exposed_extract_attachment_id(
				array( 'prompt' => 'A sunset' ),
				array( 'success' => true )
			)
		);
	}

	// ─── Usage tracking ─────────────────────────────────────────

	public function test_track_attachment_usage_updates_meta(): void {
		$attachment_id = $this->create_attachment();

		$this->columns->track_attachment_usage(
			'generate_image_alt_text',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'alt_text' => 'A test image',
				'success'  => true,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
					'total_tokens'      => 150,
				),
			)
		);

		$usage = \get_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, true );

		$this->assertIsArray( $usage );
		$this->assertSame( 150, $usage['total_tokens'] );
		$this->assertSame( 1, $usage['tool_count'] );
		$this->assertArrayHasKey( 'generate_image_alt_text', $usage['tools'] );
		$this->assertNotEmpty( $usage['last_used'] );
		$this->assertSame( 'openai', $usage['last_provider'] );
		$this->assertSame( 'gpt-4o-mini', $usage['last_model'] );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base cost calculator contributes to the total.
			$this->assertArrayHasKey( 'total_cost', $usage );
		} else {
			// Standalone: the calculator is base-owned — cost stays 0.0.
			$this->assertSame( 0.0, $usage['total_cost'] );
		}

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_track_attachment_usage_accumulates(): void {
		$attachment_id = $this->create_attachment();

		$this->columns->track_attachment_usage(
			'generate_image_alt_text',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success'  => true,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array( 'total_tokens' => 100 ),
			)
		);

		$this->columns->track_attachment_usage(
			'generate_image_caption',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success'  => true,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array( 'total_tokens' => 75 ),
			)
		);

		$usage = \get_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, true );

		$this->assertSame( 175, $usage['total_tokens'] );
		$this->assertSame( 2, $usage['tool_count'] );
		$this->assertArrayHasKey( 'generate_image_alt_text', $usage['tools'] );
		$this->assertArrayHasKey( 'generate_image_caption', $usage['tools'] );
		$this->assertSame( 1, $usage['tools']['generate_image_alt_text'] );
		$this->assertSame( 1, $usage['tools']['generate_image_caption'] );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_track_attachment_usage_ignores_non_image_tools(): void {
		$attachment_id = $this->create_attachment();

		$this->columns->track_attachment_usage(
			'create_post',
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => 1 ),
			array(
				'success' => true,
				'usage'   => array( 'total_tokens' => 100 ),
			)
		);

		$this->assertSame( '', \get_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, true ) );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_track_attachment_usage_from_result(): void {
		$attachment_id = $this->create_attachment();

		$this->columns->track_attachment_usage(
			'generate_openai_image',
			array( 'prompt' => 'A sunset' ),
			array( 'user_id' => 1 ),
			array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'provider'      => 'openai',
				'model'         => 'dall-e-3',
				'usage'         => array( 'total_tokens' => 500 ),
			)
		);

		$usage = \get_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, true );

		$this->assertIsArray( $usage );
		$this->assertSame( 500, $usage['total_tokens'] );

		\wp_delete_attachment( $attachment_id, true );
	}

	public function test_track_attachment_usage_ignores_missing_attachment(): void {
		$attachment_id = $this->create_attachment();

		// Image tool but no attachment id anywhere → nothing tracked.
		$this->columns->track_attachment_usage(
			'resize_image',
			array( 'width' => 100 ),
			array( 'user_id' => 1 ),
			array( 'success' => true )
		);

		$this->assertSame( '', \get_post_meta( $attachment_id, MediaLibraryColumns::USAGE_META_KEY, true ) );

		\wp_delete_attachment( $attachment_id, true );
	}

	// ─── Wiring + assets ────────────────────────────────────────

	public function test_admin_hooks_wired_in_admin_context(): void {
		$this->assertNotFalse(
			\has_filter( 'manage_media_columns', array( $this->columns, 'add_usage_column' ) )
		);
		$this->assertSame(
			10,
			\has_action( 'manage_media_custom_column', array( $this->columns, 'render_usage_column' ) )
		);
		$this->assertSame(
			10,
			\has_action( 'wp_mcp_ai_after_tool_execution', array( $this->columns, 'track_attachment_usage' ) )
		);
		$this->assertSame(
			10,
			\has_action( 'admin_enqueue_scripts', array( $this->columns, 'enqueue_admin_styles' ) )
		);
	}

	public function test_enqueue_admin_styles_media_page(): void {
		$this->columns->enqueue_admin_styles( 'upload.php' );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-media-columns', 'enqueued' ) );

		global $wp_styles;
		$inline = $wp_styles->registered['wp-mcp-ai-media-columns']->extra['after'] ?? array();
		$this->assertNotEmpty( $inline );
	}

	public function test_enqueue_admin_styles_skips_other_pages(): void {
		\wp_deregister_style( 'wp-mcp-ai-media-columns' );

		$this->columns->enqueue_admin_styles( 'edit.php' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-media-columns', 'registered' ) );
	}
}
