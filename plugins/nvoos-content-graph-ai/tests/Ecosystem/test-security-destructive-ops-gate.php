<?php
/**
 * Destructive operations gate port tests (Wave D4i).
 *
 * Characterization suite for `DestructiveOpsGate` and the
 * `DestructiveConfirmationRequired` exception. Assertions mirror the
 * base plugin's destructive ops gate: enable/disable, destructive flag
 * vocabulary, confirmation value handling, preview payload shape, the
 * rejection action, audit-log insertion, filterable flags, and the
 * exception's 428 WP_Error envelope. The audit table is created with
 * real DDL — the WP framework's TEMPORARY-table rewrite is suspended in
 * setUp and restored in tearDown.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\DestructiveOpsGate;
use NvoosContentGraphAi\Security\Exceptions\DestructiveConfirmationRequired;
use NvoosContentGraphAi\Security\SecurityAuditLogger;

/**
 * Testable gate that duck-types capability flags in both install modes.
 */
class Testable_Destructive_Gate extends DestructiveOpsGate {

	/**
	 * Read capability flags from any object exposing them.
	 *
	 * @param object $tool Tool instance.
	 * @return array
	 */
	protected static function get_tool_flags( $tool ) {
		if ( is_object( $tool ) && method_exists( $tool, 'get_capability_flags' ) ) {
			return (array) $tool->get_capability_flags();
		}
		return array();
	}
}

/**
 * @group security
 */
