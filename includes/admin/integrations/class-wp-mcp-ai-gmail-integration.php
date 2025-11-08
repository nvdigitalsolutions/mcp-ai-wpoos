<?php
/**
 * Gmail Integration Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Gmail_Integration' ) ) {
	/**
	 * Gmail integration configuration page.
	 */
	class WP_MCP_AI_Gmail_Integration extends WP_MCP_AI_Integration_Page {
		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		public function get_page_slug() {
			return 'wp-mcp-ai-gmail';
		}

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		public function get_page_title() {
			return __( 'Gmail Integration', 'wp-mcp-ai' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		public function get_menu_title() {
			return __( 'Gmail', 'wp-mcp-ai' );
		}

		/**
		 * Get the integration name.
		 *
		 * @return string
		 */
		public function get_integration_name() {
			return __( 'Gmail', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'gmail_client_id'     => array(
					'type'        => 'text',
					'label'       => __( 'OAuth Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ),
					'placeholder' => '',
					'default'     => '',
				),
				'gmail_client_secret' => array(
					'type'        => 'password',
					'label'       => __( 'OAuth Client Secret', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder' => '',
					'default'     => '',
				),
				'gmail_refresh_token' => array(
					'type'        => 'password',
					'label'       => __( 'Refresh Token', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Refresh Token (generated after OAuth flow).', 'wp-mcp-ai' ),
					'placeholder' => '',
					'default'     => '',
				),
				'gmail_user_email'    => array(
					'type'        => 'email',
					'label'       => __( 'Authorized Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with the Gmail account.', 'wp-mcp-ai' ),
					'placeholder' => 'user@example.com',
					'default'     => '',
				),
			);
		}

		/**
		 * Render the page content.
		 */
		public function render_page() {
			$this->render_header();
			?>
			<div class="card">
				<h2><?php esc_html_e( 'Gmail API Configuration', 'wp-mcp-ai' ); ?></h2>
				<p>
					<?php esc_html_e( 'Configure Gmail integration to enable AI assistants to read and manage emails.', 'wp-mcp-ai' ); ?>
				</p>
				<h3><?php esc_html_e( 'Setup Instructions', 'wp-mcp-ai' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Go to Google Cloud Console and create a new project or select an existing one.', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Enable the Gmail API for your project.', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application type).', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Add authorized redirect URI:', 'wp-mcp-ai' ); ?> <code><?php echo esc_url( admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_callback' ) ); ?></code></li>
					<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields below.', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Complete the OAuth flow to generate a refresh token.', 'wp-mcp-ai' ); ?></li>
				</ol>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Settings', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_form(); ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Available Tools', 'wp-mcp-ai' ); ?></h2>
				<p><?php esc_html_e( 'Once configured, the following Gmail tools will be available:', 'wp-mcp-ai' ); ?></p>
				<ul>
					<li><strong>read_emails</strong> - <?php esc_html_e( 'Read emails from Gmail inbox', 'wp-mcp-ai' ); ?></li>
					<li><strong>send_email</strong> - <?php esc_html_e( 'Send emails through Gmail', 'wp-mcp-ai' ); ?></li>
					<li><strong>search_emails</strong> - <?php esc_html_e( 'Search emails by query', 'wp-mcp-ai' ); ?></li>
				</ul>
			</div>
			<?php
			$this->render_footer();
		}
	}
}
