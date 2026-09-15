<?php
/**
 * Admin UI for assistant export/import.
 *
 * Adds every wp-admin surface for the portability engine:
 *
 *   - "Export" row action on the assistant list table.
 *   - "Export" bulk action on the assistant list table.
 *   - An "Import / Export" submenu page under the Assistants CPT menu with
 *     bulk selection, A2A options, file upload / paste-JSON import, and
 *     per-assistant import reports.
 *
 * All handlers verify the `wp_mcp_ai_assistant_portability` nonce and the
 * `manage_options` capability.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assistant portability admin surfaces.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Admin_Assistant_Portability {

	/**
	 * Nonce action shared by every handler.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_assistant_portability';

	/**
	 * Capability required by every handler.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Transient key prefix for import reports.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const REPORT_TRANSIENT = 'wp_mcp_ai_assistant_import_report_';

	/**
	 * Register hooks.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-mcp_ai_assistant', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-mcp_ai_assistant', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_post_wp_mcp_ai_export_assistant', array( __CLASS__, 'handle_admin_post_export' ) );
		add_action( 'admin_post_wp_mcp_ai_import_assistant', array( __CLASS__, 'handle_admin_post_import' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu_page' ) );
	}

	/**
	 * Add the "Export" row action to assistant rows.
	 *
	 * @since 1.1.80
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post.
	 * @return array Modified row actions.
	 */
	public static function add_row_action( $actions, $post ) {
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wp_mcp_ai_export_assistant&id=' . absint( $post->ID ) ),
			self::NONCE_ACTION
		);

		$actions['export_assistant'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Export', 'mcp-ai-wpoos' )
		);

		return $actions;
	}

	/**
	 * Add the "Export" bulk action to the assistant list table.
	 *
	 * @since 1.1.80
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function add_bulk_action( $bulk_actions ) {
		$bulk_actions['export_assistant'] = __( 'Export', 'mcp-ai-wpoos' );
		return $bulk_actions;
	}

	/**
	 * Route the bulk export action to the admin-post download handler.
	 *
	 * @since 1.1.80
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Bulk action being performed.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string Redirect URL.
	 */
	public static function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( 'export_assistant' !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect_to;
		}

		$ids = array_map( 'absint', (array) $post_ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return $redirect_to;
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wp_mcp_ai_export_assistant&ids=' . implode( ',', $ids ) ),
			self::NONCE_ACTION
		);
	}

	/**
	 * Stream an assistant export bundle as a JSON download.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	public static function handle_admin_post_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export assistants.', 'mcp-ai-wpoos' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$ids    = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$single = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';
		$format = in_array( $format, array( 'json', 'a2a' ), true ) ? $format : 'json';

		// Resolve the export payload.
		if ( 'a2a' === $format && $single ) {
			$payload = WP_MCP_AI_Assistant_Portability::export_a2a_card( $single );
			$suffix  = 'a2a-card';
		} else {
			if ( '' !== $ids ) {
				$ids = array_values( array_unique( array_map( 'absint', explode( ',', $ids ) ) ) );
			} elseif ( $single ) {
				$ids = array( $single );
			} else {
				$ids = 'all';
			}

			$payload = WP_MCP_AI_Assistant_Portability::export_assistants(
				$ids,
				array( 'include_a2a' => isset( $_GET['include_a2a'] ) && '1' === sanitize_key( wp_unslash( $_GET['include_a2a'] ) ) )
			);
			$suffix  = 'assistants';
		}

		if ( is_wp_error( $payload ) ) {
			wp_die( esc_html( $payload->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$json     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$filename = sprintf( 'nvoos-%s-%s.json', $suffix, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		// The payload is generated server-side; echo it verbatim as a download.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}

	/**
	 * Handle the import form submission.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	public static function handle_admin_post_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import assistants.', 'mcp-ai-wpoos' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$mode = isset( $_POST['import_mode'] ) ? sanitize_key( wp_unslash( $_POST['import_mode'] ) ) : 'skip';
		$mode = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';

		$dry_run = isset( $_POST['import_dry_run'] ) && '1' === sanitize_key( wp_unslash( $_POST['import_dry_run'] ) );

		$status_override = isset( $_POST['import_status'] ) ? sanitize_key( wp_unslash( $_POST['import_status'] ) ) : '';
		$status_override = in_array( $status_override, array( 'draft', 'publish', 'private' ), true ) ? $status_override : '';

		$json = '';

		// File upload takes precedence over the pasted-JSON textarea.
		if ( isset( $_FILES['assistant_import_file'] ) && is_array( $_FILES['assistant_import_file'] ) ) {
			$file = $_FILES['assistant_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- contents are parsed as JSON and validated downstream.

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				self::store_report( new WP_Error( 'wp_mcp_ai_portability_upload_failed', __( 'The uploaded file failed to transfer.', 'mcp-ai-wpoos' ) ) );
				self::redirect_back();
			}

			if ( (int) $file['size'] > 2097152 ) {
				self::store_report( new WP_Error( 'wp_mcp_ai_portability_upload_too_large', __( 'The uploaded file exceeds the 2 MB limit.', 'mcp-ai-wpoos' ) ) );
				self::redirect_back();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an uploaded temp file, not a remote URL.
			$contents = file_get_contents( $file['tmp_name'] );

			if ( false === $contents ) {
				self::store_report( new WP_Error( 'wp_mcp_ai_portability_upload_read_failed', __( 'Could not read the uploaded file.', 'mcp-ai-wpoos' ) ) );
				self::redirect_back();
			}

			$json = $contents;
		} elseif ( isset( $_POST['assistant_import_json'] ) ) {
			$json = wp_unslash( $_POST['assistant_import_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as JSON and validated downstream.
		}

		if ( '' === trim( (string) $json ) ) {
			self::store_report( new WP_Error( 'wp_mcp_ai_portability_missing_payload', __( 'Provide an import file or paste a JSON payload.', 'mcp-ai-wpoos' ) ) );
			self::redirect_back();
		}

		$bundle = WP_MCP_AI_Assistant_Portability::parse_import( $json );

		if ( is_wp_error( $bundle ) ) {
			self::store_report( $bundle );
			self::redirect_back();
		}

		$report = WP_MCP_AI_Assistant_Portability::import_bundle(
			$bundle,
			array(
				'mode'            => $mode,
				'dry_run'         => $dry_run,
				'status_override' => $status_override,
			)
		);

		self::store_report( $report );
		self::redirect_back();
	}

	/**
	 * Persist an import report for the current user.
	 *
	 * @since 1.1.80
	 *
	 * @param array|WP_Error $report Import report or error.
	 * @return void
	 */
	protected static function store_report( $report ) {
		set_transient(
			self::REPORT_TRANSIENT . get_current_user_id(),
			array(
				'error' => is_wp_error( $report ) ? $report->get_error_message() : '',
				'data'  => is_wp_error( $report ) ? array() : $report,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirect back to the Import / Export page.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	protected static function redirect_back() {
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'mcp_ai_assistant',
					'page'      => 'mcp-ai-assistant-portability',
					'imported'  => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Register the Import / Export submenu page.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	public static function register_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Import / Export Assistants', 'mcp-ai-wpoos' ),
			__( 'Import / Export', 'mcp-ai-wpoos' ),
			'manage_options',
			'mcp-ai-assistant-portability',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the Import / Export page.
	 *
	 * @since 1.1.80
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'mcp-ai-wpoos' ), '', array( 'response' => 403 ) );
		}

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$import_url   = admin_url( 'admin-post.php' );
		$import_nonce = wp_create_nonce( self::NONCE_ACTION );

		$report = get_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		if ( is_array( $report ) ) {
			delete_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export Assistants', 'mcp-ai-wpoos' ); ?></h1>

			<?php if ( is_array( $report ) ) : ?>
				<?php if ( '' !== $report['error'] ) : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php echo esc_html( $report['error'] ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-success is-dismissible">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: created count, 2: updated count, 3: skipped count, 4: error count */
									__( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d errors.', 'mcp-ai-wpoos' ),
									$report['data']['created'],
									$report['data']['updated'],
									$report['data']['skipped'],
									$report['data']['errors']
								)
							);
							?>
						</p>
					</div>
					<?php if ( ! empty( $report['data']['items'] ) ) : ?>
						<table class="widefat striped" style="max-width: 720px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Assistant', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Result', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $report['data']['items'] as $item ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $item['title'] ) ? $item['title'] : '-' ); ?></td>
									<td><?php echo esc_html( isset( $item['status'] ) ? $item['status'] : '-' ); ?></td>
									<td>
										<?php
										if ( ! empty( $item['assistant_id'] ) ) {
											printf(
												'<a href="%s">#%d</a>',
												esc_url( get_edit_post_link( $item['assistant_id'] ) ),
												esc_html( $item['assistant_id'] )
											);
										} elseif ( ! empty( $item['message'] ) ) {
											echo esc_html( $item['message'] );
										} elseif ( ! empty( $item['action'] ) ) {
											echo esc_html(
												sprintf(
												/* translators: %s: would-be action (create/update) */
													__( 'Would %s', 'mcp-ai-wpoos' ),
													$item['action']
												)
											);
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<div class="card" style="max-width: 720px; padding: 0 16px 16px;">
				<h2><?php esc_html_e( 'Export', 'mcp-ai-wpoos' ); ?></h2>
				<p><?php esc_html_e( 'Downloads a portable nvoos-assistant JSON bundle containing titles, prompts, tool assignments, model settings, and all plugin meta. Credential tokens are never exported.', 'mcp-ai-wpoos' ); ?></p>

				<?php if ( empty( $assistants ) ) : ?>
					<p><em><?php esc_html_e( 'No assistants to export yet.', 'mcp-ai-wpoos' ); ?></em></p>
				<?php else : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wp-mcp-ai-export-form">
						<input type="hidden" name="action" value="wp_mcp_ai_export_assistant" />
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" />
						<input type="hidden" name="ids" value="" id="wp-mcp-ai-export-ids" />

						<table class="widefat striped">
							<thead>
								<tr>
									<td class="check-column"><input type="checkbox" id="wp-mcp-ai-export-select-all" /></td>
									<th><?php esc_html_e( 'Assistant', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $assistants as $assistant ) : ?>
								<tr>
									<td class="check-column"><input type="checkbox" class="wp-mcp-ai-export-checkbox" value="<?php echo esc_attr( $assistant->ID ); ?>" /></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $assistant->ID ) ); ?>">
											<?php echo esc_html( $assistant->post_title ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $assistant->post_status ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>

						<p>
							<label>
								<input type="checkbox" name="include_a2a" value="1" checked="checked" />
								<?php esc_html_e( 'Embed A2A agent cards', 'mcp-ai-wpoos' ); ?>
							</label>
						</p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Export selected', 'mcp-ai-wpoos' ); ?></button>
							<button type="button" class="button" id="wp-mcp-ai-export-all-btn"><?php esc_html_e( 'Export all', 'mcp-ai-wpoos' ); ?></button>
						</p>
					</form>
					<script type="text/javascript">
						(function () {
							var form = document.getElementById('wp-mcp-ai-export-form');
							var idsInput = document.getElementById('wp-mcp-ai-export-ids');
							var selectAll = document.getElementById('wp-mcp-ai-export-select-all');
							var checkboxes = document.querySelectorAll('.wp-mcp-ai-export-checkbox');

							selectAll.addEventListener('change', function () {
								checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
							});

							form.addEventListener('submit', function (event) {
								var selected = [];
								checkboxes.forEach(function (cb) {
									if (cb.checked) { selected.push(cb.value); }
								});
								if (selected.length === 0) {
									event.preventDefault();
									window.alert(<?php echo wp_json_encode( __( 'Select at least one assistant to export.', 'mcp-ai-wpoos' ) ); ?>);
									return;
								}
								idsInput.value = selected.join(',');
							});

							document.getElementById('wp-mcp-ai-export-all-btn').addEventListener('click', function () {
								checkboxes.forEach(function (cb) { cb.checked = true; });
								form.submit();
							});
						})();
					</script>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width: 720px; padding: 0 16px 16px;">
				<h2><?php esc_html_e( 'Import', 'mcp-ai-wpoos' ); ?></h2>
				<p><?php esc_html_e( 'Imports an nvoos-assistant bundle, a legacy CLI export, or a blueprint JSON file. Credential hashes in the payload are ignored. A dry run previews the result without writing anything.', 'mcp-ai-wpoos' ); ?></p>

				<form method="post" action="<?php echo esc_url( $import_url ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="wp_mcp_ai_import_assistant" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $import_nonce ); ?>" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="assistant_import_file"><?php esc_html_e( 'Upload JSON file', 'mcp-ai-wpoos' ); ?></label></th>
							<td><input type="file" id="assistant_import_file" name="assistant_import_file" accept=".json,application/json" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="assistant_import_json"><?php esc_html_e( 'Or paste JSON', 'mcp-ai-wpoos' ); ?></label></th>
							<td>
								<textarea id="assistant_import_json" name="assistant_import_json" rows="8" cols="60" class="large-text code" placeholder='{"format":"nvoos-assistant",...}'></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="import_mode"><?php esc_html_e( 'Duplicate handling', 'mcp-ai-wpoos' ); ?></label></th>
							<td>
								<select id="import_mode" name="import_mode">
									<option value="skip"><?php esc_html_e( 'Skip existing assistants (default)', 'mcp-ai-wpoos' ); ?></option>
									<option value="overwrite"><?php esc_html_e( 'Overwrite existing assistants', 'mcp-ai-wpoos' ); ?></option>
									<option value="duplicate"><?php esc_html_e( 'Always create new assistants', 'mcp-ai-wpoos' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="import_status"><?php esc_html_e( 'Force status', 'mcp-ai-wpoos' ); ?></label></th>
							<td>
								<select id="import_status" name="import_status">
									<option value=""><?php esc_html_e( 'Keep the status from the file', 'mcp-ai-wpoos' ); ?></option>
									<option value="draft"><?php esc_html_e( 'Draft', 'mcp-ai-wpoos' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish', 'mcp-ai-wpoos' ); ?></option>
									<option value="private"><?php esc_html_e( 'Private', 'mcp-ai-wpoos' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dry run', 'mcp-ai-wpoos' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="import_dry_run" value="1" />
									<?php esc_html_e( 'Preview only — do not write anything', 'mcp-ai-wpoos' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'mcp-ai-wpoos' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
