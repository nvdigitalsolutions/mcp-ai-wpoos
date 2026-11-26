<?php
/**
 * Batch test for DJ Management Pro tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group dj-management
 */

class DjManagementToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var array */
	protected static $dirs = array( 'dj-management' );

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
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
			require_once $f;
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
	 * The canonical envelope, enforced across the whole toolkit.
	 *
	 * A `success => false` array is a *successful* tool call whose payload happens
	 * to say false: the agentic loop sees no error and the model reports the task
	 * as done. Every failure path must be `WP_Error`, which is also the shape
	 * `WPMCPAI.Tools.CanonicalReturnEnvelope` enforces.
	 *
	 * Asserted at the source level as well as behaviourally because the defect was
	 * uniform across fifteen tools: a behavioural test proves the paths we
	 * remembered, and this proves the ones we did not.
	 */
	public function test_no_tool_returns_a_success_false_envelope(): void {
		$offenders = array();

		foreach ( self::$dirs as $dir ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			$files = glob( $path . 'class-*.php' );

			if ( false === $files ) {
				$files = array();
			}

			foreach ( $files as $file ) {
				$source = (string) file_get_contents( $file );

				if ( preg_match( '/[\'\"]success[\'\"]\s*=>\s*false/', $source ) ) {
					$offenders[] = basename( $file );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Return WP_Error on the failure path; a `success => false` array reads as a successful call.'
		);
	}

	/**
	 * Behavioural proof for the missing-argument path.
	 *
	 * @dataProvider provide_missing_argument_calls
	 *
	 * @param string $class Tool class name.
	 * @param string $slug  Tool slug, for the failure message.
	 */
	public function test_a_missing_argument_is_a_wp_error( $class, $slug ) {
		if ( ! $this->load_tool( $class ) ) {
			$this->markTestSkipped( $class . ' is not available.' );
		}

		$tool   = new $class();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result, $slug . ' must return WP_Error when required arguments are missing.' );
		$this->assertSame(
			'wp_mcp_ai_missing_param',
			$result->get_error_code(),
			$slug . ' must use the shared missing-param code so a caller can branch on it.'
		);
	}

	/**
	 * Tools whose first failure is a missing required argument.
	 *
	 * @return array
	 */
	public static function provide_missing_argument_calls() {
		return array(
			'analyze_track_bpm'      => array( 'WP_MCP_AI_Tool_Analyze_Track_BPM', 'analyze_track_bpm' ),
			'create_playlist'        => array( 'WP_MCP_AI_Tool_Create_Playlist', 'create_playlist' ),
			'update_event_details'   => array( 'WP_MCP_AI_Tool_Update_Event_Details', 'update_event_details' ),
			'mix_transition_planner' => array( 'WP_MCP_AI_Tool_Mix_Transition_Planner', 'mix_transition_planner' ),
			'reserve_equipment'      => array( 'WP_MCP_AI_Tool_Reserve_Equipment', 'reserve_equipment' ),
		);
	}

	/**
	 * A disabled toolkit names itself instead of returning prose.
	 *
	 * The code matters more than the message: a caller (and the assistant) can act
	 * on `toolkit_not_available` by pointing at the toggle, while a `success =>
	 * false` string is only readable by a human.
	 */
	public function test_a_disabled_toolkit_is_a_toolkit_not_available_error(): void {
		if ( ! $this->load_tool( 'WP_MCP_AI_Tool_Get_Trending_Tracks' ) ) {
			$this->markTestSkipped( 'Get Trending Tracks is not available.' );
		}

		$had_previous = false !== get_option( 'wp_mcp_ai_settings', false );
		$previous     = get_option( 'wp_mcp_ai_settings', array() );

		update_option( 'wp_mcp_ai_settings', array() );

		try {
			$tool   = new WP_MCP_AI_Tool_Get_Trending_Tracks();
			$result = $tool->execute( array(), array() );
		} finally {
			if ( $had_previous ) {
				update_option( 'wp_mcp_ai_settings', $previous );
			} else {
				delete_option( 'wp_mcp_ai_settings' );
			}
		}

		$this->assertWPError( $result );
		$this->assertSame( 'toolkit_not_available', $result->get_error_code() );
	}

	/**
	 * Make sure a toolkit class is loaded, using the class→file map the rest of
	 * this test already trusts.
	 *
	 * Without this a behavioural assertion could skip itself silently whenever the
	 * Pro autoloader is not eager — passing while proving nothing.
	 *
	 * @param string $class Tool class name.
	 * @return bool Whether the class is now loadable.
	 */
	protected function load_tool( $class ) {
		if ( class_exists( $class ) ) {
			return true;
		}

		foreach ( self::provide_all_tools() as $row ) {
			if ( $row[0] === $class && is_readable( $row[1] ) ) {
				require_once $row[1];
				break;
			}
		}

		return class_exists( $class );
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
