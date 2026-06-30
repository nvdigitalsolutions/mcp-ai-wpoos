<?php
/**
 * Graphify — Remote Sources Admin UI
 *
 * Renders the Remote Sources tab in the Graphify settings page and handles
 * all AJAX actions for CRUD on remote source records.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI class for Graphify Remote Sources.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_Admin {

	/**
	 * Register AJAX hooks and the reindex cron callback.
	 *
	 * @since 0.6.0
	 */
	public static function init() {
		add_action( 'wp_ajax_nvoos_graphify_save_remote_source', array( __CLASS__, 'ajax_save_source' ) );
		add_action( 'wp_ajax_nvoos_graphify_delete_remote_source', array( __CLASS__, 'ajax_delete_source' ) );
		add_action( 'wp_ajax_nvoos_graphify_test_remote_source', array( __CLASS__, 'ajax_test_source' ) );
		add_action( 'wp_ajax_nvoos_graphify_sync_remote_source', array( __CLASS__, 'ajax_sync_source' ) );
		add_action( 'wp_ajax_nvoos_graphify_reindex_embeddings', array( __CLASS__, 'ajax_reindex_embeddings' ) );
		add_action( 'wp_ajax_nvoos_graphify_validate_field_map', array( __CLASS__, 'ajax_validate_field_map' ) );
		if ( class_exists( 'NV_oOS_Graphify_Embeddings' ) ) {
			add_action( 'nvoos_graphify_cron_reindex_embeddings', array( 'NV_oOS_Graphify_Embeddings', 'reindex_all' ) );
		}
	}

	/**
	 * Render the Remote Sources tab HTML (called from settings page).
	 *
	 * @since 0.6.0
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry     = NV_oOS_Graphify_Remote_Registry::get_instance();
		$driver_slugs = $registry->get_registered_driver_slugs();
		$configured   = NV_oOS_Graphify_DB::list_remote_sources( array() );
		?>
		<div class="nvoos-graphify-remote-sources">
			<h2><?php esc_html_e( 'Remote Sources', 'nvoos-graphify' ); ?></h2>
			<p><?php esc_html_e( 'Configure external knowledge-graph data sources to enrich nodes with data from Wikidata, remote oOS sites, SPARQL endpoints, REST APIs, or RSS/Sitemap feeds.', 'nvoos-graphify' ); ?></p>

			<?php if ( empty( $driver_slugs ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No remote source drivers are registered.', 'nvoos-graphify' ); ?></p></div>
			<?php else : ?>
				<h3><?php esc_html_e( 'Add New Source', 'nvoos-graphify' ); ?></h3>
				<div class="nvoos-remote-driver-cards">
					<?php
					foreach ( $driver_slugs as $driver_slug ) :
						$driver = $registry->get_driver( $driver_slug );
						if ( ! $driver ) {
							continue;
						}
						$caps = $driver->get_capabilities();
						?>
						<div class="nvoos-driver-card">
							<strong><?php echo esc_html( $driver->get_driver_label() ); ?></strong>
							<p><?php echo esc_html( implode( ', ', $caps ) ); ?></p>
							<button type="button" class="button button-secondary nvoos-add-source-btn"
								data-driver="<?php echo esc_attr( $driver_slug ); ?>"
								data-label="<?php echo esc_attr( $driver->get_driver_label() ); ?>"
								data-schema="<?php echo esc_attr( wp_json_encode( $driver->get_config_schema() ) ); ?>">
								<?php esc_html_e( 'Add', 'nvoos-graphify' ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Configured Sources', 'nvoos-graphify' ); ?></h3>
			<?php if ( empty( $configured ) ) : ?>
				<p><?php esc_html_e( 'No remote sources configured yet.', 'nvoos-graphify' ); ?></p>
			<?php else : ?>
				<table class="widefat nvoos-remote-sources-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Driver', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Circuit', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Last Sync', 'nvoos-graphify' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'nvoos-graphify' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $configured as $source ) : ?>
							<tr id="nvoos-source-row-<?php echo esc_attr( $source->slug ); ?>">
								<td><?php echo esc_html( $source->label ); ?></td>
								<td><code><?php echo esc_html( $source->slug ); ?></code></td>
								<td><?php echo esc_html( $source->driver ); ?></td>
								<td><?php echo $source->enabled ? esc_html__( 'Yes', 'nvoos-graphify' ) : esc_html__( 'No', 'nvoos-graphify' ); ?></td>
								<td>
									<span class="nvoos-circuit-badge nvoos-circuit-<?php echo esc_attr( $source->circuit_state ?? 'closed' ); ?>">
										<?php echo esc_html( $source->circuit_state ?? 'closed' ); ?>
									</span>
								</td>
								<td><?php echo $source->last_sync_at ? esc_html( $source->last_sync_at ) : esc_html__( 'Never', 'nvoos-graphify' ); ?></td>
								<td>
									<button type="button" class="button button-small nvoos-sync-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_graphify_remote_action' ) ); ?>">
										<?php esc_html_e( 'Sync', 'nvoos-graphify' ); ?>
									</button>
									<button type="button" class="button button-small nvoos-test-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_graphify_remote_action' ) ); ?>">
										<?php esc_html_e( 'Test', 'nvoos-graphify' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete nvoos-delete-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_graphify_remote_action' ) ); ?>">
										<?php esc_html_e( 'Delete', 'nvoos-graphify' ); ?>
									</button>
								</td>
							</tr>
							<?php if ( ! empty( $source->last_error ) ) : ?>
								<tr>
									<td colspan="7" class="nvoos-source-error">
										<small><?php echo esc_html( $source->last_error ); ?></small>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::render_source_modal(); ?>
		</div>
		<?php
		self::enqueue_admin_js();
	}

	/**
	 * Render the Embeddings panel (called from settings page Embeddings tab).
	 *
	 * @since 0.6.0
	 */
	public static function render_embeddings_panel() {
		global $wpdb;
		$emb_table = NV_oOS_Graphify_DB::embeddings_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$emb_table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$settings = NV_oOS_Graphify::get_settings();
		$model    = isset( $settings['embeddings_model'] ) ? $settings['embeddings_model'] : NV_oOS_Graphify_Embeddings::DEFAULT_MODEL;
		$nonce    = wp_create_nonce( 'nvoos_graphify_reindex' );
		?>
		<div class="nvoos-embeddings-panel">
			<h3><?php esc_html_e( 'Embeddings Index', 'nvoos-graphify' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %d number of stored embeddings */
					esc_html__( 'Stored embeddings: %d', 'nvoos-graphify' ),
					absint( $count )
				);
				?>
			</p>
			<p>
				<label for="nvoos-embeddings-model"><?php esc_html_e( 'Model:', 'nvoos-graphify' ); ?></label>
				<code id="nvoos-embeddings-model"><?php echo esc_html( $model ); ?></code>
			</p>
			<button type="button" class="button button-secondary" id="nvoos-reindex-btn"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Re-index All Nodes', 'nvoos-graphify' ); ?>
			</button>
			<span id="nvoos-reindex-status" style="margin-left:8px;"></span>
		</div>
		<script>
		(function($){
			$(function(){
				// Guard: avoid double-binding if the same handler is also rendered
				// by the Remote tab modal markup on the same page load.
				if ( window.nvoosGraphifyReindexBound ) { return; }
				window.nvoosGraphifyReindexBound = true;

				$(document).on('click', '#nvoos-reindex-btn', function(){
					var btn    = $(this).prop('disabled', true);
					var status = $('#nvoos-reindex-status').text('<?php echo esc_js( __( 'Reindexing…', 'nvoos-graphify' ) ); ?>');
					$.post(ajaxurl, {action:'nvoos_graphify_reindex_embeddings', nonce:btn.data('nonce')}, function(res){
						btn.prop('disabled', false);
						if ( res && res.success ) {
							var processed = (res.data && res.data.processed) || 0;
							var failed    = (res.data && res.data.failed) || 0;
							var msg       = '<?php echo esc_js( __( 'Done. Stored:', 'nvoos-graphify' ) ); ?> ' + processed;
							if ( failed > 0 ) {
								msg += ' · <?php echo esc_js( __( 'Failed:', 'nvoos-graphify' ) ); ?> ' + failed
									+ ' (<?php echo esc_js( __( 'check OpenAI API key in NV oOS settings', 'nvoos-graphify' ) ); ?>)';
							}
							status.text(msg);
						} else {
							status.text('Error: ' + ((res && res.data) || 'unknown'));
						}
					}).fail(function(xhr){
						btn.prop('disabled', false);
						status.text('Error: ' + (xhr.statusText || 'request failed'));
					});
				});
			});
		}(jQuery));
		</script>
		<?php
	}

	/**
	 * AJAX: save (create/update) a remote source.
	 *
	 * @since 0.6.0
	 */
	public static function ajax_save_source() {
		check_ajax_referer( 'nvoos_graphify_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- already checked above.
		$slug    = sanitize_key( isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '' );
		$driver  = sanitize_key( isset( $_POST['driver'] ) ? wp_unslash( $_POST['driver'] ) : '' );
		$label   = sanitize_text_field( isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '' );
		$enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
		$config  = array();

		if ( ! empty( $_POST['config'] ) && is_array( $_POST['config'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key below.
			$raw_config = wp_unslash( $_POST['config'] );
			foreach ( $raw_config as $k => $v ) {
				$config[ sanitize_key( $k ) ] = sanitize_text_field( $v );
			}
		}
		// phpcs:enable

		if ( empty( $slug ) || empty( $driver ) || empty( $label ) ) {
			wp_send_json_error( __( 'slug, driver, and label are required.', 'nvoos-graphify' ) );
		}

		$result = NV_oOS_Graphify_DB::save_remote_source(
			array(
				'slug'    => $slug,
				'driver'  => $driver,
				'label'   => $label,
				'enabled' => $enabled,
				'config'  => $config,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'slug' => $slug ) );
	}

	/**
	 * AJAX: delete a remote source.
	 *
	 * @since 0.6.0
	 */
	public static function ajax_delete_source() {
		check_ajax_referer( 'nvoos_graphify_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-graphify' ) );
		}

		NV_oOS_Graphify_DB::delete_remote_source( $slug );
		wp_send_json_success();
	}

	/**
	 * AJAX: test a remote source connection.
	 *
	 * @since 0.6.0
	 */
	public static function ajax_test_source() {
		check_ajax_referer( 'nvoos_graphify_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-graphify' ) );
		}

		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$sources  = $registry->get_active_sources();

		if ( ! isset( $sources[ $slug ] ) ) {
			wp_send_json_error( __( 'Source not found or not enabled.', 'nvoos-graphify' ) );
		}

		$driver = $sources[ $slug ];
		$result = $driver->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: trigger a manual sync for a source.
	 *
	 * @since 0.6.0
	 */
	public static function ajax_sync_source() {
		check_ajax_referer( 'nvoos_graphify_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-graphify' ) );
		}

		$enricher = new NV_oOS_Graphify_Remote_Enricher();
		$summary  = $enricher->sync_source( $slug, false );

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( $summary->get_error_message() );
		}
		wp_send_json_success( $summary );
	}

	/**
	 * AJAX: kick off an embeddings reindex.
	 *
	 * @since 0.6.0
	 */
	public static function ajax_reindex_embeddings() {
		check_ajax_referer( 'nvoos_graphify_reindex', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		delete_option( 'nvoos_graphify_reindex_offset' );
		$result = NV_oOS_Graphify_Embeddings::reindex_all();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: validate a field-map JSON string and return structured
	 * errors/warnings/fields. Optionally validates against a sample
	 * record when one is provided in the request.
	 *
	 * Expects POST: nonce, field_map (string), sample (optional JSON string).
	 *
	 * @since 0.7.9
	 */
	public static function ajax_validate_field_map() {
		check_ajax_referer( 'nvoos_graphify_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$json   = isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON validated below.
		$sample = isset( $_POST['sample'] ) ? wp_unslash( $_POST['sample'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON validated below.

		if ( ! is_string( $json ) ) {
			wp_send_json_error( __( 'Field map must be a string.', 'nvoos-graphify' ) );
		}

		if ( is_string( $sample ) && '' !== trim( $sample ) ) {
			$decoded = json_decode( $sample, true );
			if ( is_array( $decoded ) ) {
				wp_send_json_success( NV_oOS_Graphify_Field_Map_Validator::validate_against_sample( $json, $decoded ) );
			}
		}
		wp_send_json_success( NV_oOS_Graphify_Field_Map_Validator::validate( $json ) );
	}

	/**
	 * Render the modal markup used by the "Add" buttons.
	 *
	 * @since 0.6.0
	 */
	private static function render_source_modal() {
		?>
		<div id="nvoos-remote-source-modal" style="display:none;">
			<div class="nvoos-modal-overlay">
				<div class="nvoos-modal-content">
					<h3 id="nvoos-modal-title"><?php esc_html_e( 'Add Remote Source', 'nvoos-graphify' ); ?></h3>
					<form id="nvoos-remote-source-form">
						<table class="form-table">
							<tr>
								<th scope="row"><label for="nvoos-source-label"><?php esc_html_e( 'Label', 'nvoos-graphify' ); ?></label></th>
								<td><input type="text" id="nvoos-source-label" name="label" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="nvoos-source-slug"><?php esc_html_e( 'Slug', 'nvoos-graphify' ); ?></label></th>
								<td><input type="text" id="nvoos-source-slug" name="slug" class="regular-text" required
									pattern="[a-z0-9_-]+" title="<?php esc_attr_e( 'Lowercase letters, numbers, hyphens and underscores only.', 'nvoos-graphify' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enabled', 'nvoos-graphify' ); ?></th>
								<td><label><input type="checkbox" name="enabled" value="1" checked> <?php esc_html_e( 'Active', 'nvoos-graphify' ); ?></label></td>
							</tr>
						</table>
						<input type="hidden" name="driver" id="nvoos-source-driver" value="">
						<div id="nvoos-source-config-fields"></div>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Source', 'nvoos-graphify' ); ?></button>
							<button type="button" class="button button-secondary" id="nvoos-modal-cancel"><?php esc_html_e( 'Cancel', 'nvoos-graphify' ); ?></button>
						</p>
					</form>
					<div id="nvoos-modal-message"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the inline admin JS for remote sources UI interactions.
	 *
	 * @since 0.6.0
	 */
	private static function enqueue_admin_js() {
		?>
		<script>
		(function($){
			var nonce = '<?php echo esc_js( wp_create_nonce( 'nvoos_graphify_remote_action' ) ); ?>';

			// Open modal for adding a new source.
			$('.nvoos-add-source-btn').on('click', function(){
				var driver = $(this).data('driver');
				var label  = $(this).data('label');
				var schema = $(this).data('schema') || {};
				if (typeof schema === 'string') { try { schema = JSON.parse(schema); } catch(e){ schema = {}; } }
				$('#nvoos-source-driver').val(driver);
				$('#nvoos-modal-title').text('<?php echo esc_js( __( 'Add Remote Source', 'nvoos-graphify' ) ); ?>: ' + label);
				$('#nvoos-source-config-fields').html(buildFields(schema));
				$('#nvoos-remote-source-modal').show();
			});

			$('#nvoos-modal-cancel').on('click', function(){
				$('#nvoos-remote-source-modal').hide();
			});

			// Save source form submission.
			$('#nvoos-remote-source-form').on('submit', function(e){
				e.preventDefault();
				var data = { action:'nvoos_graphify_save_remote_source', nonce:nonce };
				$(this).serializeArray().forEach(function(f){ data[f.name] = f.value; });
				$.post(ajaxurl, data, function(res){
					if(res.success){ location.reload(); }
					else { $('#nvoos-modal-message').text(res.data||'Error'); }
				});
			});

			// Live field-map validation: when a textarea named "field_map"
			// is edited inside the modal, debounce-validate via AJAX and
			// render structured feedback below the input.
			var fmTimer = null;
			$(document).on('input', 'textarea[name="field_map"]', function(){
				var $ta = $(this);
				var $fb = $ta.siblings('.nvoos-fieldmap-feedback');
				if (!$fb.length) {
					$fb = $('<div class="nvoos-fieldmap-feedback" style="margin-top:6px;font-size:12px;"></div>');
					$ta.after($fb);
				}
				clearTimeout(fmTimer);
				fmTimer = setTimeout(function(){
					var val = $ta.val();
					if (!val || !val.trim()) { $fb.html(''); return; }
					$.post(ajaxurl, {
						action: 'nvoos_graphify_validate_field_map',
						nonce: nonce,
						field_map: val
					}, function(res){
						if (!res || !res.success || !res.data) { $fb.html(''); return; }
						var d = res.data;
						var html = '';
						if (d.valid) {
							html += '<span style="color:#1a7f37;">\u2713 <?php echo esc_js( __( 'Valid map', 'nvoos-graphify' ) ); ?></span>';
							if (d.fields && d.fields.length) {
								html += ' <span style="color:#666;">(' + d.fields.length + ' <?php echo esc_js( __( 'paths', 'nvoos-graphify' ) ); ?>)</span>';
							}
						} else {
							html += '<span style="color:#b32d2e;">\u2717 <?php echo esc_js( __( 'Invalid map', 'nvoos-graphify' ) ); ?></span>';
						}
						if (d.errors && d.errors.length) {
							html += '<ul style="color:#b32d2e;margin:4px 0 0 18px;">';
							d.errors.forEach(function(e){ html += '<li>' + $('<div>').text(e).html() + '</li>'; });
							html += '</ul>';
						}
						if (d.warnings && d.warnings.length) {
							html += '<ul style="color:#bf8700;margin:4px 0 0 18px;">';
							d.warnings.forEach(function(w){ html += '<li>' + $('<div>').text(w).html() + '</li>'; });
							html += '</ul>';
						}
						$fb.html(html);
					});
				}, 350);
			});

			// Sync button.
			$('.nvoos-sync-source-btn').on('click', function(){
				var slug = $(this).data('slug');
				var btn  = $(this).prop('disabled', true).text('...');
				$.post(ajaxurl, {action:'nvoos_graphify_sync_remote_source', nonce:nonce, slug:slug}, function(res){
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sync', 'nvoos-graphify' ) ); ?>');
					alert(res.success ? JSON.stringify(res.data) : ('Error: '+(res.data||'unknown')));
				});
			});

			// Test button.
			$('.nvoos-test-source-btn').on('click', function(){
				var slug = $(this).data('slug');
				$.post(ajaxurl, {action:'nvoos_graphify_test_remote_source', nonce:nonce, slug:slug}, function(res){
					alert(res.success ? '<?php echo esc_js( __( 'Connection OK', 'nvoos-graphify' ) ); ?>' : ('Error: '+(res.data||'unknown')));
				});
			});

			// Delete button.
			$('.nvoos-delete-source-btn').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'Delete this source?', 'nvoos-graphify' ) ); ?>')) { return; }
				var slug = $(this).data('slug');
				$.post(ajaxurl, {action:'nvoos_graphify_delete_remote_source', nonce:nonce, slug:slug}, function(res){
					if(res.success){ $('#nvoos-source-row-' + slug).closest('tr').remove(); }
					else { alert('Error: '+(res.data||'unknown')); }
				});
			});

			// Reindex embeddings.
			$('#nvoos-reindex-btn').on('click', function(){
				var btn    = $(this).prop('disabled', true);
				var status = $('#nvoos-reindex-status').text('<?php echo esc_js( __( 'Reindexing…', 'nvoos-graphify' ) ); ?>');
				$.post(ajaxurl, {action:'nvoos_graphify_reindex_embeddings', nonce:$(this).data('nonce')}, function(res){
					btn.prop('disabled', false);
					if ( res && res.success ) {
						var processed = (res.data && res.data.processed) || 0;
						var failed    = (res.data && res.data.failed) || 0;
						var msg       = '<?php echo esc_js( __( 'Done. Stored:', 'nvoos-graphify' ) ); ?> ' + processed;
						if ( failed > 0 ) {
							msg += ' · <?php echo esc_js( __( 'Failed:', 'nvoos-graphify' ) ); ?> ' + failed
								+ ' (<?php echo esc_js( __( 'check OpenAI API key in NV oOS settings', 'nvoos-graphify' ) ); ?>)';
						}
						status.text(msg);
					} else {
						status.text('Error: ' + ((res && res.data) || 'unknown'));
					}
				});
			});

			function buildFields(schema){
				if(!schema||!schema.properties){ return ''; }
				var html = '<table class="form-table"><tbody>';
				Object.keys(schema.properties).forEach(function(key){
					var field = schema.properties[key];
					var type  = field.secret ? 'password' : 'text';
					html += '<tr><th scope="row"><label>' + (field.label||key) + '</label></th>';
					html += '<td><input type="'+type+'" name="config['+key+']" class="regular-text"';
					if(field.required){ html += ' required'; }
					html += '> <p class="description">' + (field.description||'') + '</p></td></tr>';
				});
				html += '</tbody></table>';
				return html;
			}
		}(jQuery));
		</script>
		<?php
	}
}
