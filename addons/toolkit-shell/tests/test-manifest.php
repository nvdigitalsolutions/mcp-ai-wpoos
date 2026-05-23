<?php
/**
 * Toolkit Shell — Manifest registry tests.
 *
 * @package NV_oOS_Toolkit_Shell
 */

/**
 * Tests for NV_oOS_Toolkit_Shell_Manifest_Registry.
 */
class Test_Toolkit_Shell_Manifest extends WP_UnitTestCase {

	/**
	 * Bootstrap addon constants and require the registry class.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_VERSION' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_VERSION', '0.2.0' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_PATH' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_PATH', dirname( __DIR__ ) . '/' );
		}
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/class-nvoos-toolkit-shell-manifest-registry.php';
		NV_oOS_Toolkit_Shell_Manifest_Registry::reset_cache();
	}

	/**
	 * Sanitizer accepts a minimal valid manifest.
	 *
	 * @return void
	 */
	public function test_sanitize_accepts_minimal_manifest() {
		$raw = array(
			'version'        => '1.0',
			'toolkit'        => 'crm',
			'rest_namespace' => 'mcp-ai-pro/v1',
			'capability'     => 'edit_posts',
			'resources'      => array(
				array(
					'name'     => 'contacts',
					'endpoint' => '/crm/contacts',
					'fields'   => array(
						array(
							'name' => 'id',
							'type' => 'integer',
						),
						array(
							'name' => 'full_name',
							'type' => 'string',
						),
					),
				),
			),
		);
		$out = NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw );
		$this->assertIsArray( $out );
		$this->assertSame( 'crm', $out['toolkit'] );
		$this->assertCount( 1, $out['resources'] );
		$this->assertSame( 'contacts', $out['resources'][0]['name'] );
		$this->assertSame( '/crm/contacts', $out['resources'][0]['endpoint'] );
	}

	/**
	 * Sanitizer rejects manifests with no resources.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_empty_resources() {
		$raw = array(
			'version'   => '1.0',
			'toolkit'   => 'empty',
			'resources' => array(),
		);
		$this->assertNull( NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw ) );
	}

	/**
	 * Sanitizer rejects manifests without a toolkit slug.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_missing_toolkit() {
		$raw = array(
			'version'   => '1.0',
			'resources' => array(
				array(
					'name' => 'x',
					'fields' => array( array( 'name' => 'id' ) ),
				),
			),
		);
		$this->assertNull( NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw ) );
	}

	/**
	 * Unknown rest_namespace is replaced with the safe default.
	 *
	 * @return void
	 */
	public function test_sanitize_clamps_rest_namespace() {
		$raw = array(
			'toolkit'        => 'crm',
			'rest_namespace' => 'http://evil.example.com/path',
			'resources'      => array(
				array(
					'name' => 'r',
					'fields' => array( array( 'name' => 'id' ) ),
				),
			),
		);
		$out = NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw );
		$this->assertSame( 'mcp-ai-pro/v1', $out['rest_namespace'] );
	}

	/**
	 * Unknown field types fall back to "string".
	 *
	 * @return void
	 */
	public function test_sanitize_clamps_field_type() {
		$raw = array(
			'toolkit'   => 'crm',
			'resources' => array(
				array(
					'name'   => 'r',
					'fields' => array(
						array(
							'name' => 'x',
							'type' => 'arbitrary-evil-type',
						),
					),
				),
			),
		);
		$out = NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw );
		$this->assertSame( 'string', $out['resources'][0]['fields'][0]['type'] );
	}

	/**
	 * Unknown view types fall back to "table".
	 *
	 * @return void
	 */
	public function test_sanitize_clamps_view_type() {
		$raw = array(
			'toolkit'   => 'crm',
			'resources' => array(
				array(
					'name' => 'r',
					'fields' => array( array( 'name' => 'id' ) ),
				),
			),
			'views'     => array(
				array(
					'name' => 'v',
					'type' => 'rocket',
					'resource' => 'r',
				),
			),
		);
		$out = NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest( $raw );
		$this->assertSame( 'table', $out['views'][0]['type'] );
	}

	/**
	 * The bundled example manifest loads successfully via get_all().
	 *
	 * @return void
	 */
	public function test_bundled_example_manifest_loads() {
		NV_oOS_Toolkit_Shell_Manifest_Registry::reset_cache();
		$all = NV_oOS_Toolkit_Shell_Manifest_Registry::get_all( true );
		$this->assertArrayHasKey( 'example', $all );
		$this->assertSame( 'example', $all['example']['toolkit'] );
		$this->assertNotEmpty( $all['example']['resources'] );
	}
}
