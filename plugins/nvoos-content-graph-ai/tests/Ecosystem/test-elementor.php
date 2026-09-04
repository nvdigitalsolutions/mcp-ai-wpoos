<?php
/**
 * Elementor chat-family widget tests (Wave D-UI-3).
 *
 * Characterization suite for the ecosystem Elementor hub and the two
 * chat-family widgets. Elementor itself is never installed in the test
 * matrix, so this file defines a minimal `\Elementor\Widget_Base` +
 * `\Elementor\Controls_Manager` stub (mirroring the real surface the
 * widgets touch) before requiring the widget classes. The hub is tested
 * against fake manager objects, matching how Elementor calls it.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace Elementor {

	if ( ! class_exists( __NAMESPACE__ . '\Widget_Base' ) ) {
		/**
		 * Minimal Elementor Widget_Base stub for the test matrix.
		 */
		abstract class Widget_Base {

			/**
			 * Stub: no-op control registration.
			 */
			protected function register_controls() {}

			/**
			 * Stub: no-op section start.
			 *
			 * @param string $id   Section id.
			 * @param array  $args Section args.
			 */
			protected function start_controls_section( $id, $args ) {}

			/**
			 * Stub: no-op section end.
			 */
			protected function end_controls_section() {}

			/**
			 * Stub: no-op control registration.
			 *
			 * @param string $id   Control id.
			 * @param array  $args Control args.
			 */
			protected function add_control( $id, $args ) {}

			/**
			 * Stub: no-op responsive control registration.
			 *
			 * @param string $id   Control id.
			 * @param array  $args Control args.
			 */
			protected function add_responsive_control( $id, $args ) {}

			/**
			 * Stub settings.
			 *
			 * @return array
			 */
			protected function get_settings_for_display() {
				return array();
			}

			/**
			 * Stub element id.
			 *
			 * @return string
			 */
			protected function get_id() {
				return 'test-1';
			}

			/**
			 * Stub render.
			 */
			protected function render() {}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\Controls_Manager' ) ) {
		/**
		 * Minimal Elementor Controls_Manager stub.
		 */
		class Controls_Manager {
			const SELECT    = 'select';
			const SWITCHER  = 'switcher';
			const TEXT      = 'text';
			const NUMBER    = 'number';
			const COLOR     = 'color';
			const SLIDER    = 'slider';
			const ICONS     = 'icons';
			const TAB_STYLE = 'style';
		}
	}
}

namespace NvoosContentGraphAi\Tests {

use NvoosContentGraphAi\Blocks\Blocks;
use NvoosContentGraphAi\Elementor\ElementorHub;
use NvoosContentGraphAi\Frontend\ChatShortcode;

// Load the widget classes against the Elementor stub defined above.
// require_once keeps repeated loads across tests idempotent.
require_once dirname( __DIR__, 2 ) . '/src/Elementor/ChatWidget.php';
require_once dirname( __DIR__, 2 ) . '/src/Elementor/ChatBubbleWidget.php';

/**
 * Fake Elementor widgets manager (records registrations).
 */
class Fake_Widgets_Manager {

	/**
	 * Registered widgets.
	 *
	 * @var array
	 */
	public $registered = array();

	/**
	 * Record a widget registration.
	 *
	 * @param object $widget Widget instance.
	 */
	public function register( $widget ) {
		$this->registered[] = $widget;
	}
}

/**
 * Fake Elementor elements manager (records categories).
 */
class Fake_Elements_Manager {

	/**
	 * Registered categories (slug => args).
	 *
	 * @var array
	 */
	public $categories = array();

	/**
	 * Record a category registration.
	 *
	 * @param string $slug Category slug.
	 * @param array  $args Category args.
	 */
	public function add_category( $slug, $args ) {
		$this->categories[ $slug ] = $args;
	}
}

/**
 * Chat widget test double exposing the protected render path.
 */
class Test_Chat_Widget extends \NvoosContentGraphAi\Elementor\ChatWidget {

	/**
	 * Widget settings for the render test.
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * Return the injected settings.
	 *
	 * @param string|null $setting_key Optional setting key.
	 * @return mixed
	 */
	public function get_settings_for_display( $setting_key = null ) {
		return null === $setting_key ? $this->settings : ( $this->settings[ $setting_key ] ?? null );
	}

