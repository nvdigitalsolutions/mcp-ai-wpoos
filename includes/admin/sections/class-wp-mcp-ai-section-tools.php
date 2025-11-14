<?php
/**
 * Tools & Features Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Tools' ) ) {
	/**
	 * Tools & Features settings section.
	 */
	class WP_MCP_AI_Section_Tools extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'tools';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Tools & Features Configuration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure AI-powered tools and features for your WordPress site.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Features fields.
				'enable_mesh_computing'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Mesh Computing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable distributed computing features', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to participate in mesh computing networks.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_federation'                       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable federated discovery', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to be discovered by and connect to other WP oOS instances.', 'wp-mcp-ai' ),
					'default'        => false,
				),

				// Media fields.
				'enable_ai_media_library'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Media Library', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze images on upload', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, newly uploaded images will be automatically analyzed by AI to generate alt text and captions. This feature uses vision-capable AI models (requires OpenAI or Gemini API key).', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_media_generate_alt_text'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Alt Text', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate alt text for accessibility', 'wp-mcp-ai' ),
					'description'    => __( 'Generate descriptive alt text for images to improve accessibility for screen readers and SEO.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_generate_caption'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Captions', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate image captions', 'wp-mcp-ai' ),
					'description'    => __( 'Generate detailed captions for images to provide context and enhance content.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_overwrite_existing'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Overwrite Existing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Replace existing alt text and captions', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI will overwrite any existing alt text or captions. When disabled, AI will only fill in missing metadata.', 'wp-mcp-ai' ),
					'default'        => false,
				),

				// Comments fields.
				'enable_ai_comments_moderation'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Comments Moderation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze comments for spam and toxicity', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, incoming comments will be automatically analyzed by AI to detect spam, toxic content, and other moderation concerns before they are published.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_comments_sensitivity'                 => array(
					'type'        => 'select',
					'label'       => __( 'Moderation Sensitivity', 'wp-mcp-ai' ),
					'description' => __( 'Controls how strict the AI moderation should be. Low = permissive (only flag obvious violations), Medium = balanced (flag clear issues), High = strict (flag anything questionable).', 'wp-mcp-ai' ),
					'options'     => array(
						'low'    => __( 'Low (Permissive)', 'wp-mcp-ai' ),
						'medium' => __( 'Medium (Balanced)', 'wp-mcp-ai' ),
						'high'   => __( 'High (Strict)', 'wp-mcp-ai' ),
					),
					'default'     => 'medium',
				),
				'ai_comments_min_confidence'              => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Confidence Level', 'wp-mcp-ai' ),
					'description' => __( 'Only apply AI recommendations when confidence is at or above this threshold. Lower values trust AI more, higher values require more certainty.', 'wp-mcp-ai' ),
					'options'     => array(
						'0.5' => __( '50% (Trust AI more)', 'wp-mcp-ai' ),
						'0.6' => __( '60%', 'wp-mcp-ai' ),
						'0.7' => __( '70% (Balanced - Recommended)', 'wp-mcp-ai' ),
						'0.8' => __( '80%', 'wp-mcp-ai' ),
						'0.9' => __( '90% (Very conservative)', 'wp-mcp-ai' ),
					),
					'default'     => '0.7',
				),
				'ai_comments_auto_hold_low_confidence'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Hold Low Confidence Comments', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Hold comments for manual review when AI confidence is below threshold', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, comments that AI analyzes with low confidence will be held for moderation instead of being published or marked as spam.', 'wp-mcp-ai' ),
					'default'        => true,
				),

				// Site Creator fields.
				'enable_site_creator'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Site Creator', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow AI to create and configure sites', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content. This feature requires manage_options capability.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_plugin_install'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Plugin Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic plugin installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_theme_install'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Theme Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic theme installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_option_updates'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Option Updates', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic WordPress option updates', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		private function get_subtab_groups() {
			return array(
				'features'     => array(
					'id'     => 'features',
					'label'  => __( 'Features', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array( 'enable_mesh_computing', 'enable_federation' ),
				),
				'media'        => array(
					'id'     => 'media',
					'label'  => __( 'AI Media Library', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-format-image',
					'fields' => array( 'enable_ai_media_library', 'ai_media_generate_alt_text', 'ai_media_generate_caption', 'ai_media_overwrite_existing' ),
				),
				'comments'     => array(
					'id'     => 'comments',
					'label'  => __( 'AI Comments', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-comments',
					'fields' => array( 'enable_ai_comments_moderation', 'ai_comments_sensitivity', 'ai_comments_min_confidence', 'ai_comments_auto_hold_low_confidence' ),
				),
				'site_creator' => array(
					'id'     => 'site_creator',
					'label'  => __( 'Site Creator', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-site',
					'fields' => array( 'enable_site_creator', 'site_creator_allow_plugin_install', 'site_creator_allow_theme_install', 'site_creator_allow_option_updates' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		private function get_active_subtab() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$subtab        = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : 'features';
			$subtab_groups = $this->get_subtab_groups();

			if ( ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'features';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}

			// Render additional content based on active sub-tab.
			$this->render_subtab_footer( $active_subtab );
		}

		/**
		 * Render footer content for specific sub-tabs.
		 *
		 * @param string $subtab Active sub-tab ID.
		 */
		private function render_subtab_footer( $subtab ) {
			switch ( $subtab ) {
				case 'media':
					$this->render_media_footer();
					break;
				case 'comments':
					$this->render_comments_footer();
					break;
				case 'site_creator':
					$this->render_site_creator_footer();
					break;
			}
		}

		/**
		 * Render AI Media Library footer content.
		 */
		private function render_media_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires a vision-capable AI provider (OpenAI GPT-4o or Gemini) to be configured in the Providers tab. Image analysis will use the default provider specified in General Settings.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Each image upload will consume AI tokens. Consider the API costs when enabling this feature for high-volume sites.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render AI Comments Moderation footer content.
		 */
		private function render_comments_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How it works:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'AI analyzes comment text, author information, and context', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects spam indicators: promotional content, suspicious links, generic comments', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects toxic content: hate speech, harassment, threats, offensive language', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Provides a recommendation: approve, hold for moderation, or mark as spam', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Comments from logged-in moderators are never automatically flagged', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'AI analysis is stored as comment metadata for review by moderators', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires an AI provider (OpenAI or Gemini) to be configured. Each comment will consume a small amount of AI tokens.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Site Creator footer content.
		 */
		private function render_site_creator_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creator tools require administrative capabilities (manage_options, install_plugins, install_themes). Only users with these capabilities can execute site creator operations. All plugins and themes are installed exclusively from the official WordPress.org repository.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Performance Consideration:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creation operations (especially plugin/theme installation) can take several minutes to complete and may temporarily impact site performance. These operations are marked as long-running and should be executed with appropriate timeouts.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Tools settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'tools',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>" 
							   class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
							   data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
