<?php
/**
 * Tests for Consolidate & Add admin page setup.
 *
 * Verifies that all consolidate & add pages are:
 *  1. Properly initialised in their respective init files.
 *  2. Using a singleton pattern so that init() and render_page() share
 *     the same instance, preventing duplicate hook registrations.
 *  3. Correctly registering their admin submenu pages.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test consolidate and add pages are properly set up.
 */
class Test_Consolidate_Add_Pages extends WP_UnitTestCase {

	/**
	 * Original WordPress settings restored after each test.
	 *
	 * @var array
	 */
	protected $original_settings = array();

	/**
	 * Original menu state.
	 *
	 * @var array
	 */
	protected $original_menu;

	/**
	 * Original submenu state.
	 *
	 * @var array
	 */
	protected $original_submenu;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		global $menu, $submenu;
		$this->original_menu    = $menu;
		$this->original_submenu = $submenu;
		$menu                   = array();
		$submenu                = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		if ( ! empty( $this->original_settings ) ) {
			update_option( 'wp_mcp_ai_settings', $this->original_settings );
		} else {
			delete_option( 'wp_mcp_ai_settings' );
		}

		global $menu, $submenu;
		$menu    = $this->original_menu;
		$submenu = $this->original_submenu;

		// Reset singleton instances so tests don't bleed into each other.
		$this->reset_singleton( 'WP_MCP_AI_Product_Consolidate_Page' );
		$this->reset_singleton( 'WP_MCP_AI_Event_Consolidate_Page' );
		$this->reset_singleton( 'WP_MCP_AI_Media_Consolidate_Page' );

