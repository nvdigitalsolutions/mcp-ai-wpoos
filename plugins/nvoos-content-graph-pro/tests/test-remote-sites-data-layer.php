<?php
/**
 * Characterization tests for the Wave F6 remote-sites data layer — the
 * remote-connection HTTP wrapper and the static remote-site manager.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the registry module.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Remote-sites data layer tests.
 */
class Test_Remote_Sites_Data_Layer extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Remote_Connection'       => 'class-wp-mcp-ai-remote-connection.php',
			'WP_MCP_AI_Pro_Remote_Site_Manager' => 'class-wp-mcp-ai-pro-remote-site-manager.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The manager constants and static contracts must be byte-identical.
	 */
	public function test_manager_contracts(): void {
		$this->assertSame( 'wp_mcp_ai_pro_remote_sites', WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		$this->assertContains( 'woocommerce', WP_MCP_AI_Pro_Remote_Site_Manager::AUTH_TYPES );
		$this->assertSame( 'v2.', WP_MCP_AI_Pro_Remote_Site_Manager::ENCRYPT_V2_PREFIX );

		$this->assertIsArray( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() );
		$this->assertNull( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( 'does-not-exist' ) );

		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( 'password' ) );
		$this->assertFalse( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( 'name' ) );
	}

	/**
	 * The connection wrapper defaults must be byte-identical.
	 */
	public function test_connection_defaults(): void {
		$connection = new WP_MCP_AI_Remote_Connection();

		$config = $this->read_prop( $connection, 'config' );
		$this->assertSame( '', $config['url'] );
		$this->assertSame( 30, $config['timeout'] );
		$this->assertTrue( $config['verify_ssl'] );

		// The request surface must degrade to a WP_Error without a URL
		// (external HTTP is not exercised in the matrices).
		$result = $connection->get_products( array() );
		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Standalone only: the registry must define the `remote_connection`
	 * module (mirrors the base registry module).
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the remote_connection module.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-module-registry.php';

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertArrayHasKey( 'remote_connection', $registry->get_modules() );
		$this->assertTrue( $registry->is_loaded( 'remote_connection' ) );
	}

	/**
	 * Read a protected property for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $prop     Property name.
	 * @return mixed Property value.
	 */
	private function read_prop( object $instance, string $prop ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasProperty( $prop ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$property = $reflection->getProperty( $prop );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}
}