	/**
	 * Expose the protected render method.
	 */
	public function render_test() {
		$this->render();
	}
}

/**
 * Bubble widget test double exposing the protected render path.
 */
class Test_Bubble_Widget extends \NvoosContentGraphAi\Elementor\ChatBubbleWidget {

	/**
	 * Widget settings for the render test.
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * Return the injected settings.
	 *
	 * @param string|null $setting_key Optional setting key.
	 * @return mixed
	 */
	public function get_settings_for_display( $setting_key = null ) {
		return null === $setting_key ? $this->settings : ( $this->settings[ $setting_key ] ?? null );
	}

	/**
	 * Return a deterministic widget ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'test-1';
	}

	/**
	 * Expose the protected render method.
	 */
	public function render_test() {
		$this->render();
	}
}

/**
 * @group elementor
 */
class Test_Elementor extends \WP_UnitTestCase {

	/**
	 * Plugin src directory (for file-existence assertions).
	 *
	 * @var string
	 */
	private $src_dir;

	public function setUp(): void {
		parent::setUp();

		// Isolate the script/style registries — wp_scripts()/wp_styles()
		// are process-global singletons and inline-script data from other
		// tests would bleed in.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		// Render target for both widgets.
		( new ChatShortcode() )->register();

		// Bubble asset handles used by the bubble widget render path.
		( new Blocks() )->register_blocks();

		$this->src_dir = dirname( __DIR__, 2 ) . '/src/';
	}

	public function tearDown(): void {
		\wp_dequeue_script( Blocks::BUBBLE_SCRIPT_HANDLE );
		\wp_dequeue_style( Blocks::BUBBLE_STYLE_HANDLE );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Extract the injected frontend config from the registered inline
	 * script of the chat widget (the widget render target).
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

	// ─── Hub + file surface ─────────────────────────────────────────

	public function test_widget_files_exist(): void {
		$this->assertFileExists( $this->src_dir . 'Elementor/ElementorHub.php' );
		$this->assertFileExists( $this->src_dir . 'Elementor/ChatWidget.php' );
		$this->assertFileExists( $this->src_dir . 'Elementor/ChatBubbleWidget.php' );
		$this->assertFileExists( $this->src_dir . 'Elementor/README.md' );
	}

	public function test_hub_constants_and_hooks(): void {
		$this->assertSame( 'nvoos-content-graph-ai', ElementorHub::CATEGORY );

		$hub = new ElementorHub();
		$hub->register();

		$this->assertSame( 10, \has_action( 'elementor/widgets/register', array( $hub, 'register_widgets' ) ) );
		$this->assertSame( 10, \has_action( 'elementor/elements/categories_registered', array( $hub, 'register_category' ) ) );

		$this->assertSame(
			array(
				'NvoosContentGraphAi\Elementor\ChatWidget',
				'NvoosContentGraphAi\Elementor\ChatBubbleWidget',
			),
			$hub->get_widgets()
		);
	}

	public function test_hub_registers_widgets_with_manager(): void {
		$manager = new Fake_Widgets_Manager();

		( new ElementorHub() )->register_widgets( $manager );

		$this->assertCount( 2, $manager->registered );
		$this->assertInstanceOf( \NvoosContentGraphAi\Elementor\ChatWidget::class, $manager->registered[0] );
		$this->assertInstanceOf( \NvoosContentGraphAi\Elementor\ChatBubbleWidget::class, $manager->registered[1] );
	}

	public function test_hub_registers_category(): void {
		$manager = new Fake_Elements_Manager();

		( new ElementorHub() )->register_category( $manager );

		$this->assertArrayHasKey( ElementorHub::CATEGORY, $manager->categories );
		$this->assertArrayHasKey( 'title', $manager->categories[ ElementorHub::CATEGORY ] );
	}

	// ─── Chat widget ─────────────────────────────────────────────────

	public function test_chat_widget_identity(): void {
		$widget = new \NvoosContentGraphAi\Elementor\ChatWidget();

		$this->assertSame( 'nvoos_cg_chat', $widget->get_name() );
		$this->assertSame( array( ElementorHub::CATEGORY ), $widget->get_categories() );
		$this->assertContains( 'ai', $widget->get_keywords() );
		$this->assertContains( ChatShortcode::SCRIPT_HANDLE, $widget->get_script_depends() );
		$this->assertContains( ChatShortcode::STYLE_HANDLE, $widget->get_style_depends() );
	}

	public function test_chat_widget_render_maps_settings_to_widget(): void {
		$widget           = new Test_Chat_Widget();
		$widget->settings = array(
			'provider'    => 'gemini',
			'model'       => 'gemini-1.5-pro',
			'show_cost'   => '',
			'placeholder' => 'Ask me anything',
			'height'      => '400px',
		);

		\ob_start();
		$widget->render_test();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'nvoos-cg-elementor-chat', $html );
		$this->assertStringContainsString( 'nvoos-content-graph-chat-widget', $html );
		$this->assertStringContainsString( 'height:400px', $html );

		$config = $this->injected_config();

		$this->assertNotNull( $config );
		$this->assertSame( 'gemini', $config['provider'] );
		$this->assertSame( 'gemini-1.5-pro', $config['model'] );
		$this->assertFalse( $config['showCost'] );
		$this->assertSame( 'Ask me anything', $config['placeholder'] );
	}

