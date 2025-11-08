<?php
/**
 * Crawl4AI Integration Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Crawl4AI_Integration' ) ) {
	/**
	 * Crawl4AI integration configuration page.
	 */
	class WP_MCP_AI_Crawl4AI_Integration extends WP_MCP_AI_Integration_Page {
		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		public function get_page_slug() {
			return 'wp-mcp-ai-crawl4ai';
		}

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		public function get_page_title() {
			return __( 'Crawl4AI Integration', 'wp-mcp-ai' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		public function get_menu_title() {
			return __( 'Crawl4AI', 'wp-mcp-ai' );
		}

		/**
		 * Get the integration name.
		 *
		 * @return string
		 */
		public function get_integration_name() {
			return __( 'Crawl4AI', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'crawl4ai_base_url' => array(
					'type'        => 'url',
					'label'       => __( 'Base URL', 'wp-mcp-ai' ),
					'description' => __( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ),
					'placeholder' => 'http://localhost:8000',
					'default'     => '',
				),
				'crawl4ai_api_key'  => array(
					'type'        => 'password',
					'label'       => __( 'API Key', 'wp-mcp-ai' ),
					'description' => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder' => '',
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
				<h2><?php esc_html_e( 'Crawl4AI Service Configuration', 'wp-mcp-ai' ); ?></h2>
				<p>
					<?php esc_html_e( 'Configure Crawl4AI integration to enable AI assistants to crawl and extract content from websites.', 'wp-mcp-ai' ); ?>
				</p>
				<h3><?php esc_html_e( 'Setup Instructions', 'wp-mcp-ai' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Install and run Crawl4AI service (Docker recommended).', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Ensure the service is accessible from this WordPress installation.', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Enter the Base URL where Crawl4AI is running.', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'If your Crawl4AI instance requires authentication, enter the API key.', 'wp-mcp-ai' ); ?></li>
				</ol>
				<p>
					<strong><?php esc_html_e( 'Docker Quick Start:', 'wp-mcp-ai' ); ?></strong><br>
					<code>docker run -p 8000:8000 crawl4ai/crawl4ai:latest</code>
				</p>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Settings', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_form(); ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Available Tools', 'wp-mcp-ai' ); ?></h2>
				<p><?php esc_html_e( 'Once configured, the following Crawl4AI tools will be available:', 'wp-mcp-ai' ); ?></p>
				<ul>
					<li><strong>crawl_url</strong> - <?php esc_html_e( 'Crawl a URL and extract content', 'wp-mcp-ai' ); ?></li>
					<li><strong>extract_structured_data</strong> - <?php esc_html_e( 'Extract structured data from web pages', 'wp-mcp-ai' ); ?></li>
					<li><strong>screenshot_url</strong> - <?php esc_html_e( 'Capture screenshots of web pages', 'wp-mcp-ai' ); ?></li>
				</ul>
			</div>
			<?php
			$this->render_footer();
		}
	}
}