		parent::tearDown();
	}

	/**
	 * Reset the private static $instance on a consolidate page class.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	private function reset_singleton( $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			return;
		}
		try {
			$reflection = new ReflectionClass( $class_name );
			if ( $reflection->hasProperty( 'instance' ) ) {
				$prop = $reflection->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		} catch ( ReflectionException $e ) {
			// Property may not exist on some pages — ignore.
		}
	}

	// ── Product Consolidate Page ────────────────────────────────────────────────

	/**
	 * Product consolidate page class must exist.
	 */
	public function test_product_consolidate_page_class_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-consolidate-page.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ),
			'WP_MCP_AI_Product_Consolidate_Page class should exist'
		);
	}

	/**
	 * init() and render_page() must share the same instance (singleton).
	 *
	 * If render_page() creates a second instance its constructor registers
	 * duplicate wp_ajax_* hooks and a second admin_init callback for
	 * handle_form_submission(), which can cause the form to be processed twice.
	 */
	public function test_product_consolidate_singleton_pattern() {
		if ( ! class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$this->markTestSkipped( 'Pro addon not available' );
			}
			$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-consolidate-page.php';
			if ( ! file_exists( $file ) ) {
				$this->markTestSkipped( 'Product consolidate page file not found' );
			}
			require_once $file;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Product_Consolidate_Page' );

		// Verify the class declares its own static $instance property.
		$this->assertTrue(
			$reflection->hasProperty( 'instance' ),
			'WP_MCP_AI_Product_Consolidate_Page should declare a static $instance property'
		);

		$prop = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );

		// Starts as null.
		$this->assertNull(
			$prop->getValue( null ),
			'$instance should be null before init()'
		);

		// After init() instance must be set.
		WP_MCP_AI_Product_Consolidate_Page::init();
		$instance_after_init = $prop->getValue( null );
		$this->assertNotNull(
			$instance_after_init,
			'$instance should be set after init()'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Product_Consolidate_Page',
			$instance_after_init,
			'$instance should be a WP_MCP_AI_Product_Consolidate_Page'
		);
	}

	/**
	 * Product consolidate page registers under the ecommerce toolkit menu.
	 */
	public function test_product_consolidate_page_menu_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'Product consolidate page class not available' );
		}

		$page_slug   = 'product-consolidate';
		$parent_slug = 'wp-mcp-ai-ecommerce-toolkit';
		$expected    = $parent_slug . '_page_' . $page_slug;

		$this->assertEquals(
			'product-consolidate',
			WP_MCP_AI_Product_Consolidate_Page::PAGE_SLUG,
			'PAGE_SLUG constant should match expected slug'
		);

		$this->assertEquals(
			'wp-mcp-ai-ecommerce-toolkit_page_product-consolidate',
			$expected,
			'Enqueue hook should follow the {parent}_page_{child} WordPress pattern'
		);
	}

	// ── Event Consolidate Page ──────────────────────────────────────────────────

	/**
	 * Event consolidate page class must exist.
	 */
	public function test_event_consolidate_page_class_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-consolidate-page.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Event_Consolidate_Page' ),
			'WP_MCP_AI_Event_Consolidate_Page class should exist'
		);
	}

	/**
	 * Event consolidate page uses singleton pattern.
	 */
	public function test_event_consolidate_singleton_pattern() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Event consolidate page file not found' );
		}
		require_once $file;

		$reflection = new ReflectionClass( 'WP_MCP_AI_Event_Consolidate_Page' );

		$this->assertTrue(
			$reflection->hasProperty( 'instance' ),
			'WP_MCP_AI_Event_Consolidate_Page should declare a static $instance property'
		);

		$prop = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );

		$this->assertNull( $prop->getValue( null ), '$instance should be null before init()' );

		WP_MCP_AI_Event_Consolidate_Page::init();
		$this->assertInstanceOf(
			'WP_MCP_AI_Event_Consolidate_Page',
			$prop->getValue( null ),
			'$instance should be a WP_MCP_AI_Event_Consolidate_Page after init()'
		);
	}

	/**
	 * Event consolidate page registers under the mcp_ai_event CPT menu.
	 */
	public function test_event_consolidate_registered_under_event_cpt_menu() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Event consolidate page file not found' );
		}
		require_once $file;

		WP_MCP_AI_Event_Consolidate_Page::init();
		do_action( 'admin_menu' );

		global $submenu;
		$parent_slug = 'edit.php?post_type=mcp_ai_event';
		$found       = false;

		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( isset( $item[2] ) && 'event-consolidate' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue(
			$found,
			'Event Consolidate & Add page should be registered under the mcp_ai_event CPT menu'
		);
	}

	/**
	 * project-management-init.php must initialize the event consolidate page.
	 */
	public function test_project_management_init_registers_event_consolidate_page() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$init_file = WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/init.php';
		if ( ! file_exists( $init_file ) ) {
			$this->markTestSkipped( 'project-management-init.php not found' );
		}

		// The file must reference the event consolidate page.
		$contents = file_get_contents( $init_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString(
			'class-wp-mcp-ai-event-consolidate-page.php',
			$contents,
			'project-management-init.php must require the event consolidate page file'
		);
		$this->assertStringContainsString(
			'WP_MCP_AI_Event_Consolidate_Page::init()',
			$contents,
			'project-management-init.php must call WP_MCP_AI_Event_Consolidate_Page::init()'
		);
	}

	// ── Media Consolidate Page ──────────────────────────────────────────────────

	/**
	 * Media consolidate page class must exist.
	 */
	public function test_media_consolidate_page_class_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-consolidate-page.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Media_Consolidate_Page' ),
			'WP_MCP_AI_Media_Consolidate_Page class should exist'
		);
	}

	/**
	 * Media consolidate page uses singleton pattern.
	 */
	public function test_media_consolidate_singleton_pattern() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Media consolidate page file not found' );
		}
		require_once $file;

		$reflection = new ReflectionClass( 'WP_MCP_AI_Media_Consolidate_Page' );

		$this->assertTrue(
			$reflection->hasProperty( 'instance' ),
			'WP_MCP_AI_Media_Consolidate_Page should declare a static $instance property'
		);

		$prop = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );

		$this->assertNull( $prop->getValue( null ), '$instance should be null before init()' );

		WP_MCP_AI_Media_Consolidate_Page::init();
		$this->assertInstanceOf(
			'WP_MCP_AI_Media_Consolidate_Page',
			$prop->getValue( null ),
			'$instance should be a WP_MCP_AI_Media_Consolidate_Page after init()'
		);
	}

	/**
	 * media-toolkit-init.php must initialize the media consolidate page.
	 */
	public function test_media_toolkit_init_registers_media_consolidate_page() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$init_file = WP_MCP_AI_PRO_PATH . 'includes/tools/media/init.php';
		if ( ! file_exists( $init_file ) ) {
			$this->markTestSkipped( 'media-toolkit-init.php not found' );
		}

		$contents = file_get_contents( $init_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString(
			'class-wp-mcp-ai-media-consolidate-page.php',
			$contents,
			'media-toolkit-init.php must require the media consolidate page file'
		);
		$this->assertStringContainsString(
			'WP_MCP_AI_Media_Consolidate_Page::init()',
			$contents,
			'media-toolkit-init.php must call WP_MCP_AI_Media_Consolidate_Page::init()'
		);
	}

	/**
	 * Media consolidate page hook format must follow the WordPress 'media_page_' pattern.
	 *
	 * Submenu pages under upload.php use 'media_page_SLUG' in the enqueue hook,
	 * NOT 'upload_page_SLUG'.
	 */
	public function test_media_consolidate_page_hook_uses_media_prefix() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Media consolidate page file not found' );
		}
		require_once $file;

		$page_slug     = WP_MCP_AI_Media_Consolidate_Page::PAGE_SLUG;
		$expected_hook = 'media_page_' . $page_slug;

		// Verify the contents of the enqueue_assets method use the correct hook.
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString(
			"'media_page_' . self::PAGE_SLUG",
			$contents,
			"enqueue_assets() must check for the 'media_page_' hook prefix (not 'upload_page_')"
		);

		// Verify the expected hook is built correctly.
		$this->assertEquals(
			'media_page_design-media',
			$expected_hook,
			'Expected hook for the media consolidate page should be media_page_design-media'
		);
	}

	// ── Health Records Consolidate Page — vitals write path ─────────────────────

	/**
	 * sanitize_vitals_post_data must delegate to the CCT class for the numeric
	 * field list so that adding a new field in one place propagates everywhere.
	 */
	public function test_health_consolidate_sanitize_delegates_to_cct_class() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Health records consolidate page file not found' );
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// The sanitize function must use the CCT class rather than a hardcoded list.
		$this->assertStringContainsString(
			'WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_numeric_vital_fields()',
			$contents,
			'sanitize_vitals_post_data() must delegate to WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_numeric_vital_fields() for the field list'
		);
	}

	/**
	 * handle_import_vitals_to_cct must call upsert() not insert().
	 *
	 * PR #4197 changed all three vitals write paths to use upsert() for
	 * same-day consolidation and near-duplicate suppression.
	 */
	public function test_health_consolidate_uses_upsert_not_insert() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Health records consolidate page file not found' );
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString(
			'WP_MCP_AI_JetEngine_Vitals_Log_CCT::upsert(',
			$contents,
			'handle_import_vitals_to_cct() must call upsert() for same-day consolidation'
		);
	}

	/**
	 * handle_import_vitals_to_cct must set the audit trail fields.
	 *
	 * logged_at, logged_by, and entry_id must always be stamped server-side.
	 */
	public function test_health_consolidate_stamps_audit_fields() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Health records consolidate page file not found' );
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString(
			"'logged_at'",
			$contents,
			"handle_import_vitals_to_cct() must set 'logged_at'"
		);
		$this->assertStringContainsString(
			"'entry_id'",
			$contents,
			"handle_import_vitals_to_cct() must set 'entry_id'"
		);
		$this->assertStringContainsString(
			"'logged_by'",
			$contents,
			"handle_import_vitals_to_cct() must set 'logged_by'"
		);
	}
}