	// ─── Bubble widget ───────────────────────────────────────────────

	public function test_bubble_widget_identity(): void {
		$widget = new \NvoosContentGraphAi\Elementor\ChatBubbleWidget();

		$this->assertSame( 'nvoos_cg_chat_bubble', $widget->get_name() );
		$this->assertSame( array( ElementorHub::CATEGORY ), $widget->get_categories() );
		$this->assertContains( 'bubble', $widget->get_keywords() );
		$this->assertContains( Blocks::BUBBLE_SCRIPT_HANDLE, $widget->get_script_depends() );
		$this->assertContains( Blocks::BUBBLE_STYLE_HANDLE, $widget->get_style_depends() );
	}

	public function test_bubble_widget_render_markup_and_assets(): void {
		$widget           = new Test_Bubble_Widget();
		$widget->settings = array(
			'bubble_position'    => 'top-left',
			'bubble_size'        => 'large',
			'bubble_animation'   => 'none',
			'bubble_tooltip'     => 'Need help?',
			'panel_title'        => 'Support',
			'panel_width'        => array( 'size' => 600 ),
			'panel_height'       => array( 'size' => 700 ),
			'bubble_color'       => '#123456',
			'header_background'  => '#abcdef',
			'remember_state'     => 'yes',
			'auto_open_delay'    => 5,
			'notification_badge' => 'yes',
		);

		\ob_start();
		$widget->render_test();
		$html = \ob_get_clean();

		// Position/size classes; animation class suppressed when "none".
		$this->assertStringContainsString( 'nvoos-cg-bubble--top-left', $html );
		$this->assertStringContainsString( 'nvoos-cg-bubble--large', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-bubble--bounce', $html );

		// CSS custom properties only for non-default values.
		$this->assertStringContainsString( '--nvoos-cg-bubble-color:#123456', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-header-background:#abcdef', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-panel-width:600px', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-panel-height:700px', $html );

		// Data attributes + tooltip + panel title.
		$this->assertStringContainsString( 'data-bubble-id="nvoos-cg-bubble-test-1"', $html );
		$this->assertStringContainsString( 'data-remember-state="true"', $html );
		$this->assertStringContainsString( 'data-auto-open-delay="5"', $html );
		$this->assertStringContainsString( 'Need help?', $html );
		$this->assertStringContainsString( 'Support', $html );

		// Embedded widget + bubble assets enqueued.
		$this->assertStringContainsString( 'nvoos-content-graph-chat-widget', $html );
		$this->assertTrue( \wp_script_is( Blocks::BUBBLE_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( \wp_style_is( Blocks::BUBBLE_STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_bubble_widget_render_sanitizes_input(): void {
		$widget           = new Test_Bubble_Widget();
		$widget->settings = array(
			'bubble_color'   => 'red;background:url(evil)',
			'bubble_tooltip' => '<script>alert(1)</script>Hello',
			'panel_title'    => '<b>Hi</b>',
		);

		\ob_start();
		$widget->render_test();
		$html = \ob_get_clean();

		// Invalid hex colours never reach the style attribute.
		$this->assertStringNotContainsString( 'red;background', $html );
		// Tags are stripped from the tooltip; tags are stripped from the
		// panel title by sanitize_text_field() before esc_html() runs,
		// so the title renders as plain text rather than escaped markup.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Hello', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'nvoos-cg-bubble__panel-title">Hi</span>', $html );
	}
}
}
