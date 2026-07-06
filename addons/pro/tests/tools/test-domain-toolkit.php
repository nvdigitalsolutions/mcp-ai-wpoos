<?php
/**
 * Batch test for Domain-specific Pro tools (places, capture, cre-debt, erp-ezuite, law-firm, multilingual, paper-store).
 *
 * @package WP_MCP_AI_Pro @subpackage Tests @group tools @group pro @group domain
 */
class Test_WP_MCP_AI_Domain_Toolkit extends WP_UnitTestCase {
	/** @var int */ protected $user_id;
	/** @var array */ protected static $dirs = array( 'places', 'capture', 'cre-debt', 'erp-ezuite', 'law-firm', 'multilingual', 'paper-store' );
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
		} $this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id ); }
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown(); }
	/** @return array */ public static function provide_all_tools() {
		$d = array();
		foreach ( self::$dirs as $dir ) {
			$p = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			if ( ! is_dir( $p ) ) {
				continue;
			} foreach ( glob( $p . 'class-*.php' ) as $f ) {
				$c = file_get_contents( $f );
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $c, $m ) ) {
					$d[ $m[1] ] = array( $m[1], $f );
				}
			}
		} return $d; }
	/** @dataProvider provide_all_tools */ public function test_class_exists( $cn, $f ) {
		if ( class_exists( $cn ) ) {
			return;
		} require_once $f;
		$this->assertTrue( class_exists( $cn ) ); }
	/** @dataProvider provide_all_tools */ public function test_metadata( $cn, $f ) {
		if ( ! class_exists( $cn ) ) {
			require_once $f;
		} $t = $this->safe_new( $cn );
		if ( ! $t ) {
			return;
		} $this->assertNotEmpty( $t->get_slug() ); }
	/** @dataProvider provide_all_tools */ public function test_schema( $cn, $f ) {
		if ( ! class_exists( $cn ) ) {
			require_once $f;
		} $t = $this->safe_new( $cn );
		if ( ! $t ) {
			return;
		} $s = method_exists( $t, 'get_parameters_schema' ) ? $t->get_parameters_schema() : null;
		if ( ! $s && method_exists( $t, 'get_definition' ) ) {
			$d = $t->get_definition();
			$s = isset( $d['parameters'] ) ? $d['parameters'] : null;
		} if ( ! $s ) {
			return;
		} $this->assertIsArray( $s ); }
	/** @param string $cn @return object|null */ protected function safe_new( $cn ) {
		try {
			$r = new ReflectionClass( $cn );
			if ( $r->isAbstract() || $r->isInterface() ) {
				$this->markTestSkipped();
				return null;
			} $ctor = $r->getConstructor();
			if ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) {
				$this->markTestSkipped();
				return null;
			} return $r->newInstance();
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped();
			return null; } }
}
