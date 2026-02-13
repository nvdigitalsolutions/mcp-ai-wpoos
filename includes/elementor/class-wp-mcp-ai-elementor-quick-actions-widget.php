<?php
/**
 * Elementor widget for AI Quick Actions - single-click AI generation tools.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * AI Quick Actions widget - provides one-click access to AI tools.
 */
class WP_MCP_AI_Elementor_Quick_Actions_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_quick_actions';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'AI Quick Actions', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-flash';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'ai', 'quick', 'actions', 'tools', 'generation', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		return array( 'wp-mcp-ai-quick-actions-widget' );
	}

	/**
	 * Declare style dependencies for this widget.
	 *
	 * @return array List of style handles this widget depends on.
	 */
	public function get_style_depends() {
		return array( 'wp-mcp-ai-quick-actions-widget' );
	}

	/**
	 * Get tool categories with their tools.
	 *
	 * @return array Categorized tools.
	 */
	protected function get_categorized_tools() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_all_tools();
		
		$categories = array(
			'image' => array(
				'label' => __( 'Image Tools', 'mcp-ai-wpoos' ),
				'icon' => '🖼️',
				'tools' => array(),
			),
			'video' => array(
				'label' => __( 'Video Tools', 'mcp-ai-wpoos' ),
				'icon' => '🎬',
				'tools' => array(),
			),
			'audio' => array(
				'label' => __( 'Audio & Music', 'mcp-ai-wpoos' ),
				'icon' => '🎵',
				'tools' => array(),
			),
			'content' => array(
				'label' => __( 'Content Creation', 'mcp-ai-wpoos' ),
				'icon' => '📝',
				'tools' => array(),
			),
			'seo' => array(
				'label' => __( 'SEO Tools', 'mcp-ai-wpoos' ),
				'icon' => '🔍',
				'tools' => array(),
			),
			'woocommerce' => array(
				'label' => __( 'WooCommerce', 'mcp-ai-wpoos' ),
				'icon' => '🛍️',
				'tools' => array(),
			),
			'email' => array(
				'label' => __( 'Email & Newsletter', 'mcp-ai-wpoos' ),
				'icon' => '📧',
				'tools' => array(),
			),
			'security' => array(
				'label' => __( 'Security', 'mcp-ai-wpoos' ),
				'icon' => '🔐',
				'tools' => array(),
			),
			'analytics' => array(
				'label' => __( 'Analytics & Charts', 'mcp-ai-wpoos' ),
				'icon' => '📊',
				'tools' => array(),
			),
			'ai_models' => array(
				'label' => __( 'AI & Models', 'mcp-ai-wpoos' ),
				'icon' => '🤖',
				'tools' => array(),
			),
			'workflow' => array(
				'label' => __( 'Workflow & Automation', 'mcp-ai-wpoos' ),
				'icon' => '🔗',
				'tools' => array(),
			),
			'web_data' => array(
				'label' => __( 'Web & External Data', 'mcp-ai-wpoos' ),
				'icon' => '🌐',
				'tools' => array(),
			),
			'page_builders' => array(
				'label' => __( 'Page Builders', 'mcp-ai-wpoos' ),
				'icon' => '🗂️',
				'tools' => array(),
			),
			'utility' => array(
				'label' => __( 'Utilities', 'mcp-ai-wpoos' ),
				'icon' => '🔧',
				'tools' => array(),
			),
		);

		// Categorize tools based on their slug patterns.
		foreach ( $all_tools as $slug => $tool ) {
			$tool_data = array(
				'slug' => $slug,
				'name' => $this->get_tool_display_name( $slug ),
				'description' => $this->get_tool_description( $tool ),
			);

			// Categorize by slug patterns.
			if ( preg_match( '/image|crop|resize|rotate|vectorize/i', $slug ) ) {
				$categories['image']['tools'][] = $tool_data;
			} elseif ( preg_match( '/video|sora|veo/i', $slug ) ) {
				$categories['video']['tools'][] = $tool_data;
			} elseif ( preg_match( '/audio|music|speech|transcribe/i', $slug ) ) {
				$categories['audio']['tools'][] = $tool_data;
			} elseif ( preg_match( '/post|content|excerpt|categorize/i', $slug ) ) {
				$categories['content']['tools'][] = $tool_data;
			} elseif ( preg_match( '/seo|rankmath|meta|sitemap/i', $slug ) ) {
				$categories['seo']['tools'][] = $tool_data;
			} elseif ( preg_match( '/woo|product|order|flowhub/i', $slug ) ) {
				$categories['woocommerce']['tools'][] = $tool_data;
			} elseif ( preg_match( '/email|newsletter|subscriber/i', $slug ) ) {
				$categories['email']['tools'][] = $tool_data;
			} elseif ( preg_match( '/security|2fa|password|login/i', $slug ) ) {
				$categories['security']['tools'][] = $tool_data;
			} elseif ( preg_match( '/chart|analytics|usage|metric/i', $slug ) ) {
				$categories['analytics']['tools'][] = $tool_data;
			} elseif ( preg_match( '/model|embedding|vector|reasoning/i', $slug ) ) {
				$categories['ai_models']['tools'][] = $tool_data;
			} elseif ( preg_match( '/workflow|cron|batch|execute/i', $slug ) ) {
				$categories['workflow']['tools'][] = $tool_data;
			} elseif ( preg_match( '/search|crawl|web|geocode|places/i', $slug ) ) {
				$categories['web_data']['tools'][] = $tool_data;
			} elseif ( preg_match( '/elementor|gutenberg|jetengine|jetform/i', $slug ) ) {
				$categories['page_builders']['tools'][] = $tool_data;
			} else {
				$categories['utility']['tools'][] = $tool_data;
			}
		}

		// Remove empty categories.
		foreach ( $categories as $key => $category ) {
			if ( empty( $category['tools'] ) ) {
				unset( $categories[ $key ] );
			}
		}

		return $categories;
	}

	/**
	 * Get display name for a tool from its slug.
	 *
	 * @param string $slug Tool slug.
	 * @return string Display name.
	 */
	protected function get_tool_display_name( $slug ) {
		// Remove common prefixes and convert to title case.
		$name = str_replace( array( 'wp_mcp_ai_tool_', 'generate_', 'create_', 'get_' ), '', $slug );
		$name = str_replace( array( '_', '-' ), ' ', $name );
		return ucwords( $name );
	}

	/**
	 * Get tool description.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
	 * @return string Tool description.
	 */
	protected function get_tool_description( $tool ) {
		if ( method_exists( $tool, 'get_description' ) ) {
			return $tool->get_description();
		}
		if ( method_exists( $tool, 'get_definition' ) ) {
			$definition = $tool->get_definition();
			if ( isset( $definition['description'] ) ) {
				return $definition['description'];
			}
		}
		return '';
	}

	/**
	 * Get category options for the select control.
	 *
	 * @return array Category options.
	 */
	protected function get_category_options() {
		$categories = $this->get_categorized_tools();
		$options = array( '' => __( 'All Categories', 'mcp-ai-wpoos' ) );
		
		foreach ( $categories as $key => $category ) {
			$options[ $key ] = $category['icon'] . ' ' . $category['label'] . ' (' . count( $category['tools'] ) . ')';
		}
		
		return $options;
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		// General Settings Section.
		$this->start_controls_section(
			'section_settings',
			array(
				'label' => __( 'Quick Actions Settings', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'widget_title',
			array(
				'label'       => __( 'Widget Title', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'AI Quick Actions', 'mcp-ai-wpoos' ),
				'placeholder' => __( 'Enter title…', 'mcp-ai-wpoos' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => __( 'Description', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Select a category and tool to perform AI-powered actions with a single click.', 'mcp-ai-wpoos' ),
				'placeholder' => __( 'Provide a description…', 'mcp-ai-wpoos' ),
				'rows'        => 3,
			)
		);

		$this->add_control(
			'default_category',
			array(
				'label'       => __( 'Default Category', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_category_options(),
				'default'     => 'image',
				'label_block' => true,
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'grid' => __( 'Grid', 'mcp-ai-wpoos' ),
					'list' => __( 'List', 'mcp-ai-wpoos' ),
				),
				'default' => 'grid',
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'mcp-ai-wpoos' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 6,
				'default'   => 3,
				'condition' => array(
					'layout' => 'grid',
				),
			)
		);

		$this->add_control(
			'show_icons',
			array(
				'label'        => __( 'Show Icons', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_descriptions',
			array(
				'label'        => __( 'Show Tool Descriptions', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'enable_file_upload',
			array(
				'label'        => __( 'Enable File Upload', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Allow users to upload files for processing.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'enable_media_library',
			array(
				'label'        => __( 'Enable Media Library Selection', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Allow users to select files from the media library.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();

		// Style Section.
		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Style', 'mcp-ai-wpoos' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => __( 'Button Color', 'mcp-ai-wpoos' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-quick-action-btn' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => __( 'Button Hover Color', 'mcp-ai-wpoos' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-quick-action-btn:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Button Text Color', 'mcp-ai-wpoos' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-quick-action-btn' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .wp-mcp-ai-quick-action-btn',
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'mcp-ai-wpoos' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-quick-action-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'mcp-ai-wpoos' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-quick-action-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$categories = $this->get_categorized_tools();
		
		$widget_id = 'wp-mcp-ai-quick-actions-' . $this->get_id();
		
		?>
		<div class="wp-mcp-ai-quick-actions-widget" id="<?php echo esc_attr( $widget_id ); ?>">
			<?php if ( ! empty( $settings['widget_title'] ) ) : ?>
				<h3 class="wp-mcp-ai-quick-actions-title"><?php echo esc_html( $settings['widget_title'] ); ?></h3>
			<?php endif; ?>
			
			<?php if ( ! empty( $settings['description'] ) ) : ?>
				<p class="wp-mcp-ai-quick-actions-description"><?php echo esc_html( $settings['description'] ); ?></p>
			<?php endif; ?>
			
			<div class="wp-mcp-ai-quick-actions-controls">
				<label for="<?php echo esc_attr( $widget_id ); ?>-category" class="screen-reader-text">
					<?php esc_html_e( 'Select Category', 'mcp-ai-wpoos' ); ?>
				</label>
				<select id="<?php echo esc_attr( $widget_id ); ?>-category" class="wp-mcp-ai-category-select">
					<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
					<?php foreach ( $categories as $key => $category ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['default_category'], $key ); ?>>
							<?php echo esc_html( $category['icon'] . ' ' . $category['label'] . ' (' . count( $category['tools'] ) . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<?php if ( 'yes' === $settings['enable_file_upload'] || 'yes' === $settings['enable_media_library'] ) : ?>
				<div class="wp-mcp-ai-file-controls">
					<?php if ( 'yes' === $settings['enable_file_upload'] ) : ?>
						<div class="wp-mcp-ai-file-upload">
							<label for="<?php echo esc_attr( $widget_id ); ?>-file" class="wp-mcp-ai-upload-btn">
								<span class="dashicons dashicons-upload"></span>
								<?php esc_html_e( 'Upload File', 'mcp-ai-wpoos' ); ?>
							</label>
							<input type="file" id="<?php echo esc_attr( $widget_id ); ?>-file" class="wp-mcp-ai-file-input" accept="image/*,video/*,audio/*" />
							<span class="wp-mcp-ai-filename"></span>
						</div>
					<?php endif; ?>
					
					<?php if ( 'yes' === $settings['enable_media_library'] ) : ?>
						<button type="button" class="wp-mcp-ai-media-library-btn button">
							<span class="dashicons dashicons-admin-media"></span>
							<?php esc_html_e( 'Select from Media Library', 'mcp-ai-wpoos' ); ?>
						</button>
					<?php endif; ?>
					
					<div class="wp-mcp-ai-file-preview" style="display: none;">
						<img src="" alt="" class="wp-mcp-ai-preview-image" />
						<button type="button" class="wp-mcp-ai-remove-file">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
				</div>
			<?php endif; ?>
			
			<div class="wp-mcp-ai-tools-container <?php echo esc_attr( 'layout-' . $settings['layout'] ); ?>" 
			     data-columns="<?php echo esc_attr( $settings['columns'] ); ?>">
				<?php foreach ( $categories as $category_key => $category ) : ?>
					<div class="wp-mcp-ai-tools-category" data-category="<?php echo esc_attr( $category_key ); ?>">
						<?php foreach ( $category['tools'] as $tool ) : ?>
							<button type="button" 
							        class="wp-mcp-ai-quick-action-btn" 
							        data-tool="<?php echo esc_attr( $tool['slug'] ); ?>"
							        data-category="<?php echo esc_attr( $category_key ); ?>">
								<?php if ( 'yes' === $settings['show_icons'] ) : ?>
									<span class="wp-mcp-ai-tool-icon"><?php echo esc_html( $category['icon'] ); ?></span>
								<?php endif; ?>
								<span class="wp-mcp-ai-tool-name"><?php echo esc_html( $tool['name'] ); ?></span>
								<?php if ( 'yes' === $settings['show_descriptions'] && ! empty( $tool['description'] ) ) : ?>
									<span class="wp-mcp-ai-tool-description"><?php echo esc_html( wp_trim_words( $tool['description'], 10 ) ); ?></span>
								<?php endif; ?>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			
			<div class="wp-mcp-ai-progress" style="display: none;">
				<div class="wp-mcp-ai-progress-bar">
					<div class="wp-mcp-ai-progress-fill"></div>
				</div>
				<p class="wp-mcp-ai-progress-message"><?php esc_html_e( 'Processing…', 'mcp-ai-wpoos' ); ?></p>
			</div>
			
			<div class="wp-mcp-ai-result-preview" style="display: none;">
				<h4><?php esc_html_e( 'Result Preview', 'mcp-ai-wpoos' ); ?></h4>
				<div class="wp-mcp-ai-result-content"></div>
				<div class="wp-mcp-ai-result-actions">
					<button type="button" class="wp-mcp-ai-apply-result button button-primary">
						<?php esc_html_e( 'Apply', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" class="wp-mcp-ai-regenerate-result button">
						<?php esc_html_e( 'Regenerate', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" class="wp-mcp-ai-cancel-result button">
						<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
			</div>
			
			<div class="wp-mcp-ai-success-message" style="display: none;">
				<span class="dashicons dashicons-yes-alt"></span>
				<span class="wp-mcp-ai-success-text"></span>
			</div>
			
			<div class="wp-mcp-ai-error-message" style="display: none;">
				<span class="dashicons dashicons-warning"></span>
				<span class="wp-mcp-ai-error-text"></span>
			</div>
		</div>
		<?php
	}
}
