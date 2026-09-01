<?php
/**
 * Frontend chat widget shortcode tests (Wave D-UI-1b).
 *
 * Characterization suite for `NvoosContentGraphAi\Frontend\ChatShortcode`:
 * shortcode registration, widget markup, asset enqueuing, frontend config
 * injection (rest URL / nonce / provider / model / guest token), guest
 * token issuance gating, and the assistant attribute resolver.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Frontend\ChatShortcode;

/**
 * @group frontend
 */
class Test_Chat_Shortcode extends \WP_UnitTestCase {

	/**
	 * Shortcode instance under test.
	 *
	 * @var ChatShortcode
	 */
	private $shortcode;

	public function setUp(): void {
		parent::setUp();

		// Isolate the script registry — wp_scripts() is a process-global
		// singleton and inline-script data from other tests would bleed in.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		$this->shortcode = new ChatShortcode();
	}

	public function tearDown(): void {
		\wp_dequeue_style( ChatShortcode::STYLE_HANDLE );
		\wp_dequeue_script( ChatShortcode::SCRIPT_HANDLE );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Extract the injected frontend config from the registered inline script.
	 *
	 * @return array|null Decoded config or null when absent.
	 */
	private function injected_config(): ?array {
		$scripts = \wp_scripts();

		$registered = isset( $scripts->registered[ ChatShortcode::SCRIPT_HANDLE ] ) ? $scripts->registered[ ChatShortcode::SCRIPT_HANDLE ] : null;
		if ( ! $registered ) {
			return null;
		}

		$data = $registered->extra['before'] ?? array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		foreach ( $data as $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue; // WP may park sentinel values in the extra stack.
			}
			// Chunk shape: `window.NvoosContentGraphChat = ... || [];window.NvoosContentGraphChat.push( {...} );`
			if ( false === strpos( $chunk, 'window.NvoosContentGraphChat.push(' ) ) {
				continue;
			}
			if ( 1 === preg_match( '/push\(\s*(\{.*\})\s*\)\s*;/s', $chunk, $matches ) ) {
				$decoded = json_decode( $matches[1], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	// ─── Registration + markup ──────────────────────────────────────

	public function test_shortcode_registered_and_constants(): void {
		$this->assertSame( 'nvoos_content_graph_chat', ChatShortcode::SHORTCODE );
		$this->assertSame( 'nvoos-content-graph-ai-chat-frontend', ChatShortcode::SCRIPT_HANDLE );

		$this->shortcode->register();
		$this->assertTrue( \shortcode_exists( 'nvoos_content_graph_chat' ) );
	}

	public function test_render_outputs_widget_markup(): void {
		$html = $this->shortcode->render( array( 'height' => '320px' ) );

		$this->assertStringContainsString( 'class="nvoos-content-graph-chat-widget"', $html );
		$this->assertStringContainsString( 'height:320px', $html );
		$this->assertMatchesRegularExpression( '/id="nvoos-content-graph-chat-\d+"/', $html );
	}

	public function test_render_enqueues_assets(): void {
		$this->shortcode->render( array() );

		$this->assertTrue( \wp_style_is( ChatShortcode::STYLE_HANDLE, 'enqueued' ) );
		$this->assertTrue( \wp_script_is( ChatShortcode::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'nvoos-content-graph-ai-sse', 'enqueued' ) );

		// The frontend script depends on the shared SSE module.
		$registered = \wp_scripts()->registered[ ChatShortcode::SCRIPT_HANDLE ];
		$this->assertContains( 'nvoos-content-graph-ai-sse', $registered->deps );
	}

	// ─── Config injection ───────────────────────────────────────────

	public function test_config_injection_defaults(): void {
		$this->shortcode->render( array() );

		$config = $this->injected_config();

		$this->assertNotNull( $config );
		$this->assertSame( rest_url( 'nvoos-content-graph/v1' ), $config['restUrl'] );
		$this->assertNotSame( '', $config['nonce'] );
		$this->assertSame( '', $config['guestToken'] );
		$this->assertSame( '', $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertTrue( $config['showCost'] );
		$this->assertArrayHasKey( 'i18n', $config );
		$this->assertMatchesRegularExpression( '/^nvoos-content-graph-chat-\d+$/', $config['container'] );
	}

	public function test_config_injection_attributes(): void {
		$this->shortcode->render(
			array(
				'provider'    => 'gemini',
				'model'       => 'gemini-1.5-pro',
				'show_cost'   => '0',
				'placeholder' => 'Ask me anything',
			)
		);

		$config = $this->injected_config();

		$this->assertSame( 'gemini', $config['provider'] );
		$this->assertSame( 'gemini-1.5-pro', $config['model'] );
		$this->assertFalse( $config['showCost'] );
		$this->assertSame( 'Ask me anything', $config['placeholder'] );
	}

	// ─── Guest token issuance ───────────────────────────────────────

	public function test_no_guest_token_by_default(): void {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$this->shortcode->render( array( 'assistant' => (string) $assistant_id ) );
		$this->assertSame( '', $this->injected_config()['guestToken'] );
	}

	public function test_guest_token_requires_assistant(): void {
		$this->shortcode->render( array( 'allow_guests' => 'true' ) );
		$this->assertSame( '', $this->injected_config()['guestToken'] );
	}

	public function test_guest_token_issued_for_assistant(): void {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$this->shortcode->render(
			array(
				'allow_guests' => 'true',
				'assistant'    => (string) $assistant_id,
			)
		);

		$token = $this->injected_config()['guestToken'];
		$this->assertNotSame( '', $token );

		// The token validates back to the assistant (origin-bound records
		// skip origin checks when no request is passed).
		$this->assertSame(
			$assistant_id,
			\NvoosContentGraphAi\Chat\GuestToken::validate_guest_token( $token )
		);
	}

	// ─── Assistant resolution ───────────────────────────────────────

	public function test_resolve_assistant_id(): void {
		$reflection = new \ReflectionMethod( ChatShortcode::class, 'resolve_assistant_id' );
		$reflection->setAccessible( true );
		$resolve = static function ( $value ) use ( $reflection ) {
			return $reflection->invoke( new ChatShortcode(), (string) $value );
		};

		$this->assertSame( 0, $resolve( '' ) );
		$this->assertSame( 0, $resolve( 'no-such-slug' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_name'   => 'support-agent',
			)
		);

		$this->assertSame( $post_id, $resolve( (string) $post_id ) );
		$this->assertSame( $post_id, $resolve( 'support-agent' ) );

		// A plain post ID (wrong post type) does not resolve.
		$page_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertSame( 0, $resolve( (string) $page_id ) );
	}
}
