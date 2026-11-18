<?php
/**
 * Every toolkit settings page must persist what it renders.
 *
 * Nine Pro toolkit settings pages shipped a configuration tab whose controls
 * were plain `name="key"` inputs. `options.php` only hands `<option>[key]`
 * values to the registered sanitiser, so every value those pages collected was
 * discarded on save — and the base sanitiser returned only its own three keys,
 * which also meant a save erased anything else stored in the option.
 *
 * These tests are the durable gate for that class of bug, in three directions:
 *
 *  1. **Markup** — every rendered control is namespaced under the page's own
 *     option, so WordPress can route it to the sanitiser at all.
 *  2. **Schema** — the declared `setting_schema()` keys and the rendered keys
 *     are the same set, so adding a field without declaring it (or the reverse)
 *     fails here instead of silently dropping data in production.
 *  3. **Behaviour** — a submitted payload round-trips through
 *     `sanitize_settings()` with the declared types, and an unchecked checkbox
 *     writes `false` rather than leaving the old value in place.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 3 ) . '/addons/pro/' );
}

/**
 * Toolkit settings persistence gate.
 */
class Test_Toolkit_Settings_Persistence extends WP_UnitTestCase {

	/**
	 * The one page that renders controls for the main settings option.
	 *
	 * Those three yfinance controls are inert: `settings_fields()` opens the
	 * toolkit's own option group, so `options.php` never processes a
	 * `wp_mcp_ai_settings[...]` field posted from here — and the main settings
	 * sanitiser does not register those keys either. They are listed rather than
	 * silently tolerated so the exception is visible; the fix belongs in the main
	 * settings form, not in this page.
	 *
	 * @var string
	 */
	const MAIN_OPTION_PAGE = 'financial-planner';

	/**
	 * Pages under test: label => [class, file, option].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function pages() {
		return array(
			'analytics'               => array( 'WP_MCP_AI_Analytics_Settings_Page', 'class-wp-mcp-ai-analytics-settings-page.php', 'wp_mcp_ai_analytics_toolkit_settings' ),
			'architectural-design'    => array( 'WP_MCP_AI_Architectural_Design_Settings_Page', 'class-wp-mcp-ai-architectural-design-settings-page.php', 'wp_mcp_ai_architectural_design_toolkit_settings' ),
			'ecommerce'               => array( 'WP_MCP_AI_Ecommerce_Settings_Page', 'class-wp-mcp-ai-ecommerce-settings-page.php', 'wp_mcp_ai_ecommerce_toolkit_settings' ),
			'financial-planner'       => array( 'WP_MCP_AI_Financial_Planner_Settings_Page', 'class-wp-mcp-ai-financial-planner-settings-page.php', 'wp_mcp_ai_financial_planner_toolkit_settings' ),
			'media-toolkit'           => array( 'WP_MCP_AI_Media_Toolkit_Settings_Page', 'class-wp-mcp-ai-media-toolkit-settings-page.php', 'wp_mcp_ai_media_toolkit_settings' ),
			'multilingual'            => array( 'WP_MCP_AI_Multilingual_Settings_Page', 'class-wp-mcp-ai-multilingual-settings-page.php', 'wp_mcp_ai_multilingual_toolkit_settings' ),
			'regulatory-registration' => array( 'WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page', 'class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php', 'wp_mcp_ai_regulatory_registration_toolkit_settings' ),
			'social-media'            => array( 'WP_MCP_AI_Social_Media_Settings_Page', 'class-wp-mcp-ai-social-media-settings-page.php', 'wp_mcp_ai_social_media_toolkit_settings' ),
			'video-production'        => array( 'WP_MCP_AI_Video_Production_Settings_Page', 'class-wp-mcp-ai-video-production-settings-page.php', 'wp_mcp_ai_video_production_toolkit_settings' ),
		);
	}

	/**
	 * Load the base class and every page under test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

		// media-toolkit reads its parent slug from the media menu registry, and
		// that file is self-contained (ABSPATH guard plus one class).
		$menu = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-admin-menu.php';

		if ( ! class_exists( 'WP_MCP_AI_Media_Admin_Menu' ) && file_exists( $menu ) ) {
			require_once $menu;
		}

		foreach ( self::pages() as $page ) {
			$file = WP_MCP_AI_PRO_PATH . 'includes/admin/' . $page[1];

			if ( file_exists( $file ) ) {
				require_once $file;
			}

			// A previous test's sanitised array must not leak into this one.
			delete_option( $page[2] );
		}
	}

	/**
	 * Every rendered control is routed to this page's own option.
	 */
	public function test_every_rendered_control_is_namespaced_to_its_option() {
		foreach ( self::pages() as $label => $page ) {
			$file = WP_MCP_AI_PRO_PATH . 'includes/admin/' . $page[1];

			if ( ! file_exists( $file ) ) {
				continue;
			}

			$source = (string) file_get_contents( $file );
			preg_match_all( '/name="([^"]*)"/', $source, $matches );

			foreach ( $matches[1] as $name ) {
				if ( '' === trim( $name ) ) {
					continue;
				}

				if ( str_contains( $name, '$this->option_name' ) ) {
					continue;
				}

				if ( self::MAIN_OPTION_PAGE === $label && str_starts_with( $name, 'wp_mcp_ai_settings[' ) ) {
					continue;
				}

				$this->fail(
					sprintf(
						'%s renders name="%s" without the option namespace, so options.php discards it on save.',
						$label,
						$name
					)
				);
			}
		}

		$this->assertTrue( true );
	}