class Test_Destructive_Ops_Gate extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Allow real DDL on the audit table (gate rejections audit-log).
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_destructive_gate_rejected' );
		\remove_all_actions( 'wp_mcp_ai_security_event' );

		\delete_option( 'wp_mcp_ai_security_log_table_version' );
		\delete_option( 'nvoos_content_graph_settings' );

		// Prime the gate to enabled — the monolith repository cache
		// persists across tests within this process.
		$this->set_gate_enabled( true );
	}

	public function tearDown(): void {
		global $wpdb;

		$table = $wpdb->prefix . SecurityAuditLogger::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test teardown for custom table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		\add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_filters( 'wp_mcp_ai_destructive_confirmation_flags' );
		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_destructive_gate_rejected' );
		\remove_all_actions( 'wp_mcp_ai_security_event' );

		\delete_option( 'wp_mcp_ai_security_log_table_version' );
		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_require_confirm_destructive_ops' );

		parent::tearDown();
	}

	/**
	 * Configure the gate setting in the active settings store.
	 *
	 * Note: the monolith per-key repository cannot persist a bare `false`
	 * value (update_option treats it as a no-op when the option is
	 * missing) — write 0/1 instead.
	 *
	 * @param bool $enabled Whether the gate should be enabled.
	 * @return void
	 */
	private function set_gate_enabled( bool $enabled ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\wp_mcp_ai_get_settings_repository()->update( 'require_confirm_destructive_ops', $enabled ? 1 : 0 );
			return;
		}

		\update_option( 'nvoos_content_graph_settings', array( 'require_confirm_destructive_ops' => $enabled ) );
	}

	/**
	 * Build a mock tool with the given flags.
	 *
	 * @param array $flags Capability flags.
	 * @return object
	 */
	private function mock_tool( array $flags ) {
		return new class( $flags ) {
			private $flags;

			public function __construct( array $flags ) {
				$this->flags = $flags;
			}

			public function get_capability_flags() {
				return $this->flags;
			}

			public function get_name() {
				return 'Delete Everything Tool';
			}

			public function get_description() {
				return 'Deletes everything.';
			}
		};
	}

	public function test_destructive_tool_without_confirmation_throws(): void {
		$tool    = $this->mock_tool( array( 'write', 'destructive' ) );
		$payload = null;

		\add_action(
			'wp_mcp_ai_destructive_gate_rejected',
			static function ( $tool_slug, $gate_payload ) use ( &$payload ): void {
				$payload = $gate_payload;
			},
			10,
			2
		);

		try {
			Testable_Destructive_Gate::on_before_tool_execution( 'delete_everything', array(), array(), $tool );
			$this->fail( 'Expected DestructiveConfirmationRequired to be thrown.' );
		} catch ( DestructiveConfirmationRequired $e ) {
			$this->assertSame( 'delete_everything', $e->get_tool_slug() );

			$gate_payload = $e->get_payload();
			$this->assertSame( 'delete_everything', $gate_payload['tool_slug'] );
			$this->assertSame( 'Delete Everything Tool', $gate_payload['tool_name'] );
			$this->assertContains( 'write', $gate_payload['flags'] );
			$this->assertSame( 'confirm_destructive', $gate_payload['preview']['confirmation']['required_parameter'] );
			$this->assertSame( array(), $gate_payload['preview']['arguments'] );

			$error = $e->to_wp_error();
			$this->assertSame( 'wp_mcp_ai_destructive_confirmation_required', $error->get_error_code() );
			$this->assertSame( 428, $error->get_error_data()['status'] );
			$this->assertSame( 'delete_everything', $error->get_error_data()['tool_slug'] );
		}

		$this->assertIsArray( $payload );
		$this->assertSame( 'delete_everything', $payload['tool_slug'] );
	}

	public function test_rejection_is_audit_logged(): void {
		$tool = $this->mock_tool( array( 'irreversible' ) );

		try {
			Testable_Destructive_Gate::on_before_tool_execution( 'nuke', array(), array(), $tool );
			$this->fail( 'Expected rejection.' );
		} catch ( DestructiveConfirmationRequired $e ) {
			unset( $e );
		}

		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );
		$response = SecurityAuditLogger::get_events( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( SecurityAuditLogger::EVENT_DESTRUCTIVE_OP_DENIED, $data['events'][0]['event_type'] );
		$this->assertSame( array( 'tool_slug' => 'nuke' ), $data['events'][0]['details'] );
	}

	public function test_confirmation_values_allow_execution(): void {
		$tool = $this->mock_tool( array( 'write' ) );

		foreach ( array( true, 'true', 1, '1', 'yes' ) as $confirm ) {
			// No exception means the gate passed.
			Testable_Destructive_Gate::on_before_tool_execution(
				'delete_everything',
				array( 'confirm_destructive' => $confirm ),
				array(),
				$tool
			);
			$this->assertTrue( true );
		}
	}

	public function test_non_destructive_tool_passes(): void {
		$tool = $this->mock_tool( array( 'read' ) );

		Testable_Destructive_Gate::on_before_tool_execution( 'read_tool', array(), array(), $tool );
		$this->assertTrue( true );
	}

	public function test_disabled_gate_skips_destructive_tools(): void {
		$this->set_gate_enabled( false );

		$tool = $this->mock_tool( array( 'write', 'destructive' ) );

		Testable_Destructive_Gate::on_before_tool_execution( 'delete_everything', array(), array(), $tool );
		$this->assertTrue( true );
	}

	public function test_destructive_flags_filter_is_honoured(): void {
		\add_filter(
			'wp_mcp_ai_destructive_confirmation_flags',
			static function ( $flags ) {
				$flags[] = 'dangerous';
				return $flags;
			}
		);

		$tool = $this->mock_tool( array( 'dangerous' ) );

		try {
			Testable_Destructive_Gate::on_before_tool_execution( 'risky_tool', array(), array(), $tool );
			$this->fail( 'Expected rejection for the filtered flag.' );
		} catch ( DestructiveConfirmationRequired $e ) {
			$this->assertSame( 'risky_tool', $e->get_tool_slug() );
		}
	}

	public function test_unknown_tool_is_skipped(): void {
		// No tool instance passed and no container resolution → no-op.
		DestructiveOpsGate::on_before_tool_execution( 'ghost_tool', array(), array(), null );
		$this->assertTrue( true );
	}

	public function test_real_gate_seam_respects_interface_mode(): void {
		$tool = $this->mock_tool( array( 'write' ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: flags only resolve via the base capability-flags
			// interface — the duck-typed mock carries no flags → no throw.
			DestructiveOpsGate::on_before_tool_execution( 'delete_everything', array(), array(), $tool );
			$this->assertTrue( true );
		} else {
			// Standalone: duck-typing resolves the mock's flags → throws.
			try {
				DestructiveOpsGate::on_before_tool_execution( 'delete_everything', array(), array(), $tool );
				$this->fail( 'Expected rejection in standalone mode.' );
			} catch ( DestructiveConfirmationRequired $e ) {
				$this->assertSame( 'delete_everything', $e->get_tool_slug() );
			}
		}
	}
}
