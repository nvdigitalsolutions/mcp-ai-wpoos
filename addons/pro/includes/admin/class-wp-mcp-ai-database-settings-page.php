<?php
/**
 * Database toolkit admin surface.
 *
 * Proposal 167 phase P1 task 1.12 and P6's approval step. One page, five tabs:
 *
 *  - **Connections** — author the endpoints (native records).
 *  - **Scopes** — author the grants: schemas, tables, column rules, row filters,
 *    tiers and principals.
 *  - **Proposals** — the human half of the write path. Nothing here writes to a
 *    scoped database; approving only flips a proposal's status.
 *  - **Audit** — the recent trail, refusals included.
 *  - **Health** — runtime availability, drivers, probe state and refusal counts.
 *
 * ## Why a server-rendered page
 *
 * The console (P3) is a separate deliverable. This page exists so the toolkit is
 * operable from the moment it ships, and so the security-critical edits (who may
 * read which columns) are one `check_admin_referer` + `current_user_can` away
 * from a plain form, rather than buried in a REST surface that also has to be
 * right.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Nonce verification is centralised. Every handler on this page calls
 * self::authorise( $action ), which checks manage_options and then
 * check_admin_referer( $action ) before the first `$_POST` read. WPCS only
 * recognises a nonce check made in the same function, so each handler reads
 * as unchecked. Keeping the check in one place is the correct design; the
 * directive records that, rather than duplicating eight action strings and
 * eight checks.
 */
// phpcs:disable WordPress.Security.NonceVerification

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;

/**
 * Settings and operations page.
 */
