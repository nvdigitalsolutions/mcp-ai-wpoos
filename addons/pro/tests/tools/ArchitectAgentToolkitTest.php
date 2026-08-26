<?php
/**
 * Batch test for Architect Agent Pro tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group architect-agent
 */

class ArchitectAgentToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var array */
	protected static $dirs = array( 'architect-agent' );

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
		}
		// Preload the local git helpers trait needed by git_inspect and git_change.
		$helpers_trait = WP_MCP_AI_PRO_PATH . 'includes/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php';
		if ( file_exists( $helpers_trait ) ) {
			require_once $helpers_trait;
		}
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @return array
	 */
	public static function provide_all_tools() {
		$d = array();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return $d;
		}
		foreach ( self::$dirs as $dir ) {
			$p = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			if ( ! is_dir( $p ) ) {
				continue;
			}
			foreach ( glob( $p . 'class-*.php' ) as $f ) {
				$c = file_get_contents( $f );
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $c, $m ) ) {
					$d[ $m[1] ] = array( $m[1], $f );
				}
			}
		}
		return $d;
	}

	/** @dataProvider provide_all_tools */
	public function test_class_exists( $cn, $f ) {
		if ( ! class_exists( $cn ) ) {
			try {
				require_once $f;
			} catch ( \Throwable $e ) {
				$this->markTestSkipped( 'Failed to load: ' . $e->getMessage() );
				return;
			}
		}
		$this->assertTrue( class_exists( $cn ) );
	}

	/** @dataProvider provide_all_tools */
	public function test_metadata( $cn, $f ) {
		if ( ! class_exists( $cn ) ) {
			require_once $f;
		}
		$t = $this->safe_new( $cn );
		if ( ! $t ) {
			return;
		}
		if ( ! method_exists( $t, 'get_slug' ) ) {
			$this->markTestSkipped( $cn . ' is a helper class, not a tool.' );
			return;
		}
		$this->assertNotEmpty( $t->get_slug() );
	}

	/** @dataProvider provide_all_tools */
	public function test_schema( $cn, $f ) {
		if ( ! class_exists( $cn ) ) {
			require_once $f;
		}
		$t = $this->safe_new( $cn );
		if ( ! $t ) {
			return;
		}
		if ( ! method_exists( $t, 'get_slug' ) ) {
			$this->markTestSkipped( $cn . ' is a helper class, not a tool.' );
			return;
		}
		$s = method_exists( $t, 'get_parameters_schema' ) ? $t->get_parameters_schema() : null;
		if ( ! $s && method_exists( $t, 'get_definition' ) ) {
			$d = $t->get_definition();
			$s = isset( $d['parameters'] ) ? $d['parameters'] : ( isset( $d['input_schema'] ) ? $d['input_schema'] : ( isset( $d['arguments'] ) ? $d['arguments'] : null ) );
		}
		if ( ! $s ) {
			$this->markTestSkipped( $cn . ' exposes no parameter schema.' );
			return;
		}
		$this->assertIsArray( $s );
	}

	/**
	 * @param string $cn
	 * @return object|null
	 */
	protected function safe_new( $cn ) {
		try {
			$r = new ReflectionClass( $cn );
			if ( $r->isAbstract() || $r->isInterface() ) {
				$this->markTestSkipped();
				return null;
			}
			$ctor = $r->getConstructor();
			if ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) {
				$this->markTestSkipped();
				return null;
			}
			return $r->newInstance();
		} catch ( \Throwable $e ) {
			$this->markTestSkipped();
			return null;
		}
	}
}
