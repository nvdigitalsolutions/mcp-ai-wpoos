<?php
/**
 * External Operators admin page for the Fleet Operator addon.
 *
 * Lists operator credentials, creates new ones (showing the token and the
 * generated Hermes config once), and revokes credentials (kill switch).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for external-operator credential management.
 */
class WP_MCP_AI_Operator_Admin {

	const PAGE_SLUG        = 'wp-mcp-ai-fleet-operators';
	const CREATE_ACTION    = 'wp_mcp_ai_operator_create';
	const REVOKE_ACTION    = 'wp_mcp_ai_operator_revoke';
	const CREATE_NONCE     = 'wp_mcp_ai_operator_create_nonce';
	const REVOKE_NONCE     = 'wp_mcp_ai_operator_revoke_nonce';
	const RESULT_TRANSIENT = 'wp_mcp_ai_operator_result_';

	/**
	 * Constructor. Registers the menu page and form handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 30 );
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( $this, 'handle_revoke' ) );
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'External Operators', 'mcp-ai-wpoos' ),
			__( 'External Operators', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the page: operator list + create form + one-time result.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id   = get_current_user_id();
		$transient = self::RESULT_TRANSIENT . $user_id;
		$result    = get_transient( $transient );
		$records   = WP_MCP_AI_Operator_Credential_Repository::get_all();
		$mcp_url   = rest_url( 'mcp-ai/v1/mcp' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'External Operators (Hermes)', 'mcp-ai-wpoos' ); ?></h1>
			<div class="notice notice-info inline">
				<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: MCP endpoint URL, 2: operators page URL */
						__( 'Issue scoped credentials so a supervisor agent (Hermes or any MCP/A2A host) can operate this site within an allowlist. MCP endpoint: <code>%1$s</code>. See the <a href="%2$s">Fleet Operator implementation plan</a>.', 'mcp-ai-wpoos' ),
						esc_html( $mcp_url ),
						'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md'
					),
					array(
						'code' => array(),
						'a'    => array( 'href' => array() ),
					)
				);
				?>
				</p>
			</div>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'Operator created. Copy the token now — it is shown only once.', 'mcp-ai-wpoos' ); ?></strong></p>
					<p><?php esc_html_e( 'Token:', 'mcp-ai-wpoos' ); ?> <code><?php echo esc_html( $result['token'] ); ?></code></p>
					<p><?php esc_html_e( 'Add to ~/.hermes/.env:', 'mcp-ai-wpoos' ); ?></p>
					<pre><?php echo esc_html( $result['env'] ); ?></pre>
					<p><?php esc_html_e( 'Add to ~/.hermes/config.yaml:', 'mcp-ai-wpoos' ); ?></p>
					<pre><?php echo esc_html( $result['yaml'] ); ?></pre>
				</div>
				<?php
				delete_transient( $transient );
			endif;

			$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag; no state change.
			if ( 'created' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Operator credential created.', 'mcp-ai-wpoos' ) . '</p></div>';
			} elseif ( 'revoked' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Operator credential revoked.', 'mcp-ai-wpoos' ) . '</p></div>';
			} elseif ( 'error' === $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Operation failed. Check the form values and try again.', 'mcp-ai-wpoos' ) . '</p></div>';
			}
			?>

			<h2><?php esc_html_e( 'Existing operators', 'mcp-ai-wpoos' ); ?></h2>
			<?php if ( empty( $records ) ) : ?>
				<p><?php esc_html_e( 'No external operators yet.', 'mcp-ai-wpoos' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Tools', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Last used', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $records as $record ) : ?>
						<tr>
							<td><?php echo esc_html( $record['label'] ); ?></td>
							<td><code><?php echo esc_html( $record['id'] ); ?></code></td>
							<td><?php echo esc_html( $record['mode'] ); ?></td>
							<td><?php echo esc_html( count( $record['allowed_tools'] ) ); ?></td>
							<td>
							<?php
							if ( empty( $record['expires_at'] ) ) {
								esc_html_e( 'Never', 'mcp-ai-wpoos' );
							} else {
								echo esc_html(
									sprintf(
										/* translators: %s: localized date */
										__( '%s', 'mcp-ai-wpoos' ),
										wp_date( get_option( 'date_format' ), $record['expires_at'] )
									)
								);
							}
							?>
							</td>
							<td>
							<?php
							if ( empty( $record['last_used_at'] ) ) {
								esc_html_e( 'Never', 'mcp-ai-wpoos' );
							} else {
								echo esc_html(
									sprintf(
										/* translators: %s: localized date */
										__( '%s', 'mcp-ai-wpoos' ),
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['last_used_at'] )
									)
								);
							}
							?>
							</td>
							<td><?php echo esc_html( $record['status'] ); ?></td>
							<td>
							<?php if ( 'active' === $record['status'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::REVOKE_NONCE, '_wpnonce' ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>">
									<input type="hidden" name="id" value="<?php echo esc_attr( $record['id'] ); ?>">
									<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke', 'mcp-ai-wpoos' ); ?></button>
								</form>
							<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Create operator', 'mcp-ai-wpoos' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::CREATE_NONCE, '_wpnonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>">
				<table class="form-table" style="max-width: 900px;">
					<tr>
						<th scope="row"><label for="op_label"><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
						<td><input type="text" class="regular-text" id="op_label" name="label" placeholder="<?php esc_attr_e( 'Hermes', 'mcp-ai-wpoos' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="op_user"><?php esc_html_e( 'Act as user', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select id="op_user" name="user_id">
							<?php
							$users = get_users(
								array(
									'role__in' => array( 'administrator', 'editor' ),
									'fields'   => array( 'ID', 'user_login' ),
								)
							);
							foreach ( $users as $user ) {
								printf(
									'<option value="%1$d">%2$s</option>',
									absint( $user->ID ),
									esc_html( $user->user_login )
								);
							}
							?>
							</select>
							<p class="description"><?php esc_html_e( 'The operator gets this user\'s capabilities — no more, no less.', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="op_mode"><?php esc_html_e( 'Mode', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select id="op_mode" name="mode">
								<option value="readwrite"><?php esc_html_e( 'Read + write (with approval gates)', 'mcp-ai-wpoos' ); ?></option>
								<option value="read"><?php esc_html_e( 'Read only', 'mcp-ai-wpoos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="op_tools"><?php esc_html_e( 'Allowed tools', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<textarea id="op_tools" name="tools" rows="6" class="large-text code" placeholder="<?php esc_attr_e( "create_post\nget_recent_posts\ngroup:content_publishing\nwoo_*", 'mcp-ai-wpoos' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'One entry per line: tool slugs, fnmatch globs (woo_*), or group:<toolkit> entries. Empty = no tools allowed.', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="op_expires"><?php esc_html_e( 'Expires', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select id="op_expires" name="expires_days">
								<option value="30"><?php esc_html_e( '30 days', 'mcp-ai-wpoos' ); ?></option>
								<option value="90" selected><?php esc_html_e( '90 days', 'mcp-ai-wpoos' ); ?></option>
								<option value="365"><?php esc_html_e( '1 year', 'mcp-ai-wpoos' ); ?></option>
								<option value="0"><?php esc_html_e( 'Never', 'mcp-ai-wpoos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="op_rate"><?php esc_html_e( 'Rate limit', 'mcp-ai-wpoos' ); ?></label></th>
						<td><input type="number" min="1" max="600" id="op_rate" name="rate_limit" value="60"> <span class="description"><?php esc_html_e( 'requests per minute', 'mcp-ai-wpoos' ); ?></span></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create operator credential', 'mcp-ai-wpoos' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'How to wire Hermes', 'mcp-ai-wpoos' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Create an operator above and copy the token.', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Paste the .env line into ~/.hermes/.env and the config.yaml block into ~/.hermes/config.yaml.', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Run "hermes /reload-mcp" (or restart the desktop app) and ask Hermes to list the tools.', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Keep "trust: untrusted" so every write-capable call asks you for approval.', 'mcp-ai-wpoos' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Handle the create form submission.
	 *
	 * @return void
	 */
	public function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}
		check_admin_referer( self::CREATE_NONCE, '_wpnonce' );

		$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$user_id    = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'readwrite';
		$expires    = isset( $_POST['expires_days'] ) ? absint( $_POST['expires_days'] ) : 90;
		$rate_limit = isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : 60;
		$tools_raw  = isset( $_POST['tools'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tools'] ) ) : '';
		$tools      = array_filter( array_map( 'trim', explode( "\n", $tools_raw ) ) );

		$created = WP_MCP_AI_Operator_Credential_Repository::create( $label, $user_id, $tools, $mode, $expires, $rate_limit );

		$redirect = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );

		if ( is_wp_error( $created ) ) {
			wp_safe_redirect( add_query_arg( 'notice', 'error', $redirect ) );
			exit;
		}

		$generated = WP_MCP_AI_Operator_Config_Generator::generate_for_site(
			$created['record']['label'],
			untrailingslashit( home_url( '/' ) ),
			$created['token'],
			$created['record']['allowed_tools']
		);

		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'token' => $created['token'],
				'yaml'  => $generated['yaml'],
				'env'   => $generated['env'],
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'notice', 'created', $redirect ) );
		exit;
	}

	/**
	 * Handle the revoke form submission (kill switch).
	 *
	 * @return void
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}
		check_admin_referer( self::REVOKE_NONCE, '_wpnonce' );

		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$revoked = WP_MCP_AI_Operator_Credential_Repository::revoke( $id );

		$redirect = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );
		wp_safe_redirect( add_query_arg( 'notice', $revoked ? 'revoked' : 'error', $redirect ) );
		exit;
	}
}
