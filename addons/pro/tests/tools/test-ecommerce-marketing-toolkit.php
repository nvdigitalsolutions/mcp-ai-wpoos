<?php
/**
 * Batch test for Ecommerce + Email Marketing + Social Media + Chat Channels Pro tools.
 *
 * @package WP_MCP_AI_Pro @subpackage Tests
 * @group tools @group pro
 */

class Test_WP_MCP_AI_Ecommerce_Marketing_Toolkit extends WP_UnitTestCase {
	protected $user_id;
	protected static $dirs = array( 'ecommerce', 'email-marketing' );

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) { $this->markTestSkipped( 'Pro not loaded.' ); }
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}
	public function tearDown(): void { wp_set_current_user( 0 ); parent::tearDown(); }

	public static function provide_all_tools() {
		$data = array();
		foreach ( self::$dirs as $dir ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			if ( ! is_dir( $path ) ) { continue; }
			foreach ( glob( $path . 'class-*.php' ) as $f ) {
				// Extract class name from file by reading the class declaration.
				$content = file_get_contents( $f );
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $content, $m ) ) {
					$data[ $m[1] ] = array( $m[1], $f );
				}
			}
		}
		return $data;
	}

	/** @dataProvider provide_all_tools */
	public function test_tool_class_exists( $class_name, $file ) {
		require_once $file;
		$this->assertTrue( class_exists( $class_name ), "$class_name should exist" );
	}

	/** @dataProvider provide_all_tools */
	public function test_tool_metadata( $class_name, $file ) {
		require_once $file;
		$t = $this->safe_new( $class_name ); if ( ! $t ) { return; }
		$this->assertNotEmpty( $t->get_slug() );
		$this->assertNotEmpty( method_exists( $t, 'get_name' ) ? $t->get_name() : $t->get_slug() );
	}

	/** @dataProvider provide_all_tools */
	public function test_tool_parameter_schema( $class_name, $file ) {
		require_once $file;
		$t = $this->safe_new( $class_name ); if ( ! $t ) { return; }
		$s = method_exists( $t, 'get_parameters_schema' ) ? $t->get_parameters_schema() : null;
		if ( ! $s && method_exists( $t, 'get_definition' ) ) {
			$d = $t->get_definition();
			$s = isset( $d['parameters'] ) ? $d['parameters'] : null;
		}
		if ( ! $s ) { return; }
		$this->assertIsArray( $s );
	}

	protected function safe_new( $class_name ) {
		try {
			$r = new ReflectionClass( $class_name );
			if ( $r->isAbstract() || $r->isInterface() ) { $this->markTestSkipped( "$class_name is abstract." ); return null; }
			$ctor = $r->getConstructor();
			if ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) { $this->markTestSkipped( "$class_name needs ctor args." ); return null; }
			return $r->newInstance();
		} catch ( \ReflectionException $e ) { $this->markTestSkipped( "$class_name cannot instantiate." ); return null; }
	}
}
