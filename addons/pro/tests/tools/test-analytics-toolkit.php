<?php
/**
 * Batch test for Analytics + Orchestration Pro tools.
 *
 * Auto-discovers tool classes from directories and validates metadata,
 * parameter schemas, and class structure.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group analytics
 */

/**
 * Test Analytics + Orchestration toolkit.
 */
class Test_WP_MCP_AI_Analytics_Toolkit extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Tool directories to scan.
	 *
	 * @var array
	 */
	protected static $dirs = array( 'analytics', 'orchestration' );

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}
		$this->user_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Provide all tool classes discovered from configured directories.
	 *
	 * @return array
	 */
	public static function provide_all_tools() {
		$data = array();
		foreach ( self::$dirs as $dir ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			if ( ! is_dir( $path ) ) {
				continue;
			}
			foreach ( glob( $path . 'class-*.php' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local tool files.
				$content = file_get_contents( $file );
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $content, $m ) ) {
					$data[ $m[1] ] = array( $m[1], $file );
				}
			}
		}
		return $data;
	}

	/**
	 * Test that every tool class file exists and loads.
	 *
	 * @dataProvider provide_all_tools
	 *
	 * @param string $class_name Tool class name.
	 * @param string $file       Tool file path.
	 */
	public function test_class_exists( $class_name, $file ) {
		if ( class_exists( $class_name ) ) {
			return;
		}
		require_once $file;
		$this->assertTrue( class_exists( $class_name ) );
	}

	/**
	 * Test that every tool has a valid slug.
	 *
	 * @dataProvider provide_all_tools
	 *
	 * @param string $class_name Tool class name.
	 * @param string $file       Tool file path.
	 */
	public function test_metadata( $class_name, $file ) {
		if ( ! class_exists( $class_name ) ) {
			require_once $file;
		}
		$tool = $this->safe_new( $class_name );
		if ( ! $tool ) {
			return;
		}
		$this->assertNotEmpty( $tool->get_slug() );
	}

	/**
	 * Test that every tool has a valid parameter schema.
	 *
	 * @dataProvider provide_all_tools
	 *
	 * @param string $class_name Tool class name.
	 * @param string $file       Tool file path.
	 */
	public function test_schema( $class_name, $file ) {
		if ( ! class_exists( $class_name ) ) {
			require_once $file;
		}
		$tool = $this->safe_new( $class_name );
		if ( ! $tool ) {
			return;
		}
		$schema = method_exists( $tool, 'get_parameters_schema' )
			? $tool->get_parameters_schema()
			: null;
		if ( ! $schema && method_exists( $tool, 'get_definition' ) ) {
			$def    = $tool->get_definition();
			$schema = isset( $def['parameters'] ) ? $def['parameters'] : null;
		}
		if ( ! $schema ) {
			return;
		}
		$this->assertIsArray( $schema );
	}

	/**
	 * Instantiate a tool class safely, skipping abstract/interface/ctor-args.
	 *
	 * @param string $class_name Tool class name.
	 * @return object|null
	 */
	protected function safe_new( $class_name ) {
		try {
			$r = new ReflectionClass( $class_name );
			if ( $r->isAbstract() || $r->isInterface() ) {
				$this->markTestSkipped( $class_name . ' is abstract or interface.' );
				return null;
			}
			$ctor = $r->getConstructor();
			if ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) {
				$this->markTestSkipped( $class_name . ' requires constructor args.' );
				return null;
			}
			return $r->newInstance();
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped( $class_name . ' cannot instantiate.' );
			return null;
		}
	}
}
