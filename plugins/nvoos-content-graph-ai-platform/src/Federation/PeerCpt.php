<?php
/**
 * AI Peer custom post type for federation directory.
 *
 * Stores peer site registrations with their capabilities, health status,
 * and metadata for discovery and routing.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/class-wp-mcp-ai-ai-peer-cpt.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the AI Peer CPT for the federation directory.
 */
class PeerCpt {

	const POST_TYPE = 'ai_peer';

	// Meta keys for peer data — byte-identical to the base plugin's CPT.
	const META_MCP_URL           = '_wp_mcp_ai_peer_mcp_url';
	const META_OPENAPI_URL       = '_wp_mcp_ai_peer_openapi_url';
	const META_JWKS_URI          = '_wp_mcp_ai_peer_jwks_uri';
	const META_CAPABILITIES      = '_wp_mcp_ai_peer_capabilities';
	const META_REGIONS           = '_wp_mcp_ai_peer_regions';
	const META_DATA_TAGS         = '_wp_mcp_ai_peer_data_tags';
	const META_POLICY_TAGS       = '_wp_mcp_ai_peer_policy_tags';
	const META_QUOTAS            = '_wp_mcp_ai_peer_quotas';
	const META_PRICE_HINTS       = '_wp_mcp_ai_peer_price_hints';
	const META_HEALTH_STATUS     = '_wp_mcp_ai_peer_health_status';
	const META_LATENCY_P50       = '_wp_mcp_ai_peer_latency_p50';
	const META_LAST_VERIFIED     = '_wp_mcp_ai_peer_last_verified';
	const META_LAST_ERROR        = '_wp_mcp_ai_peer_last_error';
	const META_SITE_NAME         = '_wp_mcp_ai_peer_site_name';
	const META_SITE_URL          = '_wp_mcp_ai_peer_site_url';
	const META_WELLKNOWN_URL     = '_wp_mcp_ai_peer_wellknown_url';
	const META_VERIFICATION_DATA = '_wp_mcp_ai_peer_verification_data';

	/**
	 * Sync lock timeout in seconds.
	 */
	const SYNC_LOCK_TIMEOUT = 5;

	/**
	 * Register the post type and hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_list_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_list_columns' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'sync_to_cct_on_save' ), 20, 2 );
		add_action( 'delete_' . self::POST_TYPE, array( $this, 'cleanup_cct_on_delete' ) );
	}

	/**
	 * Register the AI Peer custom post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'AI Peers', 'post type general name', 'nvoos-content-graph-ai-platform' ),
			'singular_name'      => _x( 'AI Peer', 'post type singular name', 'nvoos-content-graph-ai-platform' ),
			'menu_name'          => _x( 'AI Peers', 'admin menu', 'nvoos-content-graph-ai-platform' ),
			'name_admin_bar'     => _x( 'AI Peer', 'add new on admin bar', 'nvoos-content-graph-ai-platform' ),
			'add_new'            => _x( 'Add New', 'ai peer', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'       => __( 'Add New AI Peer', 'nvoos-content-graph-ai-platform' ),
			'new_item'           => __( 'New AI Peer', 'nvoos-content-graph-ai-platform' ),
			'edit_item'          => __( 'Edit AI Peer', 'nvoos-content-graph-ai-platform' ),
			'view_item'          => __( 'View AI Peer', 'nvoos-content-graph-ai-platform' ),
			'all_items'          => __( 'AI Peers', 'nvoos-content-graph-ai-platform' ),
			'search_items'       => __( 'Search AI Peers', 'nvoos-content-graph-ai-platform' ),
			'parent_item_colon'  => __( 'Parent AI Peers:', 'nvoos-content-graph-ai-platform' ),
			'not_found'          => __( 'No AI peers found.', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash' => __( 'No AI peers found in trash.', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_icon'          => 'dashicons-networking',
			'menu_position'      => null,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'capabilities'       => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'delete_posts'       => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			),
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title' ),
			'show_in_rest'       => true,
			'rest_base'          => 'ai-peers',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register meta fields for the AI Peer post type.
	 */
	public static function register_meta() {
		$meta_fields = array(
			self::META_MCP_URL,
			self::META_OPENAPI_URL,
			self::META_JWKS_URI,
			self::META_SITE_NAME,
			self::META_SITE_URL,
			self::META_WELLKNOWN_URL,
			self::META_HEALTH_STATUS,
			self::META_LAST_VERIFIED,
			self::META_LAST_ERROR,
		);

		foreach ( $meta_fields as $meta_key ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'description'       => '',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// Array meta fields.
		$array_meta_fields = array(
			self::META_CAPABILITIES,
			self::META_REGIONS,
			self::META_DATA_TAGS,
			self::META_POLICY_TAGS,
			self::META_QUOTAS,
			self::META_PRICE_HINTS,
			self::META_VERIFICATION_DATA,
		);

		foreach ( $array_meta_fields as $meta_key ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'         => 'string',
					'description'  => '',
					'single'       => true,
					'show_in_rest' => true,
				)
			);
		}

