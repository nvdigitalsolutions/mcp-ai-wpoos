<?php
/**
 * Mesh Routing Metabox for Assistants.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Mesh Routing metabox for assistant posts.
 *
 * Manages intelligent routing configuration for distributed compute.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Mesh_Routing extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class for constants.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_mesh_routing';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Mesh Routing', 'wp-mcp-ai' );
	}

	/**
	 * Get the metabox context.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_context() {
		return 'side';
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied( __( 'You do not have permission to configure mesh routing.', 'wp-mcp-ai' ) );
			return;
		}

		$hub_config = WP_MCP_AI_Mesh_Router::get_hub_config( $post->ID );
		$settings   = WP_MCP_AI_Admin_Settings::get_settings();
		$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		wp_nonce_field( 'wp_mcp_ai_save_mesh_routing', 'wp_mcp_ai_save_mesh_routing_nonce' );

		?>
		<div class="wp-mcp-ai-mesh-routing-config">
			<p class="description">
				<?php esc_html_e( 'Configure intelligent routing for this assistant. The system can use AI to automatically select optimal compute resources - either mesh peer sites, different AI providers (OpenAI, Gemini, Ollama), or both.', 'wp-mcp-ai' ); ?>
			</p>

			<h3><?php esc_html_e( 'Routing Strategy', 'wp-mcp-ai' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp-mcp-ai-routing-strategy"><?php esc_html_e( 'Strategy', 'wp-mcp-ai' ); ?></label>
					</th>
					<td>
						<select name="wp_mcp_ai_mesh_routing[routing_strategy]" id="wp-mcp-ai-routing-strategy" class="regular-text">
							<option value="ai_optimized" <?php selected( $hub_config['routing_strategy'], 'ai_optimized' ); ?>>
								<?php esc_html_e( 'AI Optimized (Recommended) - Intelligently route based on load, complexity, and response times', 'wp-mcp-ai' ); ?>
							</option>
							<option value="round_robin" <?php selected( $hub_config['routing_strategy'], 'round_robin' ); ?>>
								<?php esc_html_e( 'Round Robin - Distribute requests evenly across peers', 'wp-mcp-ai' ); ?>
							</option>
							<option value="least_loaded" <?php selected( $hub_config['routing_strategy'], 'least_loaded' ); ?>>
								<?php esc_html_e( 'Least Loaded - Route to peer with lowest current load', 'wp-mcp-ai' ); ?>
							</option>
							<option value="preferred_with_fallback" <?php selected( $hub_config['routing_strategy'], 'preferred_with_fallback' ); ?>>
								<?php esc_html_e( 'Preferred with Fallback - Try preferred peers first, then fallback', 'wp-mcp-ai' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'AI Optimized works even with a single site by routing between multiple providers (OpenAI, Gemini, Ollama) based on task complexity, rate limits, and cost.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $peer_sites ) ) : ?>
			<h3><?php esc_html_e( 'Mesh Peer Configuration', 'wp-mcp-ai' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Compute Hubs', 'wp-mcp-ai' ); ?>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Designate which peer sites are "compute hubs" with larger models or more capacity. The AI router will prefer these for complex tasks.', 'wp-mcp-ai' ); ?>
						</p>
						<?php
						$compute_hubs = isset( $hub_config['compute_hubs'] ) ? $hub_config['compute_hubs'] : array();
						foreach ( $peer_sites as $peer ) {
							$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
							if ( empty( $peer_name ) ) {
								continue;
							}
							$checked = in_array( $peer_name, $compute_hubs, true );
							?>
							<label style="display: block; margin: 5px 0;">
								<input type="checkbox" name="wp_mcp_ai_mesh_routing[compute_hubs][]" value="<?php echo esc_attr( $peer_name ); ?>" <?php checked( $checked ); ?> />
								<?php echo esc_html( $peer_name ); ?>
							</label>
							<?php
						}
						?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Preferred Peers', 'wp-mcp-ai' ); ?>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Select preferred peers in order of priority. Used when routing strategy is "Preferred with Fallback".', 'wp-mcp-ai' ); ?>
						</p>
						<div id="wp-mcp-ai-preferred-peers-list">
							<?php
							$preferred_peers = isset( $hub_config['preferred_peers'] ) ? $hub_config['preferred_peers'] : array();
							$peer_index      = 0;
							foreach ( $preferred_peers as $preferred ) {
								?>
								<div class="wp-mcp-ai-preferred-peer-row" style="margin-bottom: 10px;">
									<select name="wp_mcp_ai_mesh_routing[preferred_peers][]" class="regular-text">
										<option value=""><?php esc_html_e( '-- Select Peer --', 'wp-mcp-ai' ); ?></option>
										<?php
										foreach ( $peer_sites as $peer ) {
											$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
											if ( empty( $peer_name ) ) {
												continue;
											}
											?>
											<option value="<?php echo esc_attr( $peer_name ); ?>" <?php selected( $preferred, $peer_name ); ?>>
												<?php echo esc_html( $peer_name ); ?>
											</option>
											<?php
										}
										?>
									</select>
									<button type="button" class="button wp-mcp-ai-remove-preferred-peer"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
								</div>
								<?php
								++$peer_index;
							}
							?>
						</div>
						<button type="button" class="button" id="wp-mcp-ai-add-preferred-peer"><?php esc_html_e( 'Add Preferred Peer', 'wp-mcp-ai' ); ?></button>
					</td>
				</tr>
			</table>
			<?php else : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Settings URL */
							__( 'No mesh peer sites configured. <a href="%s">Configure mesh peers</a> to enable distributed compute routing, or use AI routing with multiple providers on this site.', 'wp-mcp-ai' ),
							admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' )
						)
					);
					?>
				</p>
				<p>
					<?php esc_html_e( 'Even without mesh peers, AI Optimized routing can intelligently balance load across OpenAI, Gemini, and Ollama based on task complexity and rate limits.', 'wp-mcp-ai' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Retry & Failover', 'wp-mcp-ai' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp-mcp-ai-enable-retry"><?php esc_html_e( 'Enable Retry', 'wp-mcp-ai' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="wp_mcp_ai_mesh_routing[enable_retry]" id="wp-mcp-ai-enable-retry" value="1" <?php checked( $hub_config['enable_retry'] ); ?> />
							<?php esc_html_e( 'Automatically retry failed requests with different peers or providers', 'wp-mcp-ai' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, failed requests will automatically be retried with alternative peers or AI providers for resilience.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp-mcp-ai-max-retries"><?php esc_html_e( 'Max Retries', 'wp-mcp-ai' ); ?></label>
					</th>
					<td>
						<input type="number" name="wp_mcp_ai_mesh_routing[max_retries]" id="wp-mcp-ai-max-retries" value="<?php echo esc_attr( $hub_config['max_retries'] ); ?>" min="1" max="10" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Maximum number of retry attempts (1-10).', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<script type="text/javascript">
			jQuery(document).ready(function($) {
				var peerOptions = <?php echo wp_json_encode( array_column( $peer_sites, 'name' ) ); ?>;

				$('#wp-mcp-ai-add-preferred-peer').on('click', function() {
					var optionsHtml = '<option value=""><?php echo esc_js( __( '-- Select Peer --', 'wp-mcp-ai' ) ); ?></option>';
					peerOptions.forEach(function(peerName) {
						optionsHtml += '<option value="' + peerName + '">' + peerName + '</option>';
					});

					var newRow = $('<div class="wp-mcp-ai-preferred-peer-row" style="margin-bottom: 10px;">' +
						'<select name="wp_mcp_ai_mesh_routing[preferred_peers][]" class="regular-text">' +
						optionsHtml +
						'</select> ' +
						'<button type="button" class="button wp-mcp-ai-remove-preferred-peer"><?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?></button>' +
						'</div>');
					$('#wp-mcp-ai-preferred-peers-list').append(newRow);
				});

				$(document).on('click', '.wp-mcp-ai-remove-preferred-peer', function() {
					$(this).closest('.wp-mcp-ai-preferred-peer-row').remove();
				});
			});
			</script>
		</div>
		<?php
	}
}
