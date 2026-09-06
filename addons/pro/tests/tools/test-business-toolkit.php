<?php
/**
 * Batch test for Calendar Booking + Google Workspace + FlowHub Pro tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group business
 */
class Test_WP_MCP_AI_Business_Toolkit extends WP_UnitTestCase {
	/** @var int */ protected $user_id;
	/** @var array */ protected static $dirs = array( 'calendar-booking', 'google-workspace', 'flowhub' );

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro not loaded.' ); }
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown(); }

	/** @return array */
	public static function provide_all_tools() {
		$data = array();
		foreach ( self::$dirs as $dir ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			if ( ! is_dir( $path ) ) {
				continue; }
			foreach ( glob( $path . 'class-*.php' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = file_get_contents( $file );
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $content, $m ) ) {
					$data[ $m[1] ] = array( $m[1], $file );
				}
			}
		}
		return $data;
	}

	/**
	 * @dataProvider provide_all_tools
	 * @param string $class_name Tool class.
	 * @param string $file       Tool file.
	 */
	public function test_class_exists( $class_name, $file ) {
		if ( ! class_exists( $class_name ) ) {
			require_once $file; }
		$this->assertTrue( class_exists( $class_name ) );
	}

	/**
	 * @dataProvider provide_all_tools
	 * @param string $class_name Tool class.
	 * @param string $file       Tool file.
	 */
	public function test_metadata( $class_name, $file ) {
		if ( ! class_exists( $class_name ) ) {
			require_once $file; }
		$tool = $this->safe_new( $class_name );
		if ( ! $tool ) {
			return; }
		if ( ! method_exists( $tool, 'get_slug' ) ) {
			$this->markTestSkipped( $class_name . ' is a helper class, not a tool.' );
			return; }
		$this->assertNotEmpty( $tool->get_slug() );
	}

	/**
	 * @dataProvider provide_all_tools
	 * @param string $class_name Tool class.
	 * @param string $file       Tool file.
	 */
	public function test_schema( $class_name, $file ) {
		if ( ! class_exists( $class_name ) ) {
			require_once $file; }
		$tool = $this->safe_new( $class_name );
		if ( ! $tool ) {
			return; }
		if ( ! method_exists( $tool, 'get_slug' ) ) {
			$this->markTestSkipped( $class_name . ' is a helper class, not a tool.' );
			return; }
		$schema = method_exists( $tool, 'get_parameters_schema' ) ? $tool->get_parameters_schema() : null;
		if ( ! $schema && method_exists( $tool, 'get_definition' ) ) {
			$def    = $tool->get_definition();
			$schema = isset( $def['parameters'] ) ? $def['parameters'] : ( isset( $def['input_schema'] ) ? $def['input_schema'] : ( isset( $def['arguments'] ) ? $def['arguments'] : null ) );
		}
		if ( ! $schema ) {
			$this->markTestSkipped( $class_name . ' exposes no parameter schema.' );
			return; }
		$this->assertIsArray( $schema );
	}

	/** @param string $class_name @return object|null */
	protected function safe_new( $class_name ) {
		try {
			$r = new ReflectionClass( $class_name );
			if ( $r->isAbstract() || $r->isInterface() ) {
				$this->markTestSkipped();
				return null; }
			$ctor = $r->getConstructor();
			if ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) {
				$this->markTestSkipped();
				return null; }
			return $r->newInstance();
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped();
			return null; }
	}
}