		// Numeric fields.
		register_post_meta(
			self::POST_TYPE,
			self::META_LATENCY_P50,
			array(
				'type'              => 'integer',
				'description'       => 'P50 latency in milliseconds',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			)
		);
	}

	/**
	 * Register meta boxes for the AI Peer post type.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'wp_mcp_ai_peer_info',
			__( 'Peer Information', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_peer_info_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wp_mcp_ai_peer_health',
			__( 'Health Status', 'nvoos-content-graph-ai-platform' ),
			array( $this, 'render_health_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render peer information meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_peer_info_meta_box( $post ) {
		$connection_type = get_post_meta( $post->ID, '_wp_mcp_ai_connection_type', true );
		$site_url        = get_post_meta( $post->ID, self::META_SITE_URL, true );
		$wellknown_url   = get_post_meta( $post->ID, self::META_WELLKNOWN_URL, true );
		$mcp_url         = get_post_meta( $post->ID, self::META_MCP_URL, true );
		$jwks_uri        = get_post_meta( $post->ID, self::META_JWKS_URI, true );
		$capabilities    = get_post_meta( $post->ID, self::META_CAPABILITIES, true );
		$regions         = get_post_meta( $post->ID, self::META_REGIONS, true );
		$data_tags       = get_post_meta( $post->ID, self::META_DATA_TAGS, true );

		$capabilities = is_string( $capabilities ) ? json_decode( $capabilities, true ) : array();
		$regions      = is_string( $regions ) ? json_decode( $regions, true ) : array();
		$data_tags    = is_string( $data_tags ) ? json_decode( $data_tags, true ) : array();

		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Connection Type', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( 'mesh' === $connection_type ) : ?>
						<span style="background: #7e57c2; color: white; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600;">
							<?php esc_html_e( 'MESH', 'nvoos-content-graph-ai-platform' ); ?>
						</span>
						<p class="description">
							<?php esc_html_e( 'This peer was configured manually via mesh networking settings.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					<?php else : ?>
						<span style="background: #2196f3; color: white; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600;">
							<?php esc_html_e( 'FEDERATION', 'nvoos-content-graph-ai-platform' ); ?>
						</span>
						<p class="description">
							<?php esc_html_e( 'This peer was registered through the federation directory service.', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Site URL', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( $site_url ) : ?>
						<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $site_url ); ?>
						</a>
					<?php else : ?>
						<em><?php esc_html_e( 'Not set', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Well-Known URL', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( $wellknown_url ) : ?>
						<a href="<?php echo esc_url( $wellknown_url ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $wellknown_url ); ?>
						</a>
					<?php else : ?>
						<em><?php esc_html_e( 'Not set', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'MCP Endpoint', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( $mcp_url ) : ?>
						<code><?php echo esc_html( $mcp_url ); ?></code>
					<?php else : ?>
						<em><?php esc_html_e( 'Not set', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'JWKS URI', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( $jwks_uri ) : ?>
						<a href="<?php echo esc_url( $jwks_uri ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $jwks_uri ); ?>
						</a>
					<?php else : ?>
						<em><?php esc_html_e( 'Not set', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Capabilities', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( ! empty( $capabilities ) ) : ?>
						<div style="max-height: 200px; overflow-y: auto;">
							<?php foreach ( $capabilities as $capability ) : ?>
								<span class="capability-tag" style="display: inline-block; background: #f0f0f1; padding: 4px 8px; margin: 2px; border-radius: 3px;">
									<?php echo esc_html( $capability ); ?>
								</span>
							<?php endforeach; ?>
						</div>
						<p class="description">
							<?php
							printf(
								// translators: %d: number of capabilities.
								esc_html__( '%d capabilities available', 'nvoos-content-graph-ai-platform' ),
								count( $capabilities )
							);
							?>
						</p>
					<?php else : ?>
						<em><?php esc_html_e( 'No capabilities', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Regions', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( ! empty( $regions ) ) : ?>
						<?php echo esc_html( implode( ', ', $regions ) ); ?>
					<?php else : ?>
						<em><?php esc_html_e( 'Global', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Data Tags', 'nvoos-content-graph-ai-platform' ); ?></label></th>
				<td>
					<?php if ( ! empty( $data_tags ) ) : ?>
						<?php echo esc_html( implode( ', ', $data_tags ) ); ?>
					<?php else : ?>
						<em><?php esc_html_e( 'None specified', 'nvoos-content-graph-ai-platform' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render health status meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_health_meta_box( $post ) {
		$health_status = get_post_meta( $post->ID, self::META_HEALTH_STATUS, true );
		$latency_p50   = get_post_meta( $post->ID, self::META_LATENCY_P50, true );
		$last_verified = get_post_meta( $post->ID, self::META_LAST_VERIFIED, true );
		$last_error    = get_post_meta( $post->ID, self::META_LAST_ERROR, true );

		$status_class = 'unknown';
		$status_label = __( 'Unknown', 'nvoos-content-graph-ai-platform' );

		if ( 'healthy' === $health_status ) {
			$status_class = 'healthy';
			$status_label = __( 'Healthy', 'nvoos-content-graph-ai-platform' );
		} elseif ( 'degraded' === $health_status ) {
			$status_class = 'degraded';
			$status_label = __( 'Degraded', 'nvoos-content-graph-ai-platform' );
		} elseif ( 'down' === $health_status ) {
			$status_class = 'down';
			$status_label = __( 'Down', 'nvoos-content-graph-ai-platform' );
		}

		?>
		<?php
		wp_add_inline_style(
			'wp-mcp-ai-ai-peer-cpt',
			'.health-status{padding:8px 12px;border-radius:4px;font-weight:600;text-align:center;margin-bottom:12px;}'
			. '.health-status.healthy{background:#d4edda;color:#155724;}'
			. '.health-status.degraded{background:#fff3cd;color:#856404;}'
			. '.health-status.down{background:#f8d7da;color:#721c24;}'
			. '.health-status.unknown{background:#e2e3e5;color:#383d41;}'
		);
		?>
		<div class="health-status <?php echo esc_attr( $status_class ); ?>">
			<?php echo esc_html( $status_label ); ?>
		</div>

		<?php if ( $latency_p50 ) : ?>
			<p>
				<strong><?php esc_html_e( 'Latency (P50):', 'nvoos-content-graph-ai-platform' ); ?></strong><br>
				<?php echo esc_html( $latency_p50 ); ?> ms
			</p>
		<?php endif; ?>

		<?php if ( $last_verified ) : ?>
			<p>
				<strong><?php esc_html_e( 'Last Verified:', 'nvoos-content-graph-ai-platform' ); ?></strong><br>
				<?php echo esc_html( human_time_diff( strtotime( $last_verified ), time() ) ); ?>
				<?php esc_html_e( 'ago', 'nvoos-content-graph-ai-platform' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $last_error ) : ?>
			<p>
				<strong><?php esc_html_e( 'Last Error:', 'nvoos-content-graph-ai-platform' ); ?></strong><br>
				<code style="font-size: 11px; display: block; max-height: 100px; overflow-y: auto;">
					<?php echo esc_html( $last_error ); ?>
				</code>
			</p>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-secondary" id="wp-mcp-ai-verify-peer">
				<?php esc_html_e( 'Verify Now', 'nvoos-content-graph-ai-platform' ); ?>
			</button>
		</p>

		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			$('#wp-mcp-ai-verify-peer').on('click', function(e) {
				e.preventDefault();
				var button = $(this);
				button.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Verifying...', 'nvoos-content-graph-ai-platform' ) ); ?>);

				$.ajax({
					url: <?php echo wp_json_encode( rest_url( 'ai-dir/v1/reverify/' . $post->ID ) ); ?>,
					method: 'POST',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>);
					},
					success: function(response) {
						alert(<?php echo wp_json_encode( __( 'Peer verification completed. Refresh the page to see results.', 'nvoos-content-graph-ai-platform' ) ); ?>);
						location.reload();
					},
					error: function(xhr) {
						var message = xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: <?php echo wp_json_encode( __( 'Verification failed. Check the error log.', 'nvoos-content-graph-ai-platform' ) ); ?>;
						alert(message);
						button.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Verify Now', 'nvoos-content-graph-ai-platform' ) ); ?>);
					}
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_print_inline_script_tag( $js );
		?>
		<?php
	}

	/**
	 * Add custom columns to the AI Peer list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_list_columns( $columns ) {
		$new_columns = array(
			'cb'              => $columns['cb'],
			'title'           => $columns['title'],
			'connection_type' => __( 'Type', 'nvoos-content-graph-ai-platform' ),
			'health'          => __( 'Health', 'nvoos-content-graph-ai-platform' ),
			'capabilities'    => __( 'Capabilities', 'nvoos-content-graph-ai-platform' ),
			'regions'         => __( 'Regions', 'nvoos-content-graph-ai-platform' ),
			'latency'         => __( 'Latency', 'nvoos-content-graph-ai-platform' ),
			'last_check'      => __( 'Last Check', 'nvoos-content-graph-ai-platform' ),
			'date'            => $columns['date'],
		);

		return $new_columns;
	}

	/**
	 * Render custom columns in the AI Peer list table.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_list_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'connection_type':
				$connection_type = get_post_meta( $post_id, '_wp_mcp_ai_connection_type', true );
				if ( 'mesh' === $connection_type ) {
					echo '<span style="background: #7e57c2; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">' . esc_html__( 'MESH', 'nvoos-content-graph-ai-platform' ) . '</span>';
				} else {
					echo '<span style="background: #2196f3; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">' . esc_html__( 'FEDERATION', 'nvoos-content-graph-ai-platform' ) . '</span>';
				}
				break;

			case 'health':
				$health_status = get_post_meta( $post_id, self::META_HEALTH_STATUS, true );
				$status_colors = array(
					'healthy'  => '#46b450',
					'degraded' => '#ffb900',
					'down'     => '#dc3232',
				);
				$status_labels = array(
					'healthy'  => __( 'Healthy', 'nvoos-content-graph-ai-platform' ),
					'degraded' => __( 'Degraded', 'nvoos-content-graph-ai-platform' ),
					'down'     => __( 'Down', 'nvoos-content-graph-ai-platform' ),
				);
				$color         = isset( $status_colors[ $health_status ] ) ? $status_colors[ $health_status ] : '#999';
				$label         = isset( $status_labels[ $health_status ] ) ? $status_labels[ $health_status ] : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
				echo '<span style="color: ' . esc_attr( $color ) . '; font-weight: 600;">● ' . esc_html( $label ) . '</span>';
				break;

			case 'capabilities':
				$capabilities = get_post_meta( $post_id, self::META_CAPABILITIES, true );
				$capabilities = is_string( $capabilities ) ? json_decode( $capabilities, true ) : array();
				if ( ! empty( $capabilities ) ) {
					echo esc_html( count( $capabilities ) ) . ' ' . esc_html__( 'tools', 'nvoos-content-graph-ai-platform' );
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static em dash character.
					echo '—';
				}
				break;

			case 'regions':
				$regions = get_post_meta( $post_id, self::META_REGIONS, true );
				$regions = is_string( $regions ) ? json_decode( $regions, true ) : array();
				if ( ! empty( $regions ) ) {
					echo esc_html( implode( ', ', array_slice( $regions, 0, 3 ) ) );
					if ( count( $regions ) > 3 ) {
						echo ' <span style="color: #999;">+' . esc_html( count( $regions ) - 3 ) . '</span>';
					}
				} else {
					echo esc_html__( 'Global', 'nvoos-content-graph-ai-platform' );
				}
				break;

			case 'latency':
				$latency = get_post_meta( $post_id, self::META_LATENCY_P50, true );
				if ( $latency ) {
					echo esc_html( $latency ) . ' ms';
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static em dash character.
					echo '—';
				}
				break;

			case 'last_check':
				$last_verified = get_post_meta( $post_id, self::META_LAST_VERIFIED, true );
				if ( $last_verified ) {
					echo esc_html( human_time_diff( strtotime( $last_verified ), time() ) ) . ' ' . esc_html__( 'ago', 'nvoos-content-graph-ai-platform' );
				} else {
					echo esc_html__( 'Never', 'nvoos-content-graph-ai-platform' );
				}
				break;
		}
	}

	/**
	 * Hook callback to sync CPT to CCT on save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function sync_to_cct_on_save( $post_id, $post ) {
		// Skip if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip if this is a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Sync to JetEngine CCT if available.
		$this->sync_to_cct( $post_id, $post );
	}

	/**
	 * Synchronize CPT data to the JetEngine ai_peers CCT.
	 *
	 * This ensures that API consumers using the JetEngine CCT endpoint
	 * have access to the same peer configuration as the CPT.
	 *
	 * In standalone mode the base plugin's CCT handler class is absent, so
	 * the sync is a no-op — the meta data remains available via the CPT.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	protected function sync_to_cct( $post_id, $post ) {
		// Only sync in Full Version when JetEngine is available.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT' ) ) {
			return;
		}

		// Prevent concurrent sync operations using a transient lock.
		$lock_key = 'wp_mcp_ai_peer_sync_lock_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			// Another sync is in progress, skip to prevent locking.
			return;
		}

		// Set a short-lived lock (5 seconds should be more than enough).
		set_transient( $lock_key, true, self::SYNC_LOCK_TIMEOUT );

		try {
			// Get the CCT item handler.
			$handler = \WP_MCP_AI_JetEngine_AI_Peers_CCT::get_item_handler();

			if ( ! $handler ) {
				return;
			}

			// Validate handler has required methods.
			if ( ! method_exists( $handler, 'update_item' ) ) {
				return;
			}

			// Get peer metadata.
			$site_name     = get_post_meta( $post_id, self::META_SITE_NAME, true );
			$site_url      = get_post_meta( $post_id, self::META_SITE_URL, true );
			$mcp_url       = get_post_meta( $post_id, self::META_MCP_URL, true );
			$jwks_uri      = get_post_meta( $post_id, self::META_JWKS_URI, true );
			$capabilities  = get_post_meta( $post_id, self::META_CAPABILITIES, true );
			$regions       = get_post_meta( $post_id, self::META_REGIONS, true );
			$data_tags     = get_post_meta( $post_id, self::META_DATA_TAGS, true );
			$health_status = get_post_meta( $post_id, self::META_HEALTH_STATUS, true );
			$latency_p50   = get_post_meta( $post_id, self::META_LATENCY_P50, true );
			$last_verified = get_post_meta( $post_id, self::META_LAST_VERIFIED, true );

			// Map CPT data to CCT fields.
			$cct_data = array(
				'site_name'     => $site_name ? $site_name : $post->post_title,
				'site_url'      => $site_url ? $site_url : '',
				'mcp_url'       => $mcp_url ? $mcp_url : '',
				'jwks_uri'      => $jwks_uri ? $jwks_uri : '',
				'capabilities'  => $capabilities ? $capabilities : '[]',
				'regions'       => $regions ? $regions : '[]',
				'data_tags'     => $data_tags ? $data_tags : '[]',
				'health_status' => $health_status ? $health_status : '',
				'latency_p50'   => $latency_p50 ? absint( $latency_p50 ) : 0,
				'last_verified' => $last_verified ? $last_verified : '',
			);

			// Check if a CCT item already exists for this CPT post ID.
			// We use a meta field to link CPT ID to CCT item ID.
			$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_peer_cct_item_id', true );

			if ( $cct_item_id ) {
				// Update existing CCT item.
				$cct_data['_ID'] = absint( $cct_item_id );
				$result          = $handler->update_item( $cct_data );

				if ( ! $result ) {
					// If update failed, the item might have been deleted. Clear the link and create new.
					delete_post_meta( $post_id, '_wp_mcp_ai_peer_cct_item_id' );
					$cct_item_id = 0;
				}
			}

			if ( ! $cct_item_id ) {
				// Create new CCT item.
				$new_item_id = $handler->update_item( $cct_data );

				if ( $new_item_id ) {
					// Store the link between CPT post ID and CCT item ID.
					update_post_meta( $post_id, '_wp_mcp_ai_peer_cct_item_id', $new_item_id );
				}
			}
		} catch ( \Throwable $e ) {
			// Log error but don't block the save process.
			self::log_sync_error(
				'ai_peer_cct_sync_failed',
				sprintf( 'Failed to sync AI Peer %d to CCT: %s', $post_id, $e->getMessage() ),
				$post_id,
				$e
			);
		} finally {
			// Always release the lock.
			delete_transient( $lock_key );
		}
	}

	/**
	 * Hook callback to cleanup CCT item on peer deletion.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function cleanup_cct_on_delete( $post_id ) {
		$this->delete_cct_item( $post_id );
	}

	/**
	 * Delete the linked JetEngine CCT item for this peer.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function delete_cct_item( $post_id ) {
		// Only attempt deletion in Full Version when JetEngine is available.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT' ) ) {
			return;
		}

		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_peer_cct_item_id', true );

		if ( ! $cct_item_id ) {
			return;
		}

		$handler = \WP_MCP_AI_JetEngine_AI_Peers_CCT::get_item_handler();

		if ( ! $handler ) {
			return;
		}

		try {
			// Delete the CCT item.
			$handler->delete_item( absint( $cct_item_id ) );

			// Remove the meta link.
			delete_post_meta( $post_id, '_wp_mcp_ai_peer_cct_item_id' );
		} catch ( \Throwable $e ) {
			// Log error but don't block the delete process.
			self::log_sync_error(
				'ai_peer_cct_delete_failed',
				sprintf( 'Failed to delete AI Peer %d CCT item: %s', $post_id, $e->getMessage() ),
				$post_id,
				$e
			);
		}
	}

	/**
	 * Log a CCT sync error through the base plugin's error handler when
	 * present (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string    $code     Error code.
	 * @param string    $message  Error message.
	 * @param int       $peer_id  Peer post ID.
	 * @param \Throwable $throwable Exception.
	 */
	private static function log_sync_error( $code, $message, $peer_id, $throwable ) {
		$context = array(
			'peer_id'        => $peer_id,
			'exception_type' => get_class( $throwable ),
			'file'           => $throwable->getFile(),
			'line'           => $throwable->getLine(),
		);

		if ( class_exists( 'WP_MCP_AI_Error_Handler' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Error_Handler::create_error( $code, $message, $context, \WP_MCP_AI_Logger::LEVEL_ERROR );
			return;
		}

		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $code, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}
}
