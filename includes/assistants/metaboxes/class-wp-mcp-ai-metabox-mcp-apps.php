<?php
/**
 * MCP Apps Metabox for Assistants.
 *
 * Provides the admin UI for configuring MCP App connections
 * per assistant, following the MCP Apps extension (SEP-1865).
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @see     https://modelcontextprotocol.io/extensions/apps/overview
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the MCP Apps metabox on the assistant editor.
 *
 * Allows administrators to connect remote MCP servers to an assistant,
 * enabling tool discovery and UI resource integration per the MCP
 * specification and SEP-1865 Apps extension.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Metabox_MCP_Apps extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_mcp_apps';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_title() {
		return __( 'MCP Apps', 'mcp-ai-wpoos' );
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://modelcontextprotocol.io/extensions/apps/overview';
	}

	/**
	 * Check if current user has permission to view this metabox.
	 *
	 * @since 1.8.0
	 * @return bool
	 */
	protected function can_view() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.8.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied( __( 'You do not have permission to manage MCP Apps.', 'mcp-ai-wpoos' ) );
			return;
		}

		wp_nonce_field( 'wp_mcp_ai_mcp_apps_meta', 'wp_mcp_ai_mcp_apps_meta_nonce' );

		$apps = array();
		if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
			$apps     = $registry->get_apps( $post->ID );
		}

		?>
		<div class="wp-mcp-ai-mcp-apps">
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to the MCP Apps specification */
					esc_html__( 'Connect remote MCP servers as apps to extend this assistant with external tools and interactive UIs. Apps follow the %s specification.', 'mcp-ai-wpoos' ),
					'<a href="https://modelcontextprotocol.io/extensions/apps/overview" target="_blank" rel="noopener noreferrer">' . esc_html__( 'MCP Apps (SEP-1865)', 'mcp-ai-wpoos' ) . '</a>'
				);
				?>
			</p>

			<div id="wp-mcp-ai-mcp-apps-list">
				<?php
				if ( empty( $apps ) ) {
					$this->render_empty_state();
				} else {
					foreach ( $apps as $index => $app ) {
						$this->render_app_row( $index, $app );
					}
				}
				?>
			</div>

			<p style="margin-top: 15px;">
				<button type="button" class="button button-secondary" id="wp-mcp-ai-add-mcp-app">
					<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Add MCP App', 'mcp-ai-wpoos' ); ?>
				</button>
			</p>

			<p class="description" style="margin-top: 10px;">
				<?php
				printf(
					/* translators: %d: Maximum number of apps allowed. */
					esc_html__( 'Maximum %d MCP Apps per assistant. Each app connects to a remote MCP server via Streamable HTTP transport.', 'mcp-ai-wpoos' ),
					10
				);
				?>
			</p>
		</div>

		<?php
		$this->render_app_template();
		$this->render_script();
		$this->render_documentation_link();
	}

	/**
	 * Save metabox data.
	 *
	 * @since 1.8.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['wp_mcp_ai_mcp_apps_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_mcp_apps_meta_nonce'] ) ), 'wp_mcp_ai_mcp_apps_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$apps = array();

		if ( isset( $_POST['wp_mcp_ai_mcp_apps'] ) && is_array( $_POST['wp_mcp_ai_mcp_apps'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_app_config.
			$raw_apps = wp_unslash( $_POST['wp_mcp_ai_mcp_apps'] );

			foreach ( $raw_apps as $raw_app ) {
				if ( ! is_array( $raw_app ) ) {
					continue;
				}

				$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw_app );
				if ( ! empty( $sanitized['server_url'] ) ) {
					$apps[] = $sanitized;
				}
			}
		}

		if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
			$registry->save_apps( $post_id, $apps );
		} elseif ( empty( $apps ) ) {
			delete_post_meta( $post_id, WP_MCP_AI_MCP_App_Registry::META_KEY );
		} else {
			$sanitized_apps = array();
			foreach ( $apps as $app ) {
				$sanitized_apps[] = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $app );
			}
			update_post_meta( $post_id, WP_MCP_AI_MCP_App_Registry::META_KEY, array_slice( $sanitized_apps, 0, 10 ) );
		}
	}

	/**
	 * Render the empty state when no apps are configured.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	protected function render_empty_state() {
		?>
		<div class="wp-mcp-ai-mcp-apps-empty" id="wp-mcp-ai-mcp-apps-empty" style="padding: 20px; text-align: center; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; margin: 15px 0;">
			<span class="dashicons dashicons-cloud" style="font-size: 32px; color: #999; display: block; margin-bottom: 10px;"></span>
			<p><?php esc_html_e( 'No MCP Apps connected yet.', 'mcp-ai-wpoos' ); ?></p>
			<p class="description"><?php esc_html_e( 'Add a remote MCP server to extend this assistant with external tools and interactive UI resources.', 'mcp-ai-wpoos' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a single MCP App configuration row.
	 *
	 * @since 1.8.0
	 * @param int   $index App index.
	 * @param array $app   App configuration.
	 * @return void
	 */
	protected function render_app_row( $index, $app ) {
		$app = wp_parse_args(
			$app,
			array(
				'label'       => '',
				'server_url'  => '',
				'auth_type'   => 'none',
				'token'       => '',
				'header_name' => '',
				'enabled'     => true,
				'timeout'     => 30,
				'verify_ssl'  => true,
			)
		);

		$prefix = 'wp_mcp_ai_mcp_apps[' . $index . ']';
		?>
		<div class="wp-mcp-ai-mcp-app-row" style="border: 1px solid #dcdcde; border-radius: 3px; padding: 15px; margin: 10px 0; background: #fff;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<strong class="wp-mcp-ai-mcp-app-title">
					<?php echo esc_html( ! empty( $app['label'] ) ? $app['label'] : __( 'MCP App', 'mcp-ai-wpoos' ) ); ?>
				</strong>
				<div>
					<label style="margin-right: 10px;">
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="0" />
						<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( $app['enabled'] ); ?> />
						<?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?>
					</label>
					<button type="button" class="button button-link-delete wp-mcp-ai-remove-mcp-app"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos' ); ?></button>
				</div>
			</div>

			<table class="form-table" style="margin: 0;">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $app['label'] ); ?>" class="regular-text wp-mcp-ai-mcp-app-label" placeholder="<?php esc_attr_e( 'My MCP App', 'mcp-ai-wpoos' ); ?>" />
						<p class="description"><?php esc_html_e( 'A friendly name for this MCP App connection.', 'mcp-ai-wpoos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Server URL', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="url" name="<?php echo esc_attr( $prefix ); ?>[server_url]" value="<?php echo esc_attr( $app['server_url'] ); ?>" class="regular-text" placeholder="https://example.com/mcp" required />
						<p class="description"><?php esc_html_e( 'The remote MCP server endpoint URL (Streamable HTTP transport).', 'mcp-ai-wpoos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Authentication', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $prefix ); ?>[auth_type]" class="wp-mcp-ai-mcp-app-auth-type">
							<option value="none" <?php selected( $app['auth_type'], 'none' ); ?>><?php esc_html_e( 'None', 'mcp-ai-wpoos' ); ?></option>
							<option value="bearer" <?php selected( $app['auth_type'], 'bearer' ); ?>><?php esc_html_e( 'Bearer Token', 'mcp-ai-wpoos' ); ?></option>
							<option value="header" <?php selected( $app['auth_type'], 'header' ); ?>><?php esc_html_e( 'Custom Header', 'mcp-ai-wpoos' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="wp-mcp-ai-mcp-app-token-row" <?php echo 'none' === $app['auth_type'] ? 'style="display:none;"' : ''; ?>>
					<th scope="row"><label><?php esc_html_e( 'Token / API Key', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $prefix ); ?>[token]" value="<?php echo esc_attr( $app['token'] ); ?>" class="regular-text" autocomplete="off" />
					</td>
				</tr>
				<tr class="wp-mcp-ai-mcp-app-header-row" <?php echo 'header' !== $app['auth_type'] ? 'style="display:none;"' : ''; ?>>
					<th scope="row"><label><?php esc_html_e( 'Header Name', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[header_name]" value="<?php echo esc_attr( $app['header_name'] ); ?>" class="regular-text" placeholder="X-API-Key" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Timeout', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $prefix ); ?>[timeout]" value="<?php echo esc_attr( $app['timeout'] ); ?>" min="1" max="120" style="width: 80px;" />
						<span class="description"><?php esc_html_e( 'seconds', 'mcp-ai-wpoos' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Verify SSL', 'mcp-ai-wpoos' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[verify_ssl]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[verify_ssl]" value="1" <?php checked( $app['verify_ssl'] ); ?> />
							<?php esc_html_e( 'Verify SSL certificate on the remote server.', 'mcp-ai-wpoos' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the JavaScript template for adding new app rows.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	protected function render_app_template() {
		?>
		<script type="text/html" id="tmpl-wp-mcp-ai-mcp-app-row">
			<div class="wp-mcp-ai-mcp-app-row" style="border: 1px solid #dcdcde; border-radius: 3px; padding: 15px; margin: 10px 0; background: #fff;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
					<strong class="wp-mcp-ai-mcp-app-title"><?php esc_html_e( 'New MCP App', 'mcp-ai-wpoos' ); ?></strong>
					<div>
						<label style="margin-right: 10px;">
							<input type="hidden" name="wp_mcp_ai_mcp_apps[{{data.index}}][enabled]" value="0" />
							<input type="checkbox" name="wp_mcp_ai_mcp_apps[{{data.index}}][enabled]" value="1" checked />
							<?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?>
						</label>
						<button type="button" class="button button-link-delete wp-mcp-ai-remove-mcp-app"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos' ); ?></button>
					</div>
				</div>

				<table class="form-table" style="margin: 0;">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="text" name="wp_mcp_ai_mcp_apps[{{data.index}}][label]" value="" class="regular-text wp-mcp-ai-mcp-app-label" placeholder="<?php esc_attr_e( 'My MCP App', 'mcp-ai-wpoos' ); ?>" />
							<p class="description"><?php esc_html_e( 'A friendly name for this MCP App connection.', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Server URL', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="url" name="wp_mcp_ai_mcp_apps[{{data.index}}][server_url]" value="" class="regular-text" placeholder="https://example.com/mcp" required />
							<p class="description"><?php esc_html_e( 'The remote MCP server endpoint URL (Streamable HTTP transport).', 'mcp-ai-wpoos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Authentication', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select name="wp_mcp_ai_mcp_apps[{{data.index}}][auth_type]" class="wp-mcp-ai-mcp-app-auth-type">
								<option value="none"><?php esc_html_e( 'None', 'mcp-ai-wpoos' ); ?></option>
								<option value="bearer"><?php esc_html_e( 'Bearer Token', 'mcp-ai-wpoos' ); ?></option>
								<option value="header"><?php esc_html_e( 'Custom Header', 'mcp-ai-wpoos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-token-row" style="display:none;">
						<th scope="row"><label><?php esc_html_e( 'Token / API Key', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="password" name="wp_mcp_ai_mcp_apps[{{data.index}}][token]" value="" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr class="wp-mcp-ai-mcp-app-header-row" style="display:none;">
						<th scope="row"><label><?php esc_html_e( 'Header Name', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="text" name="wp_mcp_ai_mcp_apps[{{data.index}}][header_name]" value="" class="regular-text" placeholder="X-API-Key" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Timeout', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="number" name="wp_mcp_ai_mcp_apps[{{data.index}}][timeout]" value="30" min="1" max="120" style="width: 80px;" />
							<span class="description"><?php esc_html_e( 'seconds', 'mcp-ai-wpoos' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Verify SSL', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<input type="hidden" name="wp_mcp_ai_mcp_apps[{{data.index}}][verify_ssl]" value="0" />
							<label>
								<input type="checkbox" name="wp_mcp_ai_mcp_apps[{{data.index}}][verify_ssl]" value="1" checked />
								<?php esc_html_e( 'Verify SSL certificate on the remote server.', 'mcp-ai-wpoos' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		</script>
		<?php
	}

		/**
		 * Render the JavaScript for the MCP Apps metabox.
		 *
		 * @since 1.8.0
		 * @return void
		 */
	protected function render_script() {
		$app_index          = (int) count( $this->get_current_apps_count() );
		$max_apps_message   = esc_js( __( 'Maximum number of MCP Apps reached.', 'mcp-ai-wpoos' ) );
		$confirm_message    = esc_js( __( 'Remove this MCP App connection?', 'mcp-ai-wpoos' ) );
		$mcp_app_label      = esc_js( __( 'MCP App', 'mcp-ai-wpoos' ) );

		ob_start();
		?>
			( function() {
				var appIndex = <?php echo (int) $app_index; ?>;
				var maxApps = 10;

				document.addEventListener( 'DOMContentLoaded', function() {
					var addBtn = document.getElementById( 'wp-mcp-ai-add-mcp-app' );
					var listEl = document.getElementById( 'wp-mcp-ai-mcp-apps-list' );
					var emptyEl = document.getElementById( 'wp-mcp-ai-mcp-apps-empty' );

					if ( ! addBtn || ! listEl ) {
						return;
					}

					addBtn.addEventListener( 'click', function() {
						var rows = listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' );
						if ( rows.length >= maxApps ) {
							window.alert( <?php echo wp_json_encode( $max_apps_message ); ?> );
							return;
						}

						var tmpl = document.getElementById( 'tmpl-wp-mcp-ai-mcp-app-row' );
						if ( ! tmpl ) {
							return;
						}

						var html = tmpl.innerHTML.replace( /\{\{data\.index\}\}/g, appIndex );
						appIndex++;

						if ( emptyEl ) {
							emptyEl.style.display = 'none';
						}

						var wrapper = document.createElement( 'div' );
						wrapper.innerHTML = html;
						listEl.appendChild( wrapper.firstElementChild );
					} );

					listEl.addEventListener( 'click', function( event ) {
						if ( event.target.classList.contains( 'wp-mcp-ai-remove-mcp-app' ) ) {
							if ( window.confirm( <?php echo wp_json_encode( $confirm_message ); ?> ) ) {
								var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
								if ( row ) {
									row.remove();
								}

								var remaining = listEl.querySelectorAll( '.wp-mcp-ai-mcp-app-row' );
								if ( remaining.length === 0 && emptyEl ) {
									emptyEl.style.display = '';
								}
							}
						}
					} );

					listEl.addEventListener( 'change', function( event ) {
						if ( event.target.classList.contains( 'wp-mcp-ai-mcp-app-auth-type' ) ) {
							var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
							if ( ! row ) {
								return;
							}

							var tokenRow = row.querySelector( '.wp-mcp-ai-mcp-app-token-row' );
							var headerRow = row.querySelector( '.wp-mcp-ai-mcp-app-header-row' );
							var value = event.target.value;

							if ( tokenRow ) {
								tokenRow.style.display = ( value === 'none' ) ? 'none' : '';
							}
							if ( headerRow ) {
								headerRow.style.display = ( value === 'header' ) ? '' : 'none';
							}
						}
					} );

					listEl.addEventListener( 'input', function( event ) {
						if ( event.target.classList.contains( 'wp-mcp-ai-mcp-app-label' ) ) {
							var row = event.target.closest( '.wp-mcp-ai-mcp-app-row' );
							if ( ! row ) {
								return;
							}

							var titleEl = row.querySelector( '.wp-mcp-ai-mcp-app-title' );
							if ( titleEl ) {
								titleEl.textContent = event.target.value || <?php echo wp_json_encode( $mcp_app_label ); ?>;
							}
						}
					} );
			} );
		} )();
		<?php
		$js = ob_get_clean();
		wp_print_inline_script_tag( $js );
	}

	/**
	 * Get current apps count for JS initialization.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	protected function get_current_apps_count() {
		global $post;

		if ( ! $post || ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		return $registry->get_apps( $post->ID );
	}
}
