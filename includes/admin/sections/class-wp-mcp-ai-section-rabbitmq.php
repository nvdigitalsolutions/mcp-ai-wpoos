<?php
/**
 * RabbitMQ Settings Section for NV oOS.
 *
 * Provides admin UI for configuring RabbitMQ integration settings
 * when deployed on Cloudways with RabbitMQ enabled.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load abstract section class.
require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';

/**
 * RabbitMQ Settings Section class.
 */
class WP_MCP_AI_Section_RabbitMQ extends WP_MCP_AI_Settings_Section {

	/**
	 * Get section ID.
	 *
	 * @return string Section ID.
	 */
	public function get_id() {
		return 'rabbitmq';
	}

	/**
	 * Get section title.
	 *
	 * @return string Section title.
	 */
	public function get_title() {
		return __( 'RabbitMQ', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tab ID.
	 *
	 * @return string Tab ID.
	 */
	public function get_tab() {
		return 'orchestration';
	}

	/**
	 * Get section description.
	 *
	 * @return string Section description.
	 */
	public function get_description() {
		return __( 'Configure RabbitMQ message broker integration for distributed queue-based tool execution and job orchestration.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get documentation URL for this section.
	 *
	 * @return string Documentation URL.
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/federation/RABBITMQ-CLOUDWAYS-INTEGRATION-PLAN.md';
	}

	/**
	 * Get section icon.
	 *
	 * @return string Section icon (dashicon class).
	 */
	public function get_icon() {
		return 'dashicons-networking';
	}

	/**
	 * Get section priority.
	 *
	 * @return int Section priority (lower = earlier).
	 */
	public function get_priority() {
		return 85;
	}

	/**
	 * Check if section should be visible.
	 *
	 * @return bool Whether section is visible.
	 */
	public function is_visible() {
		// Only show if AMQP extension is available or RabbitMQ is configured.
		return extension_loaded( 'amqp' ) ||
			defined( 'WP_MCP_AI_RABBITMQ_ENABLED' ) ||
			! empty( $this->settings['rabbitmq_enabled'] );
	}

	/**
	 * Get section fields.
	 *
	 * @return array Section fields configuration.
	 */
	public function get_fields() {
		$fields = array(
			// Connection Settings.
			'rabbitmq_enabled'             => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable RabbitMQ Integration', 'mcp-ai-wpoos' ),
				'description' => __( 'Enable message queue integration for improved agentic workflow processing.', 'mcp-ai-wpoos' ),
				'default'     => false,
			),
			'rabbitmq_host'                => array(
				'type'        => 'text',
				'label'       => __( 'RabbitMQ Host', 'mcp-ai-wpoos' ),
				'description' => __( 'Hostname of the RabbitMQ server. Usually "localhost" on Cloudways.', 'mcp-ai-wpoos' ),
				'default'     => 'localhost',
				'placeholder' => 'localhost',
			),
			'rabbitmq_port'                => array(
				'type'        => 'number',
				'label'       => __( 'RabbitMQ Port', 'mcp-ai-wpoos' ),
				'description' => __( 'AMQP port (default: 5672).', 'mcp-ai-wpoos' ),
				'default'     => 5672,
				'min'         => 1,
				'max'         => 65535,
			),
			'rabbitmq_username'            => array(
				'type'         => 'text',
				'label'        => __( 'RabbitMQ Username', 'mcp-ai-wpoos' ),
				'description'  => __( 'Username for RabbitMQ authentication.', 'mcp-ai-wpoos' ),
				'default'      => 'guest',
				'autocomplete' => 'new-password',
			),
			'rabbitmq_password'            => array(
				'type'         => 'password',
				'label'        => __( 'RabbitMQ Password', 'mcp-ai-wpoos' ),
				'description'  => __( 'Password for RabbitMQ authentication. Check Cloudways for your credentials.', 'mcp-ai-wpoos' ),
				'default'      => '',
				'autocomplete' => 'new-password',
			),
			'rabbitmq_vhost'               => array(
				'type'        => 'text',
				'label'       => __( 'Virtual Host', 'mcp-ai-wpoos' ),
				'description' => __( 'RabbitMQ virtual host (default: /).', 'mcp-ai-wpoos' ),
				'default'     => '/',
			),

			// Queue Settings.
			'rabbitmq_queue_prefix'        => array(
				'type'        => 'text',
				'label'       => __( 'Queue Prefix', 'mcp-ai-wpoos' ),
				'description' => __( 'Prefix for queue names (useful for multisite).', 'mcp-ai-wpoos' ),
				'default'     => 'wp_mcp_ai',
			),
			'rabbitmq_priority_queues'     => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Priority Queues', 'mcp-ai-wpoos' ),
				'description' => __( 'Use separate queues for high, normal, and async priority tools.', 'mcp-ai-wpoos' ),
				'default'     => true,
			),

			// Worker Settings.
			'rabbitmq_parallel_execution'  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Parallel Tool Execution', 'mcp-ai-wpoos' ),
				'description' => __( 'Allow independent tools to execute in parallel during agentic workflows.', 'mcp-ai-wpoos' ),
				'default'     => false,
			),
			'rabbitmq_worker_timeout'      => array(
				'type'        => 'number',
				'label'       => __( 'Worker Timeout (seconds)', 'mcp-ai-wpoos' ),
				'description' => __( 'Maximum time for a worker to process a single tool.', 'mcp-ai-wpoos' ),
				'default'     => 300,
				'min'         => 30,
				'max'         => 3600,
			),

			// Retry Settings.
			'rabbitmq_max_retries'         => array(
				'type'        => 'number',
				'label'       => __( 'Max Retry Attempts', 'mcp-ai-wpoos' ),
				'description' => __( 'Number of times to retry a failed tool execution.', 'mcp-ai-wpoos' ),
				'default'     => 3,
				'min'         => 0,
				'max'         => 10,
			),
			'rabbitmq_retry_delay'         => array(
				'type'        => 'number',
				'label'       => __( 'Retry Delay (ms)', 'mcp-ai-wpoos' ),
				'description' => __( 'Initial delay between retries (uses exponential backoff).', 'mcp-ai-wpoos' ),
				'default'     => 1000,
				'min'         => 100,
				'max'         => 60000,
			),

			// Dead Letter Settings.
			'rabbitmq_dead_letter_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Dead Letter Queue', 'mcp-ai-wpoos' ),
				'description' => __( 'Store failed messages for debugging and manual processing.', 'mcp-ai-wpoos' ),
				'default'     => true,
			),
			'rabbitmq_dead_letter_ttl'     => array(
				'type'        => 'number',
				'label'       => __( 'Dead Letter TTL (seconds)', 'mcp-ai-wpoos' ),
				'description' => __( 'How long to keep failed messages (default: 24 hours).', 'mcp-ai-wpoos' ),
				'default'     => 86400,
				'min'         => 3600,
				'max'         => 604800, // 7 days.
			),
		);

		return $fields;
	}

	/**
	 * Get field groups configuration.
	 *
	 * @return array Field groups.
	 */
	public function get_field_groups() {
		return array(
			array(
				'id'          => 'connection',
				'title'       => __( 'Connection Settings', 'mcp-ai-wpoos' ),
				'description' => __( 'Configure connection to your RabbitMQ server.', 'mcp-ai-wpoos' ),
				'fields'      => array(
					'rabbitmq_enabled',
					'rabbitmq_host',
					'rabbitmq_port',
					'rabbitmq_username',
					'rabbitmq_password',
					'rabbitmq_vhost',
				),
			),
			array(
				'id'          => 'queues',
				'title'       => __( 'Queue Settings', 'mcp-ai-wpoos' ),
				'description' => __( 'Configure queue behavior and naming.', 'mcp-ai-wpoos' ),
				'fields'      => array(
					'rabbitmq_queue_prefix',
					'rabbitmq_priority_queues',
				),
			),
			array(
				'id'          => 'execution',
				'title'       => __( 'Execution Settings', 'mcp-ai-wpoos' ),
				'description' => __( 'Configure how tools are executed via queues.', 'mcp-ai-wpoos' ),
				'fields'      => array(
					'rabbitmq_parallel_execution',
					'rabbitmq_worker_timeout',
				),
			),
			array(
				'id'          => 'reliability',
				'title'       => __( 'Reliability Settings', 'mcp-ai-wpoos' ),
				'description' => __( 'Configure retry and dead letter handling.', 'mcp-ai-wpoos' ),
				'fields'      => array(
					'rabbitmq_max_retries',
					'rabbitmq_retry_delay',
					'rabbitmq_dead_letter_enabled',
					'rabbitmq_dead_letter_ttl',
				),
			),
		);
	}

	/**
	 * Render section content.
	 */
	public function render() {
		$this->render_status_widget();
		parent::render();
	}

	/**
	 * Render connection status widget.
	 */
	private function render_status_widget() {
		$rabbitmq_available = class_exists( 'WP_MCP_AI_RabbitMQ_Client' );
		$extension_loaded   = extension_loaded( 'amqp' );
		$enabled            = ! empty( $this->settings['rabbitmq_enabled'] );

		?>
		<div class="wp-mcp-ai-status-widget" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'RabbitMQ Status', 'mcp-ai-wpoos' ); ?></h3>

			<table class="widefat" style="margin-bottom: 15px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PHP AMQP Extension', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<?php if ( $extension_loaded ) : ?>
								<span style="color: green;">✓ <?php esc_html_e( 'Loaded', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: red;">✗ <?php esc_html_e( 'Not Loaded', 'mcp-ai-wpoos' ); ?></span>
								<br><small><?php esc_html_e( 'Enable RabbitMQ on your Cloudways server to install this extension.', 'mcp-ai-wpoos' ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Integration Enabled', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<?php if ( $enabled ) : ?>
								<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: gray;">○ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $enabled && $rabbitmq_available && $extension_loaded ) : ?>
						<tr>
							<td><strong><?php esc_html_e( 'Connection Status', 'mcp-ai-wpoos' ); ?></strong></td>
							<td id="rabbitmq-connection-status">
								<span style="color: gray;">○ <?php esc_html_e( 'Checking...', 'mcp-ai-wpoos' ); ?></span>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $enabled && $extension_loaded ) : ?>
				<button type="button" class="button" id="test-rabbitmq-connection">
					<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button" id="setup-rabbitmq-infrastructure">
					<?php esc_html_e( 'Setup Queues', 'mcp-ai-wpoos' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( ! $extension_loaded ) : ?>
				<div class="notice notice-warning inline" style="margin: 10px 0 0 0;">
					<p>
						<strong><?php esc_html_e( 'How to Enable RabbitMQ on Cloudways:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li><?php esc_html_e( 'Go to your Cloudways Platform', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Navigate to Server Management → Settings & Packages', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Enable RabbitMQ under Advanced Settings', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Note the credentials provided', 'mcp-ai-wpoos' ); ?></li>
					</ol>
					<p>
						<a href="https://support.cloudways.com/en/articles/8680154-how-to-enable-rabbitmq-on-cloudways" target="_blank" rel="noopener">
							<?php esc_html_e( 'View Cloudways Documentation →', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $enabled && $extension_loaded ) :
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script>
			jQuery(document).ready(function($) {
				// Test connection on page load.
				checkRabbitMQConnection();

				$('#test-rabbitmq-connection').on('click', function() {
					checkRabbitMQConnection();
				});

				$('#setup-rabbitmq-infrastructure').on('click', function() {
					var $button = $(this);
					$button.prop('disabled', true).text('<?php echo esc_js( __( 'Setting up...', 'mcp-ai-wpoos' ) ); ?>');

					$.ajax({
						url: ajaxurl,
						method: 'POST',
						data: {
							action: 'wp_mcp_ai_rabbitmq_setup',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_admin' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								alert('<?php echo esc_js( __( 'RabbitMQ infrastructure setup complete!', 'mcp-ai-wpoos' ) ); ?>');
							} else {
								alert('<?php echo esc_js( __( 'Setup failed: ', 'mcp-ai-wpoos' ) ); ?>' + (response.data.message || 'Unknown error'));
							}
						},
						error: function() {
							alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos' ) ); ?>');
						},
						complete: function() {
							$button.prop('disabled', false).text('<?php echo esc_js( __( 'Setup Queues', 'mcp-ai-wpoos' ) ); ?>');
						}
					});
				});

				function checkRabbitMQConnection() {
					var $status = $('#rabbitmq-connection-status');
					$status.html('<span style="color: gray;">○ <?php echo esc_js( __( 'Checking...', 'mcp-ai-wpoos' ) ); ?></span>');

					$.ajax({
						url: ajaxurl,
						method: 'POST',
						data: {
							action: 'wp_mcp_ai_rabbitmq_health',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_admin' ) ); ?>'
						},
						success: function(response) {
							if (response.success && response.data.status === 'healthy') {
								$status.html('<span style="color: green;">✓ <?php echo esc_js( __( 'Connected', 'mcp-ai-wpoos' ) ); ?></span>');
							} else {
								var error = response.data.error || response.data.message || '<?php echo esc_js( __( 'Connection failed', 'mcp-ai-wpoos' ) ); ?>';
								$status.html('<span style="color: red;">✗ ' + error + '</span>');
							}
						},
						error: function() {
							$status.html('<span style="color: red;">✗ <?php echo esc_js( __( 'Request failed', 'mcp-ai-wpoos' ) ); ?></span>');
						}
					});
				}
			});
			</script>
			<?php
		endif;
	}

	/**
	 * Register AJAX handlers.
	 */
	protected function register_ajax_handlers() {
		add_action( 'wp_ajax_wp_mcp_ai_rabbitmq_health', array( $this, 'ajax_health_check' ) );
		add_action( 'wp_ajax_wp_mcp_ai_rabbitmq_setup', array( $this, 'ajax_setup_infrastructure' ) );
	}

	/**
	 * AJAX handler for health check.
	 */
	public function ajax_health_check() {
		check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			wp_send_json_error( array( 'message' => __( 'RabbitMQ client not loaded.', 'mcp-ai-wpoos' ) ) );
		}

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$status = $client->health_check();

		wp_send_json_success( $status );
	}

	/**
	 * AJAX handler for infrastructure setup.
	 */
	public function ajax_setup_infrastructure() {
		check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			wp_send_json_error( array( 'message' => __( 'RabbitMQ client not loaded.', 'mcp-ai-wpoos' ) ) );
		}

		try {
			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$client->setup_infrastructure();
			wp_send_json_success( array( 'message' => __( 'Infrastructure setup complete.', 'mcp-ai-wpoos' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