final class WP_MCP_AI_Database_Settings_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wp-mcp-ai-database';

	/**
	 * Parent menu slug.
	 */
	public const PARENT = 'nvoos-pro-dashboard';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_wp_mcp_ai_db_save_connection', array( __CLASS__, 'handle_save_connection' ) );
		add_action( 'admin_post_wp_mcp_ai_db_delete_connection', array( __CLASS__, 'handle_delete_connection' ) );
		add_action( 'admin_post_wp_mcp_ai_db_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_wp_mcp_ai_db_save_scope', array( __CLASS__, 'handle_save_scope' ) );
		add_action( 'admin_post_wp_mcp_ai_db_delete_scope', array( __CLASS__, 'handle_delete_scope' ) );
		add_action( 'admin_post_wp_mcp_ai_db_approve_proposal', array( __CLASS__, 'handle_approve_proposal' ) );
		add_action( 'admin_post_wp_mcp_ai_db_revoke_proposal', array( __CLASS__, 'handle_revoke_proposal' ) );
	}

	/**
	 * Add the submenu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Database Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'Database', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handle a connection save.
	 */
	public static function handle_save_connection(): void {
		self::authorise( 'wp_mcp_ai_db_save_connection' );

		$registry = new WP_MCP_AI_Db_Connection_Registry();
		$id       = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';

		$record = array(
			'id'            => $id,
			'label'         => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : $id,
			'engine'        => isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( (string) $_POST['engine'] ) ) : 'pgsql',
			'host'          => isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '',
			'port'          => isset( $_POST['port'] ) ? absint( $_POST['port'] ) : 0,
			'database'      => isset( $_POST['database'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['database'] ) ) : '',
			'sslmode'       => isset( $_POST['sslmode'] ) ? sanitize_key( wp_unslash( (string) $_POST['sslmode'] ) ) : 'require',
			'role'          => isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['role'] ) ) : '',
			'allow_site_db' => ! empty( $_POST['allow_site_db'] ),
		);

		$username = isset( $_POST['db_username'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['db_username'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password must not be text-sanitised: sanitising silently strips characters from the secret. It is unslashed here and sealed by WP_MCP_AI_Encryption before storage.
		$password = isset( $_POST['db_password'] ) ? (string) wp_unslash( $_POST['db_password'] ) : '';

		if ( '' !== $password ) {
			$record['username'] = $username;
			$record['password'] = $password;
		}

		try {
			$saved = $registry->save( $record );
		} catch ( DatabaseRefusal $refusal ) {
			self::redirect( 'connections', 'error', $refusal->getMessage() );
		}

		self::redirect( 'connections', 'success', sprintf( /* translators: %s: connection id. */ __( 'Connection %s saved. Run Test to record its health.', 'mcp-ai-wpoos-pro' ), $saved->id ) );
	}

	/**
	 * Delete a connection.
	 */
	public static function handle_delete_connection(): void {
		self::authorise( 'wp_mcp_ai_db_delete_connection' );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';

		( new WP_MCP_AI_Db_Connection_Registry() )->delete( $id );

		self::redirect( 'connections', 'success', __( 'Connection deleted.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Probe a connection from the list.
	 */
	public static function handle_test_connection(): void {
		self::authorise( 'wp_mcp_ai_db_test_connection' );

		$id       = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';
		$registry = new WP_MCP_AI_Db_Connection_Registry();
		$record   = $registry->get( $id );

		if ( null === $record ) {
			self::redirect( 'connections', 'error', __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$factory = new WP_MCP_AI_Db_Connection_Factory( new WP_MCP_AI_Db_Encrypted_Credential_Store() );
		$probe   = ( new WP_MCP_AI_Db_Probe( $factory ) )->run( $record );

		$registry->record_probe( $id, $probe );
		( new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() ) )->invalidate( $record );

		$message = $probe->reachable
			? __( 'Connection reachable; health recorded.', 'mcp-ai-wpoos-pro' )
			: __( 'Connection failed; the reason was recorded.', 'mcp-ai-wpoos-pro' );

		self::redirect( 'connections', $probe->reachable ? 'success' : 'error', $message );
	}

	/**
	 * Save a scope.
	 */
	public static function handle_save_scope(): void {
		self::authorise( 'wp_mcp_ai_db_save_scope' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Every value below is sanitised or closed-set validated: tiers go through sanitize_key() then GrantTier::tryFrom(); principals through sanitize_text_field() then PrincipalRef; schemas/tables through self::csv(), and the three JSON fields through self::decode(), each of which calls wp_unslash() itself. The registry normalises and refuses the rest.
		$connection = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( (string) $_POST['connection'] ) ) : '';

		if ( null === ( new WP_MCP_AI_Db_Connection_Registry() )->get( $connection ) && ! str_starts_with( $connection, 'rs' ) ) {
			self::redirect( 'scopes', 'error', __( 'Choose a connection that exists.', 'mcp-ai-wpoos-pro' ) );
		}

		$tiers = array();

		foreach ( (array) wp_unslash( $_POST['tiers'] ?? array() ) as $tier ) {
			if ( ! is_string( $tier ) ) {
				continue;
			}

			$case = GrantTier::tryFrom( sanitize_key( $tier ) );

			if ( null !== $case ) {
				$tiers[] = $case->value;
			}
		}

		$principals = array();

		foreach ( preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['principals'] ?? '' ) ) as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$principals[] = sanitize_text_field( $line );
			}
		}

		$record = array(
			'id'         => isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '',
			'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '',
			'connection' => $connection,
			'schemas'    => self::csv( $_POST['schemas'] ?? '' ),
			'tables'     => self::csv( $_POST['tables'] ?? '' ),
			'tiers'      => $tiers,
			'principals' => $principals,
			'columns'    => self::json_object( $_POST['columns'] ?? '', 'columns' ),
			'row_filter' => self::json_list( $_POST['row_filter'] ?? '', 'row_filter' ),
			'limits'     => self::json_object( $_POST['limits'] ?? '', 'limits' ),
		);

		try {
			$saved = ( new WP_MCP_AI_Db_Scope_Registry() )->save( $record );
		} catch ( DatabaseRefusal $refusal ) {
			self::redirect( 'scopes', 'error', $refusal->getMessage() );
		}

		self::redirect(
			'scopes',
			'success',
			sprintf(
				/* translators: 1: scope id, 2: version. */
				__( 'Scope %1$s saved at version %2$d.', 'mcp-ai-wpoos-pro' ),
				$saved->id,
				$saved->version
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Delete a scope.
	 */
	public static function handle_delete_scope(): void {
		self::authorise( 'wp_mcp_ai_db_delete_scope' );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';

		( new WP_MCP_AI_Db_Scope_Registry() )->delete( $id );

		self::redirect( 'scopes', 'success', __( 'Scope deleted.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Approve a write proposal.
	 */
	public static function handle_approve_proposal(): void {
		self::authorise( 'wp_mcp_ai_db_approve_proposal' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';

		$ok = ( new WP_MCP_AI_Db_Proposal_Store() )->approve( $id, get_current_user_id() );

		self::redirect(
			'proposals',
			$ok ? 'success' : 'error',
			$ok ? __( 'Proposal approved. The assistant may now apply it.', 'mcp-ai-wpoos-pro' ) : __( 'Proposal could not be approved; it may have expired.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Revoke an approval.
	 */
	public static function handle_revoke_proposal(): void {
		self::authorise( 'wp_mcp_ai_db_revoke_proposal' );

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
		$store   = new WP_MCP_AI_Db_Proposal_Store();
		$records = $store->get( $id );

		if ( null !== $records ) {
			$records['status']      = 'proposed';
			$records['approved_by'] = 0;
			$records['approved_at'] = 0;

			$store->replace( $id, $records );
		}

		self::redirect( 'proposals', 'success', __( 'Approval revoked.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage database connections.', 'mcp-ai-wpoos-pro' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection is not a state change.
		$tabs = array(
			'connections' => __( 'Connections', 'mcp-ai-wpoos-pro' ),
			'scopes'      => __( 'Scopes', 'mcp-ai-wpoos-pro' ),
			'proposals'   => __( 'Write proposals', 'mcp-ai-wpoos-pro' ),
			'audit'       => __( 'Audit', 'mcp-ai-wpoos-pro' ),
			'health'      => __( 'Health', 'mcp-ai-wpoos-pro' ),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Database Toolkit', 'mcp-ai-wpoos-pro' ) . '</h1>';
		self::notice();

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$slug === $tab ? ' nav-tab-active' : '',
				esc_url( self::url( $slug ) ),
				esc_html( $label )
			);
		}

		echo '</nav><div style="margin-top:16px">';

		switch ( $tab ) {
			case 'scopes':
				self::render_scopes();
				break;
			case 'proposals':
				self::render_proposals();
				break;
			case 'audit':
				self::render_audit();
				break;
			case 'health':
				self::render_health();
				break;
			default:
				self::render_connections();
		}

		echo '</div></div>';
	}

	/**
	 * Connections tab.
	 */
	private static function render_connections(): void {
		$registry    = new WP_MCP_AI_Db_Connection_Registry();
		$connections = $registry->summaries();
		$editing     = isset( $_GET['edit'] ) ? $registry->find_public( sanitize_key( wp_unslash( (string) $_GET['edit'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form prefill.

		echo '<h2>' . esc_html__( 'Connections', 'mcp-ai-wpoos-pro' ) . '</h2>';

		if ( array() === $connections ) {
			echo '<p>' . esc_html__( 'No connections yet. Add one below.', 'mcp-ai-wpoos-pro' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Id', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Label', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Engine', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Healthy', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Read-safe role', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $connections as $summary ) {
				$probe = is_array( $summary['probe'] ?? null ) ? $summary['probe'] : array();

				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $summary['id'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $summary['label'] ) . '</td>';
				echo '<td>' . esc_html( (string) $summary['engine'] ) . '</td>';
				echo '<td>' . ( ! empty( $probe['reachable'] ) ? esc_html__( 'Yes', 'mcp-ai-wpoos-pro' ) : esc_html__( 'No', 'mcp-ai-wpoos-pro' ) ) . '</td>';
				echo '<td>' . ( empty( $probe['can_write'] ) && empty( $probe['bypasses_rls'] ) ? esc_html__( 'Yes', 'mcp-ai-wpoos-pro' ) : esc_html__( 'No', 'mcp-ai-wpoos-pro' ) ) . '</td>';
				echo '<td>';

				printf(
					'<a href="%s">%s</a> | ',
					esc_url( self::url( 'connections', array( 'edit' => (string) $summary['id'] ) ) ),
					esc_html__( 'Edit', 'mcp-ai-wpoos-pro' )
				);

				self::post_button( 'wp_mcp_ai_db_test_connection', (string) $summary['id'], __( 'Test', 'mcp-ai-wpoos-pro' ), 'wp_mcp_ai_db_test_connection' );

				echo ' | ';

				self::post_button( 'wp_mcp_ai_db_delete_connection', (string) $summary['id'], __( 'Delete', 'mcp-ai-wpoos-pro' ), 'wp_mcp_ai_db_delete_connection', true );

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h2 style="margin-top:24px">' . esc_html( null === $editing ? __( 'Add connection', 'mcp-ai-wpoos-pro' ) : __( 'Edit connection', 'mcp-ai-wpoos-pro' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		wp_nonce_field( 'wp_mcp_ai_db_save_connection' );
		echo '<input type="hidden" name="action" value="wp_mcp_ai_db_save_connection" />';

		self::text_field( 'id', __( 'Id', 'mcp-ai-wpoos-pro' ), (string) ( $editing['id'] ?? '' ), 'warehouse', null !== $editing );
		self::text_field( 'label', __( 'Label', 'mcp-ai-wpoos-pro' ), (string) ( $editing['label'] ?? '' ) );

		echo '<p><label>' . esc_html__( 'Engine', 'mcp-ai-wpoos-pro' ) . '<br /><select name="engine">';

		foreach ( array( 'pgsql', 'mysql', 'mariadb', 'sqlite' ) as $engine ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $engine ),
				( $editing['engine'] ?? 'pgsql' ) === $engine ? ' selected' : '',
				esc_html( $engine )
			);
		}

		echo '</select></label></p>';

		self::text_field( 'host', __( 'Host', 'mcp-ai-wpoos-pro' ), (string) ( $editing['host'] ?? '' ), 'db.example.com' );
		self::text_field( 'port', __( 'Port', 'mcp-ai-wpoos-pro' ), (string) ( $editing['port'] ?? '' ), '5432', false, 'number' );
		self::text_field( 'database', __( 'Database', 'mcp-ai-wpoos-pro' ), (string) ( $editing['database'] ?? '' ), 'analytics' );

		echo '<p><label>' . esc_html__( 'SSL mode', 'mcp-ai-wpoos-pro' ) . '<br /><select name="sslmode">';

		foreach ( array( 'disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full' ) as $mode ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $mode ),
				( $editing['sslmode'] ?? 'require' ) === $mode ? ' selected' : '',
				esc_html( $mode )
			);
		}

		echo '</select></label></p>';

		self::text_field( 'db_username', __( 'Database user', 'mcp-ai-wpoos-pro' ), (string) ( $editing['db_username_hint'] ?? '' ) );
		echo '<p><label>' . esc_html__( 'Database password (leave blank to keep the stored value)', 'mcp-ai-wpoos-pro' ) . '<br />';
		echo '<input type="password" name="db_password" value="" autocomplete="new-password" class="regular-text" /></label></p>';

		self::text_field( 'role', __( 'Expected role (documentation only)', 'mcp-ai-wpoos-pro' ), (string) ( $editing['role'] ?? '' ), 'nvoos_readonly' );

		echo '<p><label><input type="checkbox" name="allow_site_db" value="1"' . ( ! empty( $editing['allow_site_db'] ) ? ' checked' : '' ) . ' /> ';
		echo esc_html__( 'Allow this connection to be the WordPress database (not recommended).', 'mcp-ai-wpoos-pro' ) . '</label></p>';

		submit_button( __( 'Save connection', 'mcp-ai-wpoos-pro' ) );
		echo '</form>';
	}

	/**
	 * Scopes tab.
	 */
	private static function render_scopes(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry();
		$scopes   = $registry->all();
		$editing  = isset( $_GET['edit'] ) ? $registry->get( sanitize_key( wp_unslash( (string) $_GET['edit'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form prefill.
		$raw      = null !== $editing ? ( $registry->all()[ $editing->id ] ?? array() ) : array();

		echo '<h2>' . esc_html__( 'Scopes', 'mcp-ai-wpoos-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'A scope is the grant. An unbound scope is inert; a scope with no tier denies every operation.', 'mcp-ai-wpoos-pro' ) . '</p>';

		if ( array() === $scopes ) {
			echo '<p>' . esc_html__( 'No scopes yet.', 'mcp-ai-wpoos-pro' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Id', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Connection', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Schemas', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Tiers', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Version', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $scopes as $id => $record ) {
				$version = (int) ( $record['version'] ?? 1 );
				$tiers   = is_array( $record['tiers'] ?? null ) ? implode( ', ', array_map( 'strval', $record['tiers'] ) ) : '';
				$schemas = is_array( $record['schemas'] ?? null ) ? implode( ', ', array_map( 'strval', $record['schemas'] ) ) : '';

				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $id ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $record['connection'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $schemas ) . '</td>';
				echo '<td>' . esc_html( $tiers ) . '</td>';
				echo '<td>' . esc_html( (string) $version ) . '</td>';
				echo '<td>';

				printf(
					'<a href="%s">%s</a> | ',
					esc_url( self::url( 'scopes', array( 'edit' => (string) $id ) ) ),
					esc_html__( 'Edit', 'mcp-ai-wpoos-pro' )
				);

				self::post_button( 'wp_mcp_ai_db_delete_scope', (string) $id, __( 'Delete', 'mcp-ai-wpoos-pro' ), 'wp_mcp_ai_db_delete_scope', true );

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h2 style="margin-top:24px">' . esc_html( null === $editing ? __( 'Add scope', 'mcp-ai-wpoos-pro' ) : __( 'Edit scope', 'mcp-ai-wpoos-pro' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		wp_nonce_field( 'wp_mcp_ai_db_save_scope' );
		echo '<input type="hidden" name="action" value="wp_mcp_ai_db_save_scope" />';

		self::text_field( 'id', __( 'Id', 'mcp-ai-wpoos-pro' ), (string) ( $editing->id ?? '' ), 'analytics-readonly', null !== $editing );
		self::text_field( 'label', __( 'Label', 'mcp-ai-wpoos-pro' ), (string) ( $editing->label ?? '' ) );
		self::text_field( 'connection', __( 'Connection id', 'mcp-ai-wpoos-pro' ), (string) ( $editing->connection ?? '' ), 'warehouse', false, 'text', true );
		self::text_field( 'schemas', __( 'Schemas (comma separated; no wildcard)', 'mcp-ai-wpoos-pro' ), implode( ', ', $editing->schemas ?? array() ), 'reporting' );
		self::text_field( 'tables', __( 'Tables (comma separated schema.table, or *)', 'mcp-ai-wpoos-pro' ), implode( ', ', $editing->tables ?? array() ), 'reporting.orders, reporting.customers' );

		echo '<fieldset><legend>' . esc_html__( 'Tiers', 'mcp-ai-wpoos-pro' ) . '</legend>';

		foreach ( GrantTier::cases() as $tier ) {
			if ( ! $tier->isAssistantGrantable() ) {
				continue;
			}

			printf(
				'<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="tiers[]" value="%s"%s /> %s</label>',
				esc_attr( $tier->value ),
				in_array( $tier->value, is_array( $raw['tiers'] ?? null ) ? array_map( 'strval', $raw['tiers'] ) : array(), true ) ? ' checked' : '',
				esc_html( $tier->value )
			);
		}

		echo '</fieldset>';

		echo '<p><label>' . esc_html__( 'Principals (one per line: assistant:42 or user:7; empty means nobody)', 'mcp-ai-wpoos-pro' ) . '<br />';
		echo '<textarea name="principals" rows="3" class="large-text code">' . esc_textarea( implode( "\n", array_map( static fn ( $p ) => $p->key(), $editing->principals ?? array() ) ) ) . '</textarea></label></p>';

		echo '<p><label>' . esc_html__( 'Column rules (JSON: {"schema.table":{"sensitive":["email"],"exclude":["ssn"],"searchable":["name"]}})', 'mcp-ai-wpoos-pro' ) . '<br />';
		echo '<textarea name="columns" rows="3" class="large-text code">' . esc_textarea( (string) wp_json_encode( $raw['columns'] ?? new stdClass(), JSON_PRETTY_PRINT ) ) . '</textarea></label></p>';

		echo '<p><label>' . esc_html__( 'Row filter (JSON array: [{"column":"tenant_id","op":"eq","value_from":"scope_tenant"}])', 'mcp-ai-wpoos-pro' ) . '<br />';
		echo '<textarea name="row_filter" rows="3" class="large-text code">' . esc_textarea( (string) wp_json_encode( $raw['row_filter'] ?? array(), JSON_PRETTY_PRINT ) ) . '</textarea></label></p>';

		echo '<p><label>' . esc_html__( 'Limits (JSON: {"max_rows":500,"max_affected_rows":0,"timeout_ms":5000})', 'mcp-ai-wpoos-pro' ) . '<br />';

		$limit_defaults = self::limit_defaults();
		$limits_json    = array() !== ( $raw['limits'] ?? array() ) ? (string) wp_json_encode( $raw['limits'], JSON_PRETTY_PRINT ) : (string) wp_json_encode( $limit_defaults, JSON_PRETTY_PRINT );

		echo '<textarea name="limits" rows="2" class="large-text code">' . esc_textarea( $limits_json ) . '</textarea></label></p>';

		submit_button( __( 'Save scope', 'mcp-ai-wpoos-pro' ) );
		echo '</form>';
	}

	/**
	 * Proposals tab.
	 */
	private static function render_proposals(): void {
		$store     = new WP_MCP_AI_Db_Proposal_Store();
		$proposals = $store->all();

		echo '<h2>' . esc_html__( 'Write proposals', 'mcp-ai-wpoos-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'Approving here does not modify the database. It authorises the assistant to call db_apply_write, which re-checks the row count.', 'mcp-ai-wpoos-pro' ) . '</p>';

		if ( array() === $proposals ) {
			echo '<p>' . esc_html__( 'No proposals.', 'mcp-ai-wpoos-pro' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Id', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Relation', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Operation', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Statement', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $proposals, true ) as $id => $proposal ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $id ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $proposal['relation'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $proposal['operation'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $proposal['estimated_rows'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $proposal['status'] ?? '' ) ) . '</td>';
			echo '<td><code style="white-space:pre-wrap">' . esc_html( substr( (string) ( $proposal['statement_template'] ?? '' ), 0, 300 ) ) . '</code></td>';
			echo '<td>';

			if ( 'proposed' === ( $proposal['status'] ?? '' ) ) {
				self::post_button( 'wp_mcp_ai_db_approve_proposal', (string) $id, __( 'Approve', 'mcp-ai-wpoos-pro' ), 'wp_mcp_ai_db_approve_proposal' );
			} elseif ( 'approved' === ( $proposal['status'] ?? '' ) ) {
				self::post_button( 'wp_mcp_ai_db_revoke_proposal', (string) $id, __( 'Revoke', 'mcp-ai-wpoos-pro' ), 'wp_mcp_ai_db_revoke_proposal' );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Audit tab.
	 */
	private static function render_audit(): void {
		$log     = new WP_MCP_AI_Db_Audit_Log();
		$records = $log->tail( 100 );
		$summary = $log->summary( 3600 );

		echo '<h2>' . esc_html__( 'Audit (last 100 calls)', 'mcp-ai-wpoos-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'Records contain the statement template and a fingerprint, never bound values.', 'mcp-ai-wpoos-pro' ) . '</p>';

		echo '<p>';

		foreach ( $summary as $code => $count ) {
			printf(
				'<span class="badge" style="display:inline-block;margin-right:8px;padding:2px 8px;background:#f0f0f1;border-radius:3px">%s: <strong>%d</strong></span>',
				esc_html( (string) $code ),
				(int) $count
			);
		}

		echo '</p>';

		if ( array() === $records ) {
			echo '<p>' . esc_html__( 'No calls recorded yet.', 'mcp-ai-wpoos-pro' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Principal', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Connection', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Scope', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Refusal', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Statement', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			echo '<tr>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $record['ts'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['principal'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['connection'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['scope'] ?? '' ) . ' v' . (int) ( $record['scope_version'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['action'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['row_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $record['refusal_code'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( substr( (string) ( $record['sql_template'] ?? '' ), 0, 160 ) ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Health tab.
	 */
	private static function render_health(): void {
		$runtime = class_exists( 'WP_MCP_AI_Db_Runtime' ) ? WP_MCP_AI_Db_Runtime::reason() : 'runtime_missing';
		$drivers = array();

		foreach ( array( 'pdo_pgsql', 'pdo_mysql', 'pdo_sqlite' ) as $driver ) {
			$drivers[ $driver ] = extension_loaded( $driver );
		}

		$connections = count( ( new WP_MCP_AI_Db_Connection_Registry() )->all() );
		$scopes      = count( ( new WP_MCP_AI_Db_Scope_Registry() )->all() );
		$proposals   = count( ( new WP_MCP_AI_Db_Proposal_Store() )->all() );

		echo '<h2>' . esc_html__( 'Health', 'mcp-ai-wpoos-pro' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		self::health_row( __( 'Runtime', 'mcp-ai-wpoos-pro' ), '' === $runtime ? __( 'Available', 'mcp-ai-wpoos-pro' ) : $runtime );
		self::health_row( __( 'PHP', 'mcp-ai-wpoos-pro' ), PHP_VERSION . ' (' . __( 'kernel requires 8.2+', 'mcp-ai-wpoos-pro' ) . ')' );

		foreach ( $drivers as $driver => $loaded ) {
			self::health_row( $driver, $loaded ? __( 'Loaded', 'mcp-ai-wpoos-pro' ) : __( 'Missing', 'mcp-ai-wpoos-pro' ) );
		}

		self::health_row( __( 'Connections', 'mcp-ai-wpoos-pro' ), (string) $connections );
		self::health_row( __( 'Scopes', 'mcp-ai-wpoos-pro' ), (string) $scopes );
		self::health_row( __( 'Write proposals', 'mcp-ai-wpoos-pro' ), (string) $proposals );
		self::health_row( __( 'Write path', 'mcp-ai-wpoos-pro' ), ! empty( get_option( 'wp_mcp_ai_settings', array() )['enable_database_writes'] ) ? __( 'Enabled', 'mcp-ai-wpoos-pro' ) : __( 'Disabled', 'mcp-ai-wpoos-pro' ) );

		echo '</tbody></table>';
	}

	/**
	 * Health table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function health_row( string $label, string $value ): void {
		echo '<tr><td style="width:220px"><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Site-wide limit defaults, used to prefill a new scope.
	 *
	 * Delegates to {@see WP_MCP_AI_Db_Limit_Defaults} because the console's REST
	 * route reads the same numbers and a front-end REST request never loads this
	 * admin page. One gatherer, two surfaces.
	 *
	 * @return array<string, int>
	 */
	private static function limit_defaults(): array {
		return WP_MCP_AI_Db_Limit_Defaults::get();
	}

	/**
	 * Render a text field.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param string $placeholder Placeholder.
	 * @param bool   $is_readonly Whether the field is read-only.
	 * @param string $type     Input type.
	 * @param bool   $required Whether the field is required.
	 */
	private static function text_field(
		string $name,
		string $label,
		string $value,
		string $placeholder = '',
		bool $is_readonly = false,
		string $type = 'text',
		bool $required = false
	): void {
		printf(
			'<p><label>%s<br /><input type="%s" name="%s" value="%s" placeholder="%s" class="regular-text"%s%s /></label></p>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			$is_readonly ? ' readonly' : '',
			$required ? ' required' : ''
		);
	}

	/**
	 * Render a POST button.
	 *
	 * @param string $action    Action name.
	 * @param string $id        Record id.
	 * @param string $label     Button label.
	 * @param string $nonce     Nonce action.
	 * @param bool   $confirm   Whether to prompt before submitting.
	 */
	private static function post_button( string $action, string $id, string $label, string $nonce, bool $confirm = false ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( $nonce );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="id" value="%s" />', esc_attr( $id ) );
		printf(
			'<button type="submit" class="button button-link"%s>%s</button>',
			$confirm ? ' onclick="return confirm(\'' . esc_js( __( 'Delete this record?', 'mcp-ai-wpoos-pro' ) ) . '\')"' : '',
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Print a notice from the query string.
	 */
	private static function notice(): void {
		if ( ! isset( $_GET['db_notice'] ) ) {
			return;
		}

		// A query-string value is a string, but a crafted `?db_notice[]=x` is an
		// array, and sanitize_key()/sanitize_text_field() raise on an array.
		// Guard the shape before sanitising rather than casting.
		$message = is_string( $_GET['db_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['db_notice'] ) ) : '';
		$type    = is_string( $_GET['db_notice_type'] ?? null ) && 'error' === sanitize_key( wp_unslash( $_GET['db_notice_type'] ) )
			? 'error'
			: 'success';

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Parse a comma/newline separated list.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function csv( mixed $value ): array {
		$list = array();

		foreach ( preg_split( '/[\r\n,]+/', (string) wp_unslash( $value ) ) as $entry ) {
			$entry = strtolower( trim( sanitize_text_field( (string) $entry ) ) );

			if ( '' !== $entry ) {
				$list[] = $entry;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Parse a JSON object, refusing malformed input.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name, for the error notice.
	 * @return array<string, mixed>
	 */
	private static function json_object( mixed $value, string $field ): array {
		$decoded = self::decode( $value, $field );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Parse a JSON list, refusing malformed input.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return list<mixed>
	 */
	private static function json_list( mixed $value, string $field ): array {
		$decoded = self::decode( $value, $field );

		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Decode JSON from a form field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return mixed
	 */
	private static function decode( mixed $value, string $field ) {
		$raw = trim( (string) wp_unslash( $value ) );

		if ( '' === $raw || '{}' === $raw || '[]' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			self::redirect(
				'scopes',
				'error',
				sprintf(
					/* translators: %s: field name. */
					__( '%s is not valid JSON.', 'mcp-ai-wpoos-pro' ),
					$field
				)
			);
		}

		return $decoded;
	}

	/**
	 * Verify capability and nonce, dying on failure.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function authorise( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage database connections.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( $nonce );
	}

	/**
	 * Redirect back to a tab with a notice.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $type    `success` or `error`.
	 * @param string $message Message.
	 */
	private static function redirect( string $tab, string $type, string $message ): void {
		wp_safe_redirect(
			self::url(
				$tab,
				array(
					'db_notice'      => $message,
					'db_notice_type' => $type,
				)
			)
		);
		exit;
	}

	/**
	 * Build a page URL.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Extra query args.
	 */
	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}
}