	/**
	 * The declared schema is exactly the set of keys the markup renders.
	 *
	 * This is the anti-drift half: a new control with no schema entry fails
	 * here, and so does a schema entry whose control was removed.
	 */
	public function test_the_declared_schema_matches_the_rendered_controls() {
		foreach ( self::pages() as $label => $page ) {
			$class = $page[0];

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$file   = WP_MCP_AI_PRO_PATH . 'includes/admin/' . $page[1];
			$source = (string) file_get_contents( $file );

			preg_match_all( '/name="<\?php echo esc_attr\( \$this->option_name \); \?>\[([a-z0-9_]+)\]"/', $source, $matches );
			$rendered = array_unique( $matches[1] );
			sort( $rendered );

			$declared = array_keys( self::schema_for( new $class() ) );
			sort( $declared );

			$this->assertSame(
				$rendered,
				$declared,
				sprintf(
					'%s renders %d control(s) and declares %d. Rendered: %s. Declared: %s.',
					$label,
					count( $rendered ),
					count( $declared ),
					implode( ', ', $rendered ),
					implode( ', ', $declared )
				)
			);
		}
	}

	/**
	 * A submitted payload survives the sanitiser with its declared types.
	 */
	public function test_settings_round_trip_through_the_sanitiser() {
		foreach ( self::pages() as $label => $page ) {
			$class = $page[0];

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$instance = new $class();
			$schema   = self::schema_for( $instance );
			$input    = array();

			foreach ( $schema as $key => $spec ) {
				$type = is_array( $spec ) ? $spec['type'] : $spec;

				// Booleans are omitted on purpose: an unchecked box must write
				// false, not leave a previous true behind.
				if ( 'boolean' !== $type ) {
					$input[ $key ] = self::sample( $type );
				}
			}

			$sanitized = $instance->sanitize_settings( $input );

			foreach ( $schema as $key => $spec ) {
				$type = is_array( $spec ) ? $spec['type'] : $spec;

				$this->assertArrayHasKey( $key, $sanitized, sprintf( '%s: %s was dropped by the sanitiser.', $label, $key ) );

				if ( 'boolean' === $type ) {
					$this->assertFalse( $sanitized[ $key ], sprintf( '%s: %s should be false when unchecked.', $label, $key ) );
					continue;
				}

				if ( 'number' === $type ) {
					$this->assertSame( 42, $sanitized[ $key ], sprintf( '%s: %s should be cast to a number.', $label, $key ) );
					continue;
				}

				if ( 'url' === $type ) {
					$this->assertSame( 'https://example.com/hook', $sanitized[ $key ], sprintf( '%s: %s should be a URL.', $label, $key ) );
					continue;
				}

				$this->assertIsString( $sanitized[ $key ], sprintf( '%s: %s should be a string.', $label, $key ) );
				$this->assertNotSame( '', $sanitized[ $key ], sprintf( '%s: %s should not be empty.', $label, $key ) );
			}
		}
	}

