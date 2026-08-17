<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

use NvoosContentGraph\Plugin;

use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function delete_option;
use function esc_attr;
use function esc_attr_e;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function implode;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function sanitize_key;
use function sanitize_text_field;
use function trim;
use function update_option;
use function wp_create_nonce;
use function wp_json_encode;
use function wp_schedule_single_event;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Content Graph — Remote Sources Admin UI
 *
 * Renders the Remote Sources tab in the Content Graph settings page and handles
 * all AJAX actions for CRUD on remote source records.
 *
 * @package NvoosContentGraph
 * @since   1.0.0
 */

/**
 * Admin UI class for Content Graph Remote Sources.
 *
 * @since 1.0.0
 */
class RemoteAdmin {

	/**
	 * Register AJAX hooks and the reindex cron callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_nvoos_content_graph_save_remote_source', array( $this, 'ajaxSaveSource' ) );
		add_action( 'wp_ajax_nvoos_content_graph_delete_remote_source', array( $this, 'ajaxDeleteSource' ) );
		add_action( 'wp_ajax_nvoos_content_graph_test_remote_source', array( $this, 'ajaxTestSource' ) );
		add_action( 'wp_ajax_nvoos_content_graph_sync_remote_source', array( $this, 'ajaxSyncSource' ) );
		add_action( 'wp_ajax_nvoos_content_graph_reindex_embeddings', array( $this, 'ajaxReindexEmbeddings' ) );
		add_action( 'wp_ajax_nvoos_content_graph_validate_field_map', array( $this, 'ajaxValidateFieldMap' ) );

		// Enqueue remote-admin JS on the Content Graph settings page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueRemoteAdminAssets' ) );
	}

	/**
	 * Render the Remote Sources tab HTML (called from settings page).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function renderTab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry     = Plugin::instance()->getRemoteRegistry();
		$drivers      = $registry->allDrivers();
		$driver_slugs = array_keys( $drivers );
		$configured   = \NvoosContentGraph\Graph\Db::listRemoteSources( array() );
		?>
		<div class="nvoos-content-graph-remote-sources">
			<h2><?php esc_html_e( 'Remote Sources', 'nvoos-content-graph' ); ?></h2>
			<p><?php esc_html_e( 'Configure external knowledge-graph data sources to enrich nodes with data from Wikidata, remote oOS sites, SPARQL endpoints, REST APIs, or RSS/Sitemap feeds.', 'nvoos-content-graph' ); ?></p>

			<?php if ( empty( $driver_slugs ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No remote source drivers are registered.', 'nvoos-content-graph' ); ?></p></div>
			<?php else : ?>
				<h3><?php esc_html_e( 'Add New Source', 'nvoos-content-graph' ); ?></h3>
				<div class="nvoos-remote-driver-cards">
					<?php
					foreach ( $driver_slugs as $driver_slug ) :
						$driver = $registry->getDriver( $driver_slug );
						if ( ! $driver ) {
							continue;
						}
						$caps = $driver->getCapabilities();
						?>
						<div class="nvoos-driver-card">
							<strong><?php echo esc_html( $driver->getDriverLabel() ); ?></strong>
							<p><?php echo esc_html( implode( ', ', $caps ) ); ?></p>
							<button type="button" class="button button-secondary nvoos-add-source-btn"
								data-driver="<?php echo esc_attr( $driver_slug ); ?>"
								data-label="<?php echo esc_attr( $driver->getDriverLabel() ); ?>"
								data-schema="<?php echo esc_attr( wp_json_encode( $driver->getConfigSchema() ) ); ?>">
								<?php esc_html_e( 'Add', 'nvoos-content-graph' ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Configured Sources', 'nvoos-content-graph' ); ?></h3>
			<?php if ( empty( $configured ) ) : ?>
				<p><?php esc_html_e( 'No remote sources configured yet.', 'nvoos-content-graph' ); ?></p>
			<?php else : ?>
				<table class="widefat nvoos-remote-sources-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Driver', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Circuit', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Last Sync', 'nvoos-content-graph' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'nvoos-content-graph' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $configured as $source ) : ?>
							<tr id="nvoos-source-row-<?php echo esc_attr( $source->slug ); ?>">
								<td><?php echo esc_html( $source->label ); ?></td>
								<td><code><?php echo esc_html( $source->slug ); ?></code></td>
								<td><?php echo esc_html( $source->driver ); ?></td>
								<td><?php echo $source->enabled ? esc_html__( 'Yes', 'nvoos-content-graph' ) : esc_html__( 'No', 'nvoos-content-graph' ); ?></td>
								<td>
									<span class="nvoos-circuit-badge nvoos-circuit-<?php echo esc_attr( $source->circuit_state ?? 'closed' ); ?>">
										<?php echo esc_html( $source->circuit_state ?? 'closed' ); ?>
									</span>
								</td>
								<td><?php echo $source->last_sync_at ? esc_html( $source->last_sync_at ) : esc_html__( 'Never', 'nvoos-content-graph' ); ?></td>
								<td>
									<button type="button" class="button button-small nvoos-sync-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_content_graph_remote_action' ) ); ?>">
										<?php esc_html_e( 'Sync', 'nvoos-content-graph' ); ?>
									</button>
									<button type="button" class="button button-small nvoos-test-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_content_graph_remote_action' ) ); ?>">
										<?php esc_html_e( 'Test', 'nvoos-content-graph' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete nvoos-delete-source-btn"
										data-slug="<?php echo esc_attr( $source->slug ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvoos_content_graph_remote_action' ) ); ?>">
										<?php esc_html_e( 'Delete', 'nvoos-content-graph' ); ?>
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

			<?php self::renderSourceModal(); ?>
			</div>
			<?php
	}

	/**
	 * Render the Embeddings panel (called from settings page Embeddings tab).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function renderEmbeddingsPanel(): void {
		global $wpdb;
		$emb_table = \NvoosContentGraph\Graph\Db::embeddingsTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$emb_table}" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$settings = \NvoosContentGraph\Settings::all();
		$model    = isset( $settings['embeddings_model'] ) ? $settings['embeddings_model'] : 'text-embedding-3-small';
		$nonce    = wp_create_nonce( 'nvoos_content_graph_reindex' );
		?>
		<div class="nvoos-embeddings-panel">
			<h3><?php esc_html_e( 'Embeddings Index', 'nvoos-content-graph' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %d number of stored embeddings */
					esc_html__( 'Stored embeddings: %d', 'nvoos-content-graph' ),
					absint( $count )
				);
				?>
			</p>
			<p>
				<label for="nvoos-embeddings-model"><?php esc_html_e( 'Model:', 'nvoos-content-graph' ); ?></label>
				<code id="nvoos-embeddings-model"><?php echo esc_html( $model ); ?></code>
			</p>
			<button type="button" class="button button-secondary" id="nvoos-reindex-btn"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Re-index All Nodes', 'nvoos-content-graph' ); ?>
			</button>
			<span id="nvoos-reindex-status" style="margin-left:8px;"></span>
		</div>
		<?php
		// The reindex click handler lives in assets/js/content-graph-remote-admin.js,
		// enqueued via enqueueRemoteAdminAssets() with its i18n strings localized.
	}

	/**
	 * AJAX: save (create/update) a remote source.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxSaveSource(): void {
		check_ajax_referer( 'nvoos_content_graph_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
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
			wp_send_json_error( __( 'slug, driver, and label are required.', 'nvoos-content-graph' ) );
		}

		$result = \NvoosContentGraph\Graph\Db::saveRemoteSource(
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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxDeleteSource(): void {
		check_ajax_referer( 'nvoos_content_graph_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-content-graph' ) );
		}

		\NvoosContentGraph\Graph\Db::deleteRemoteSource( $slug );
		wp_send_json_success();
	}

	/**
	 * AJAX: test a remote source connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxTestSource(): void {
		check_ajax_referer( 'nvoos_content_graph_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-content-graph' ) );
		}

		$registry = Plugin::instance()->getRemoteRegistry();
		$sources  = \NvoosContentGraph\Graph\Db::listRemoteSources( array( 'slug' => $slug ) );

		if ( empty( $sources ) ) {
			wp_send_json_error( __( 'Source not found or not enabled.', 'nvoos-content-graph' ) );
		}

		$source = $sources[0];
		$driver = $registry->getDriver( $source->driver );
		if ( ! $driver ) {
			wp_send_json_error( __( 'Driver not registered.', 'nvoos-content-graph' ) );
		}

		$result = $driver->testConnection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: trigger a manual sync for a source.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxSyncSource(): void {
		check_ajax_referer( 'nvoos_content_graph_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'slug is required.', 'nvoos-content-graph' ) );
		}

		if ( ! class_exists( 'NvoosContentGraph\Remote\Enricher' ) ) {
			wp_send_json_error( __( 'Remote enrichment is not available.', 'nvoos-content-graph' ) );
		}

		$enricher = new \NvoosContentGraph\Remote\Enricher();
		$summary  = $enricher->syncSource( $slug );

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( $summary->get_error_message() );
		}
		wp_send_json_success( $summary );
	}

	/**
	 * AJAX: kick off an embeddings reindex.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxReindexEmbeddings(): void {
		check_ajax_referer( 'nvoos_content_graph_reindex', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
		}

		$result = self::reindexAllEmbeddings();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Generate embeddings for graph nodes via the AI addon's embedding
	 * service. Processes one batch per call; schedules the next batch via
	 * WP-Cron when more nodes remain.
	 *
	 * @since 1.0.2
	 *
	 * @return array<string,mixed>|\WP_Error Summary or error.
	 */
	public static function reindexAllEmbeddings() {
		if ( ! class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			return new \WP_Error(
				'nvoos_content_graph_no_ai_addon',
				__( 'The AI addon is not installed. Install the NV oOS Content Graph — AI addon to enable embeddings.', 'nvoos-content-graph' )
			);
		}

		$settings = \NvoosContentGraph\Settings::all();
		if ( empty( $settings['embeddings_enabled'] ) ) {
			delete_option( 'nvoos_content_graph_reindex_offset' );
			return array(
				'processed' => 0,
				'failed'    => 0,
				'remaining' => false,
			);
		}

		$bridge = \NvoosContentGraphAi\CoreBridge::instance();
		$embed  = $bridge->embeddings;

		$batch  = 50;
		$offset = (int) get_option( 'nvoos_content_graph_reindex_offset', 0 );
		$nodes  = \NvoosContentGraph\Graph\Db::listNodes(
			array(
				'order_by' => 'updated_at',
				'order'    => 'ASC',
				'limit'    => $batch,
				'offset'   => $offset,
			)
		);

		if ( empty( $nodes ) ) {
			delete_option( 'nvoos_content_graph_reindex_offset' );
			return array(
				'processed' => 0,
				'failed'    => 0,
				'remaining' => false,
			);
		}

		$processed = 0;
		$failed    = 0;
		foreach ( $nodes as $node ) {
			$text = $node->label ?? '';
			if ( ! empty( $node->properties ) ) {
				$props = is_string( $node->properties ) ? json_decode( $node->properties, true ) : (array) $node->properties;
				if ( is_array( $props ) && ! empty( $props['excerpt'] ) ) {
					$text .= ' ' . $props['excerpt'];
				}
			}

			$vector = $embed->embed( $text );
			if ( ! is_array( $vector ) || empty( $vector['vector'] ) ) {
				++$failed;
				continue;
			}

			$ok = \NvoosContentGraph\Graph\Db::upsertEmbedding(
				array(
					'node_id' => $node->node_id,
					'model'   => isset( $vector['model'] ) ? sanitize_text_field( $vector['model'] ) : 'text-embedding-3-small',
					'dim'     => isset( $vector['dim'] ) ? absint( $vector['dim'] ) : count( $vector['vector'] ),
					'vector'  => wp_json_encode( $vector['vector'] ),
				)
			);
			if ( $ok ) {
				++$processed;
			} else {
				++$failed;
			}
		}

		$total_seen = $processed + $failed;
		$remaining  = $total_seen >= $batch;
		if ( $remaining ) {
			update_option( 'nvoos_content_graph_reindex_offset', $offset + $total_seen );
			wp_schedule_single_event( time() + 60, 'nvoos_content_graph_cron_reindex_embeddings' );
		} else {
			delete_option( 'nvoos_content_graph_reindex_offset' );
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $remaining,
		);
	}

	/**
	 * AJAX: validate a field-map JSON string and return structured
	 * errors/warnings/fields. Optionally validates against a sample
	 * record when one is provided in the request.
	 *
	 * Expects POST: nonce, field_map (string), sample (optional JSON string).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajaxValidateFieldMap(): void {
		check_ajax_referer( 'nvoos_content_graph_remote_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nvoos-content-graph' ) );
		}

		$json   = isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON validated below.
		$sample = isset( $_POST['sample'] ) ? wp_unslash( $_POST['sample'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON validated below.

		if ( ! is_string( $json ) ) {
			wp_send_json_error( __( 'Field map must be a string.', 'nvoos-content-graph' ) );
		}

		if ( is_string( $sample ) && '' !== trim( $sample ) ) {
			$decoded = json_decode( $sample, true );
			if ( is_array( $decoded ) ) {
				wp_send_json_success( \NvoosContentGraph\Remote\FieldMapValidator::validate_against_sample( $json, $decoded ) );
			}
		}
		wp_send_json_success( \NvoosContentGraph\Remote\FieldMapValidator::validate( $json ) );
	}

	/**
	 * Render the modal markup used by the "Add" buttons.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function renderSourceModal(): void {
		?>
		<div id="nvoos-remote-source-modal" style="display:none;">
			<div class="nvoos-modal-overlay">
				<div class="nvoos-modal-content">
					<h3 id="nvoos-modal-title"><?php esc_html_e( 'Add Remote Source', 'nvoos-content-graph' ); ?></h3>
					<form id="nvoos-remote-source-form">
						<table class="form-table">
							<tr>
								<th scope="row"><label for="nvoos-source-label"><?php esc_html_e( 'Label', 'nvoos-content-graph' ); ?></label></th>
								<td><input type="text" id="nvoos-source-label" name="label" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="nvoos-source-slug"><?php esc_html_e( 'Slug', 'nvoos-content-graph' ); ?></label></th>
								<td><input type="text" id="nvoos-source-slug" name="slug" class="regular-text" required
									pattern="[a-z0-9_-]+" title="<?php esc_attr_e( 'Lowercase letters, numbers, hyphens and underscores only.', 'nvoos-content-graph' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enabled', 'nvoos-content-graph' ); ?></th>
								<td><label><input type="checkbox" name="enabled" value="1" checked> <?php esc_html_e( 'Active', 'nvoos-content-graph' ); ?></label></td>
							</tr>
						</table>
						<input type="hidden" name="driver" id="nvoos-source-driver" value="">
						<div id="nvoos-source-config-fields"></div>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Source', 'nvoos-content-graph' ); ?></button>
							<button type="button" class="button button-secondary" id="nvoos-modal-cancel"><?php esc_html_e( 'Cancel', 'nvoos-content-graph' ); ?></button>
						</p>
					</form>
					<div id="nvoos-modal-message"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the remote-admin JS file with localized config on the
	 * Content Graph settings page.  Replaces the previous inline <script>
	 * block, which could conflict with Content Security Policy headers
	 * and made the JS uncacheable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueRemoteAdminAssets( string $hook ): void {
		if ( false === strpos( $hook, \NvoosContentGraph\Admin\SettingsPage::PAGE_SLUG ) ) {
			return;
		}

		\wp_enqueue_script(
			'nvoos-content-graph-remote-admin',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-remote-admin.js',
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		\wp_localize_script(
			'nvoos-content-graph-remote-admin',
			'nvoosContentGraphRemoteAdmin',
			array(
				'ajaxurl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( 'nvoos_content_graph_remote_action' ),
				'i18n'    => array(
					'addSource'     => \__( 'Add Remote Source', 'nvoos-content-graph' ),
					'sync'          => \__( 'Sync', 'nvoos-content-graph' ),
					'connectionOk'  => \__( 'Connection OK', 'nvoos-content-graph' ),
					'deleteConfirm' => \__( 'Delete this source?', 'nvoos-content-graph' ),
					'reindexing'    => \__( 'Reindexing…', 'nvoos-content-graph' ),
					'doneStored'    => \__( 'Done. Stored:', 'nvoos-content-graph' ),
					'failed'        => \__( 'Failed:', 'nvoos-content-graph' ),
					'checkApiKey'   => \__( 'check OpenAI API key in NV oOS settings', 'nvoos-content-graph' ),
					'validMap'      => \__( 'Valid map', 'nvoos-content-graph' ),
					'invalidMap'    => \__( 'Invalid map', 'nvoos-content-graph' ),
					'paths'         => \__( 'paths', 'nvoos-content-graph' ),
				),
			)
		);
	}
}