	/**
	 * A stored key the form does not render is preserved.
	 *
	 * `wp_mcp_ai_media_toolkit_settings` is read by the Media Command Center, so
	 * a save from the toolkit page must not erase a key that page never showed.
	 */
	public function test_a_save_preserves_keys_the_form_does_not_render() {
		$class = 'WP_MCP_AI_Analytics_Settings_Page';

		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( 'Analytics settings page not available.' );
		}

		update_option(
			'wp_mcp_ai_analytics_toolkit_settings',
			array(
				'data_retention' => '365',
				'written_by'     => 'another-surface',
			)
		);

		$sanitized = ( new $class() )->sanitize_settings( array( 'data_retention' => '90' ) );

		$this->assertSame( '90', $sanitized['data_retention'] );
		$this->assertSame( 'another-surface', $sanitized['written_by'] );
	}

	/**
	 * The Research tab toggle can now be turned off.
	 *
	 * The old sanitiser wrote `enable_research` only when the key was present in
	 * POST, and an unchecked checkbox is absent — so the tab could be enabled but
	 * never disabled.
	 */
	public function test_an_unchecked_research_toggle_writes_false() {
		$page = new class() extends WP_MCP_AI_Toolkit_Settings_Base {
			/**
			 * Toolkit slug.
			 *
			 * @var string
			 */
			protected $toolkit_slug = 'test_toggle';

			/**
			 * Toolkit name.
			 *
			 * @var string
			 */
			protected $toolkit_name = 'Test Toggle';

			/**
			 * Settings option name.
			 *
			 * @var string
			 */
			protected $option_name = 'wp_mcp_ai_test_toggle_settings';

			/**
			 * Enable the Research tab, then register the page.
			 */
			public function __construct() {
				$this->has_research = true;

				parent::__construct();
			}

			/**
			 * Toolkit slug.
			 *
			 * @return string
			 */
			protected function get_toolkit_slug() {
				return $this->toolkit_slug;
			}

			/**
			 * Toolkit name.
			 *
			 * @return string
			 */
			protected function get_toolkit_name() {
				return $this->toolkit_name;
			}

			/**
			 * Overview tab.
			 *
			 * @return void
			 */
			protected function render_overview_tab() {
				// The fixture renders nothing.
			}

			/**
			 * Configuration tab.
			 *
			 * @return void
			 */
			protected function render_configuration_tab() {
				// The fixture renders nothing.
			}

			/**
			 * Tools list.
			 *
			 * @return array
			 */
			protected function get_tools_list() {
				return array();
			}
		};

		update_option( 'wp_mcp_ai_test_toggle_settings', array( 'enable_research' => true ) );

		$sanitized = $page->sanitize_settings( array() );

		$this->assertFalse( $sanitized['enable_research'] );

		delete_option( 'wp_mcp_ai_test_toggle_settings' );
	}

	/**
	 * Read a page's protected `setting_schema()`.
	 *
	 * @param WP_MCP_AI_Toolkit_Settings_Base $page Page instance.
	 * @return array<string, string|array{type: string, options?: array<int, string>}>
	 */
	private static function schema_for( $page ) {
		$method = new ReflectionMethod( $page, 'setting_schema' );
		$method->setAccessible( true );

		return (array) $method->invoke( $page );
	}

	/**
	 * A representative value for one declared type.
	 *
	 * @param string $type Declared type.
	 * @return string
	 */
	private static function sample( $type ) {
		switch ( $type ) {
			case 'number':
				return '42';

			case 'url':
				return 'https://example.com/hook';

			case 'email':
				return 'ops@example.com';

			case 'color':
				return '#123456';

			case 'key':
				return 'Some-Key';

			case 'textarea':
				return "first line\nsecond line";

			default:
				return 'Example';
		}
	}
}
