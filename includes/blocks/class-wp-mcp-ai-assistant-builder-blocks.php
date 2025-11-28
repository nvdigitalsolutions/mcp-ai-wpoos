<?php
/**
 * Gutenberg blocks for WP oOS Assistant Builder.
 *
 * Registers chat and assistant builder blocks for use in the block editor.
 * These are dynamic blocks that use PHP render callbacks since they need
 * to fetch real-time data (assistants, tools) from the WordPress backend.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for assistant builder functionality.
 */
class WP_MCP_AI_Assistant_Builder_Blocks {

	/**
	 * Block script handle.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-assistant-builder-blocks';

	/**
	 * Block style handle.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-assistant-builder-blocks-style';

	/**
	 * Initialize the blocks integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register assistant builder blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// AI Chat Block - renders the chat interface.
		register_block_type(
			'wp-mcp-ai/chat',
			array(
				'render_callback' => array( __CLASS__, 'render_chat_block' ),
				'attributes'      => self::get_chat_block_attributes(),
				'supports'        => array(
					'align'  => array( 'wide', 'full' ),
					'anchor' => true,
				),
			)
		);

		// Assistant Selector Block - dropdown to select assistants.
		register_block_type(
			'wp-mcp-ai/assistant-selector',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_selector_block' ),
				'attributes'      => self::get_assistant_selector_attributes(),
			)
		);

		// Tools Grid Block - displays available tools.
		register_block_type(
			'wp-mcp-ai/tools-grid',
			array(
				'render_callback' => array( __CLASS__, 'render_tools_grid_block' ),
				'attributes'      => self::get_tools_grid_attributes(),
			)
		);

		// Knowledge Base Block - file uploads for assistant memory.
		register_block_type(
			'wp-mcp-ai/knowledge-base',
			array(
				'render_callback' => array( __CLASS__, 'render_knowledge_base_block' ),
				'attributes'      => self::get_knowledge_base_attributes(),
			)
		);

		// Assistant Builder Block - combines selector, tools, knowledge base, and chat.
		register_block_type(
			'wp-mcp-ai/assistant-builder',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_builder_block' ),
				'attributes'      => self::get_assistant_builder_attributes(),
				'supports'        => array(
					'align'  => array( 'wide', 'full' ),
					'anchor' => true,
				),
			)
		);
	}

	/**
	 * Get chat block attributes.
	 *
	 * @return array Block attributes schema.
	 */
	private static function get_chat_block_attributes() {
		return array(
			'assistantId'         => array(
				'type'    => 'number',
				'default' => 0,
			),
			'allowGuests'         => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'saveTranscript'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'enableStreaming'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'allowSensitiveTools' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'showBuildButton'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'placeholder'         => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Get assistant selector block attributes.
	 *
	 * @return array Block attributes schema.
	 */
	private static function get_assistant_selector_attributes() {
		return array(
			'defaultAssistantId' => array(
				'type'    => 'number',
				'default' => 0,
			),
			'label'              => array(
				'type'    => 'string',
				'default' => '',
			),
			'showStartButton'    => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'startButtonText'    => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Get tools grid block attributes.
	 *
	 * @return array Block attributes schema.
	 */
	private static function get_tools_grid_attributes() {
		return array(
			'title'            => array(
				'type'    => 'string',
				'default' => '',
			),
			'description'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'showDescriptions' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'startCollapsed'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showActions'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'selectedTools'    => array(
				'type'    => 'array',
				'default' => array(),
			),
		);
	}

	/**
	 * Get assistant builder block attributes.
	 *
	 * @return array Block attributes schema.
	 */
	private static function get_assistant_builder_attributes() {
		return array(
			'showAssistantSelector'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showToolsGrid'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showKnowledgeBase'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showBuildButton'        => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'defaultAssistantId'     => array(
				'type'    => 'number',
				'default' => 0,
			),
			'layout'                 => array(
				'type'    => 'string',
				'default' => 'stacked',
			),
			'toolsCollapsed'         => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showToolDescriptions'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'enableStreaming'        => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'chatPlaceholder'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'allowedFileTypes'       => array(
				'type'    => 'string',
				'default' => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
			),
			'maxFiles'               => array(
				'type'    => 'number',
				'default' => 10,
			),
			'maxFileSizeMB'          => array(
				'type'    => 'number',
				'default' => 10,
			),
		);
	}

	/**
	 * Get knowledge base block attributes.
	 *
	 * @return array Block attributes schema.
	 */
	private static function get_knowledge_base_attributes() {
		return array(
			'title'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'description'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'allowedTypes'    => array(
				'type'    => 'string',
				'default' => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
			),
			'maxFiles'        => array(
				'type'    => 'number',
				'default' => 10,
			),
			'maxFileSizeMB'   => array(
				'type'    => 'number',
				'default' => 10,
			),
			'showPreview'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'uploadedFileIds' => array(
				'type'    => 'array',
				'default' => array(),
			),
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		// Register block editor script.
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Get allowed file types.
		$allowed_types = self::get_allowed_knowledge_base_types();

		// Localize script with data for the editor.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiBlocks',
			array(
				'assistants'       => self::get_assistants_for_editor(),
				'toolGroups'       => self::get_tool_groups_for_editor(),
				'restUrl'          => rest_url( 'mcp-ai/v1' ),
				'wpRestUrl'        => rest_url( 'wp/v2' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'createNonce'      => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
				'maxUploadSize'    => wp_max_upload_size(),
				'allowedFileTypes' => $allowed_types,
				'i18n'             => array(
					'selectAssistant'    => __( '— Select an assistant —', 'wp-mcp-ai' ),
					'noAssistants'       => __( 'No assistants found.', 'wp-mcp-ai' ),
					'startChat'          => __( 'Start Chat', 'wp-mcp-ai' ),
					'selectAll'          => __( 'Select All', 'wp-mcp-ai' ),
					'deselectAll'        => __( 'Deselect All', 'wp-mcp-ai' ),
					'toolsSelected'      => __( 'tools selected', 'wp-mcp-ai' ),
					'availableTools'     => __( 'Available Tools', 'wp-mcp-ai' ),
					'buildAssistant'     => __( 'Build', 'wp-mcp-ai' ),
					'chatPlaceholder'    => __( 'Describe the assistant you want to create...', 'wp-mcp-ai' ),
					'noPermission'       => __( 'You do not have permission to use this feature.', 'wp-mcp-ai' ),
					'assistantSelector'  => __( 'Select an Assistant:', 'wp-mcp-ai' ),
					'knowledgeBase'      => __( 'Knowledge Base', 'wp-mcp-ai' ),
					'uploadFiles'        => __( 'Upload Files', 'wp-mcp-ai' ),
					'dropFilesHere'      => __( 'Drop files here or click to upload', 'wp-mcp-ai' ),
					'filesUploaded'      => __( 'files uploaded', 'wp-mcp-ai' ),
					'removeFile'         => __( 'Remove', 'wp-mcp-ai' ),
					'uploading'          => __( 'Uploading...', 'wp-mcp-ai' ),
					'uploadError'        => __( 'Upload failed', 'wp-mcp-ai' ),
					'maxFilesReached'    => __( 'Maximum number of files reached', 'wp-mcp-ai' ),
					'fileTooLarge'       => __( 'File is too large', 'wp-mcp-ai' ),
					'invalidFileType'    => __( 'Invalid file type', 'wp-mcp-ai' ),
				),
			)
		);

		// Register block editor styles.
		wp_enqueue_style(
			self::STYLE_HANDLE,
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Enqueue frontend assets for blocks.
	 */
	public static function enqueue_frontend_assets() {
		// Only enqueue if blocks are present on the page.
		if ( ! has_block( 'wp-mcp-ai/assistant-builder' ) &&
			 ! has_block( 'wp-mcp-ai/knowledge-base' ) &&
			 ! has_block( 'wp-mcp-ai/tools-grid' ) &&
			 ! has_block( 'wp-mcp-ai/assistant-selector' ) ) {
			return;
		}

		// Enqueue frontend script.
		wp_enqueue_script(
			'wp-mcp-ai-assistant-builder-frontend',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Enqueue frontend styles.
		wp_enqueue_style(
			'wp-mcp-ai-assistant-builder-frontend',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Get allowed file types for knowledge base uploads.
	 *
	 * @return array
	 */
	private static function get_allowed_knowledge_base_types() {
		return array(
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
			'md'   => 'text/markdown',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'csv'  => 'text/csv',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'html' => 'text/html',
			'rtf'  => 'application/rtf',
		);
	}

	/**
	 * Get assistants data for the block editor.
	 *
	 * @return array
	 */
	private static function get_assistants_for_editor() {
		$assistants = array();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $assistants;
		}

		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$tools = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
			if ( ! is_array( $tools ) ) {
				$tools = array();
			}

			$assistants[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'tools' => $tools,
			);
		}

		return $assistants;
	}

	/**
	 * Get tool groups data for the block editor.
	 *
	 * @return array
	 */
	private static function get_tool_groups_for_editor() {
		$groups = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $groups;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		$group_map    = array();
		$group_labels = array();

		if ( method_exists( $registry, 'get_tool_group_map' ) ) {
			$group_map = $registry->get_tool_group_map();
		}
		if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
			$group_labels = $registry->get_tool_group_labels();
		}

		if ( ! is_array( $group_map ) ) {
			$group_map = array();
		}
		if ( ! is_array( $group_labels ) ) {
			$group_labels = array();
		}
		if ( ! isset( $group_labels['other'] ) ) {
			$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
		}

		$grouped = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();
			if ( '' === $slug ) {
				continue;
			}

			$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';
			if ( '' === $group_id ) {
				$group_id = 'other';
			}

			if ( ! isset( $grouped[ $group_id ] ) ) {
				$grouped[ $group_id ] = array(
					'id'    => $group_id,
					'label' => isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucfirst( $group_id ),
					'tools' => array(),
				);
			}

			$definition = $tool->get_definition();

			$grouped[ $group_id ]['tools'][] = array(
				'slug'        => $slug,
				'name'        => isset( $definition['name'] ) ? $definition['name'] : $slug,
				'description' => isset( $definition['description'] ) ? $definition['description'] : '',
			);
		}

		// Order by group labels.
		foreach ( $group_labels as $group_id => $label ) {
			if ( isset( $grouped[ $group_id ] ) ) {
				$groups[] = $grouped[ $group_id ];
				unset( $grouped[ $group_id ] );
			}
		}
		foreach ( $grouped as $group ) {
			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Render the AI Chat block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_chat_block( $attributes ) {
		$assistant_id          = isset( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$allow_guests          = ! empty( $attributes['allowGuests'] );
		$save_transcript       = isset( $attributes['saveTranscript'] ) ? $attributes['saveTranscript'] : true;
		$enable_streaming      = isset( $attributes['enableStreaming'] ) ? $attributes['enableStreaming'] : true;
		$allow_sensitive_tools = ! empty( $attributes['allowSensitiveTools'] );
		$show_build_button     = ! empty( $attributes['showBuildButton'] );

		// Build shortcode attributes.
		$shortcode_atts = array();

		if ( $assistant_id ) {
			$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
		}

		if ( $allow_guests ) {
			$shortcode_atts[] = 'allow_guests="true"';
		}

		if ( ! $save_transcript ) {
			$shortcode_atts[] = 'save_transcript="false"';
		}

		if ( $enable_streaming ) {
			$shortcode_atts[] = 'enable_streaming="true"';
		}

		if ( $allow_sensitive_tools ) {
			$shortcode_atts[] = 'allow_sensitive_tools="true"';
		}

		$shortcode = '[mcp_ai_chat ' . implode( ' ', $shortcode_atts ) . ']';

		$wrapper_class = 'wp-block-wp-mcp-ai-chat';
		if ( $show_build_button ) {
			$wrapper_class .= ' wp-block-wp-mcp-ai-chat--with-build';
		}

		$output = '<div class="' . esc_attr( $wrapper_class ) . '">';
		$output .= do_shortcode( $shortcode );
		$output .= '</div>';

		return $output;
	}

	/**
	 * Render the Assistant Selector block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_selector_block( $attributes ) {
		$default_id        = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
		$label             = isset( $attributes['label'] ) && '' !== $attributes['label'] ? $attributes['label'] : __( 'Select an Assistant:', 'wp-mcp-ai' );
		$show_start_button = isset( $attributes['showStartButton'] ) ? $attributes['showStartButton'] : true;
		$start_button_text = isset( $attributes['startButtonText'] ) && '' !== $attributes['startButtonText'] ? $attributes['startButtonText'] : __( 'Start Chat', 'wp-mcp-ai' );

		$assistants = self::get_assistants_for_editor();
		$unique_id  = wp_unique_id( 'wp-mcp-ai-assistant-selector-' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-assistant-selector" data-block-id="<?php echo esc_attr( $unique_id ); ?>">
			<label for="<?php echo esc_attr( $unique_id ); ?>-select">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $unique_id ); ?>-select" class="wp-mcp-ai-assistant-selector__select">
				<option value=""><?php esc_html_e( '— Select an assistant —', 'wp-mcp-ai' ); ?></option>
				<?php foreach ( $assistants as $assistant ) : ?>
					<option 
						value="<?php echo esc_attr( $assistant['id'] ); ?>"
						data-tools="<?php echo esc_attr( wp_json_encode( $assistant['tools'] ) ); ?>"
						<?php selected( $default_id, $assistant['id'] ); ?>
					>
						<?php echo esc_html( $assistant['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $show_start_button ) : ?>
				<button type="button" class="wp-mcp-ai-assistant-selector__start button button-primary" disabled>
					<?php echo esc_html( $start_button_text ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the Tools Grid block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_tools_grid_block( $attributes ) {
		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<p class="wp-block-wp-mcp-ai-tools-grid__notice">' . esc_html__( 'You do not have permission to view tools.', 'wp-mcp-ai' ) . '</p>';
		}

		$title             = isset( $attributes['title'] ) && '' !== $attributes['title'] ? $attributes['title'] : __( 'Available Tools', 'wp-mcp-ai' );
		$description       = isset( $attributes['description'] ) && '' !== $attributes['description'] ? $attributes['description'] : __( 'Select or deselect tools to customize what capabilities the assistant can use.', 'wp-mcp-ai' );
		$show_descriptions = isset( $attributes['showDescriptions'] ) ? $attributes['showDescriptions'] : true;
		$start_collapsed   = isset( $attributes['startCollapsed'] ) ? $attributes['startCollapsed'] : true;
		$show_actions      = isset( $attributes['showActions'] ) ? $attributes['showActions'] : true;
		$selected_tools    = isset( $attributes['selectedTools'] ) ? $attributes['selectedTools'] : array();

		$groups    = self::get_tool_groups_for_editor();
		$unique_id = wp_unique_id( 'wp-mcp-ai-tools-grid-' );

		if ( empty( $groups ) ) {
			return '<p class="wp-block-wp-mcp-ai-tools-grid__notice">' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
		}

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-tools-grid" data-block-id="<?php echo esc_attr( $unique_id ); ?>">
			<?php if ( $title ) : ?>
				<h3 class="wp-block-wp-mcp-ai-tools-grid__title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="wp-block-wp-mcp-ai-tools-grid__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<?php if ( $show_actions ) : ?>
				<div class="wp-block-wp-mcp-ai-tools-grid__actions">
					<button type="button" class="button wp-mcp-ai-tools-grid__select-all">
						<?php esc_html_e( 'Select All', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button wp-mcp-ai-tools-grid__deselect-all">
						<?php esc_html_e( 'Deselect All', 'wp-mcp-ai' ); ?>
					</button>
					<span class="wp-mcp-ai-tools-grid__count">
						<strong class="wp-mcp-ai-tools-grid__selected-count">0</strong>
						<?php esc_html_e( 'tools selected', 'wp-mcp-ai' ); ?>
					</span>
				</div>
			<?php endif; ?>

			<div class="wp-block-wp-mcp-ai-tools-grid__groups">
				<?php foreach ( $groups as $group ) : ?>
					<?php $open_attr = $start_collapsed ? '' : ' open'; ?>
					<details class="wp-block-wp-mcp-ai-tools-grid__group"<?php echo $open_attr; ?>>
						<summary>
							<span class="wp-block-wp-mcp-ai-tools-grid__group-title"><?php echo esc_html( $group['label'] ); ?></span>
							<span class="wp-block-wp-mcp-ai-tools-grid__group-count">
								<span class="wp-mcp-ai-tools-grid__group-selected">0</span> / <?php echo esc_html( count( $group['tools'] ) ); ?>
							</span>
						</summary>
						<ul class="wp-block-wp-mcp-ai-tools-grid__list">
							<?php foreach ( $group['tools'] as $tool ) : ?>
								<?php
								$is_selected = in_array( $tool['slug'], $selected_tools, true );
								$item_class  = 'wp-block-wp-mcp-ai-tools-grid__item';
								if ( $is_selected ) {
									$item_class .= ' wp-block-wp-mcp-ai-tools-grid__item--selected';
								}
								?>
								<li class="<?php echo esc_attr( $item_class ); ?>" data-tool-slug="<?php echo esc_attr( $tool['slug'] ); ?>">
									<div class="wp-block-wp-mcp-ai-tools-grid__item-header">
										<input 
											type="checkbox" 
											class="wp-mcp-ai-tools-grid__checkbox"
											id="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>"
											value="<?php echo esc_attr( $tool['slug'] ); ?>"
											<?php checked( $is_selected ); ?>
										>
										<label for="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>">
											<span class="wp-block-wp-mcp-ai-tools-grid__item-name"><?php echo esc_html( $tool['name'] ); ?></span>
										</label>
									</div>
									<?php if ( $show_descriptions && ! empty( $tool['description'] ) ) : ?>
										<p class="wp-block-wp-mcp-ai-tools-grid__item-description"><?php echo esc_html( $tool['description'] ); ?></p>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the Assistant Builder block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_builder_block( $attributes ) {
		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<div class="wp-block-wp-mcp-ai-assistant-builder__notice">'
				. '<p>' . esc_html__( 'You do not have permission to use the Assistant Builder.', 'wp-mcp-ai' ) . '</p>'
				. '</div>';
		}

		$show_assistant_selector = isset( $attributes['showAssistantSelector'] ) ? $attributes['showAssistantSelector'] : true;
		$show_tools_grid         = isset( $attributes['showToolsGrid'] ) ? $attributes['showToolsGrid'] : true;
		$show_build_button       = isset( $attributes['showBuildButton'] ) ? $attributes['showBuildButton'] : true;
		$default_assistant_id    = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
		$layout                  = isset( $attributes['layout'] ) ? $attributes['layout'] : 'stacked';
		$tools_collapsed         = isset( $attributes['toolsCollapsed'] ) ? $attributes['toolsCollapsed'] : true;
		$show_tool_descriptions  = isset( $attributes['showToolDescriptions'] ) ? $attributes['showToolDescriptions'] : true;
		$enable_streaming        = isset( $attributes['enableStreaming'] ) ? $attributes['enableStreaming'] : true;
		$chat_placeholder        = isset( $attributes['chatPlaceholder'] ) && '' !== $attributes['chatPlaceholder'] ? $attributes['chatPlaceholder'] : __( 'Describe the assistant you want to create...', 'wp-mcp-ai' );
		$show_knowledge_base     = isset( $attributes['showKnowledgeBase'] ) ? $attributes['showKnowledgeBase'] : true;
		$allowed_file_types      = isset( $attributes['allowedFileTypes'] ) ? $attributes['allowedFileTypes'] : '.pdf,.txt,.md,.doc,.docx,.csv,.json';
		$max_files               = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
		$max_file_size_mb        = isset( $attributes['maxFileSizeMB'] ) ? absint( $attributes['maxFileSizeMB'] ) : 10;

		$unique_id       = wp_unique_id( 'wp-mcp-ai-assistant-builder-' );
		$wrapper_classes = array( 'wp-block-wp-mcp-ai-assistant-builder' );
		$wrapper_classes[] = 'wp-block-wp-mcp-ai-assistant-builder--' . sanitize_html_class( $layout );

		// Build configuration for JavaScript.
		$config = array(
			'blockId'               => $unique_id,
			'showAssistantSelector' => $show_assistant_selector,
			'showToolsGrid'         => $show_tools_grid,
			'showKnowledgeBase'     => $show_knowledge_base,
			'showBuildButton'       => $show_build_button,
			'defaultAssistantId'    => $default_assistant_id,
			'enableStreaming'       => $enable_streaming,
			'chatPlaceholder'       => $chat_placeholder,
			'allowedFileTypes'      => $allowed_file_types,
			'maxFiles'              => $max_files,
			'maxFileSizeMB'         => $max_file_size_mb,
			'restUrl'               => rest_url( 'mcp-ai/v1' ),
			'wpRestUrl'             => rest_url( 'wp/v2' ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
			'createNonce'           => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
		);

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" data-block-id="<?php echo esc_attr( $unique_id ); ?>">
			<?php if ( $show_assistant_selector ) : ?>
				<?php
				echo self::render_assistant_selector_block(
					array(
						'defaultAssistantId' => $default_assistant_id,
						'showStartButton'    => true,
					)
				);
				?>
			<?php endif; ?>

			<?php if ( $show_tools_grid ) : ?>
				<div class="wp-block-wp-mcp-ai-assistant-builder__tools" style="display: none;">
					<?php
					echo self::render_tools_grid_block(
						array(
							'showDescriptions' => $show_tool_descriptions,
							'startCollapsed'   => $tools_collapsed,
							'showActions'      => true,
						)
					);
					?>
				</div>
			<?php endif; ?>

			<?php if ( $show_knowledge_base ) : ?>
				<div class="wp-block-wp-mcp-ai-assistant-builder__knowledge-base" style="display: none;">
					<?php
					echo self::render_knowledge_base_block(
						array(
							'allowedTypes'  => $allowed_file_types,
							'maxFiles'      => $max_files,
							'maxFileSizeMB' => $max_file_size_mb,
							'showPreview'   => true,
						)
					);
					?>
				</div>
			<?php endif; ?>

			<div class="wp-block-wp-mcp-ai-assistant-builder__chat" style="display: none;">
				<!-- Chat interface will be initialized via JavaScript -->
			</div>

			<script type="application/json" class="wp-mcp-ai-assistant-builder-config">
				<?php echo wp_json_encode( $config ); ?>
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the Knowledge Base block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_knowledge_base_block( $attributes ) {
		// Check user permissions.
		if ( ! current_user_can( 'upload_files' ) ) {
			return '<p class="wp-block-wp-mcp-ai-knowledge-base__notice">' . esc_html__( 'You do not have permission to upload files.', 'wp-mcp-ai' ) . '</p>';
		}

		$title           = isset( $attributes['title'] ) && '' !== $attributes['title'] ? $attributes['title'] : __( 'Knowledge Base', 'wp-mcp-ai' );
		$description     = isset( $attributes['description'] ) && '' !== $attributes['description'] ? $attributes['description'] : __( 'Upload files to include in the assistant\'s knowledge base. These files will be used for retrieval-augmented generation.', 'wp-mcp-ai' );
		$allowed_types   = isset( $attributes['allowedTypes'] ) ? $attributes['allowedTypes'] : '.pdf,.txt,.md,.doc,.docx,.csv,.json';
		$max_files       = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
		$max_file_size   = isset( $attributes['maxFileSizeMB'] ) ? absint( $attributes['maxFileSizeMB'] ) : 10;
		$show_preview    = isset( $attributes['showPreview'] ) ? $attributes['showPreview'] : true;
		$uploaded_ids    = isset( $attributes['uploadedFileIds'] ) ? $attributes['uploadedFileIds'] : array();

		$unique_id       = wp_unique_id( 'wp-mcp-ai-knowledge-base-' );
		$max_upload_size = min( wp_max_upload_size(), $max_file_size * 1024 * 1024 );

		// Get file type display names.
		$type_names = array_map( function( $ext ) {
			return strtoupper( ltrim( trim( $ext ), '.' ) );
		}, explode( ',', $allowed_types ) );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-knowledge-base" 
			 data-block-id="<?php echo esc_attr( $unique_id ); ?>"
			 data-allowed-types="<?php echo esc_attr( $allowed_types ); ?>"
			 data-max-files="<?php echo esc_attr( $max_files ); ?>"
			 data-max-size="<?php echo esc_attr( $max_upload_size ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			 data-upload-url="<?php echo esc_attr( rest_url( 'wp/v2/media' ) ); ?>">

			<?php if ( $title ) : ?>
				<h3 class="wp-block-wp-mcp-ai-knowledge-base__title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="wp-block-wp-mcp-ai-knowledge-base__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<!-- Upload Area -->
			<div class="wp-block-wp-mcp-ai-knowledge-base__upload-area">
				<div class="wp-block-wp-mcp-ai-knowledge-base__dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload files', 'wp-mcp-ai' ); ?>">
					<svg class="wp-block-wp-mcp-ai-knowledge-base__upload-icon" viewBox="0 0 24 24" aria-hidden="true">
						<path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
					</svg>
					<p class="wp-block-wp-mcp-ai-knowledge-base__dropzone-text">
						<?php esc_html_e( 'Drop files here or click to upload', 'wp-mcp-ai' ); ?>
					</p>
					<p class="wp-block-wp-mcp-ai-knowledge-base__dropzone-hint">
						<?php
						printf(
							/* translators: 1: file types, 2: max file size */
							esc_html__( 'Accepted: %1$s • Max %2$s per file', 'wp-mcp-ai' ),
							esc_html( implode( ', ', $type_names ) ),
							esc_html( size_format( $max_upload_size ) )
						);
						?>
					</p>
					<input 
						type="file" 
						class="wp-block-wp-mcp-ai-knowledge-base__file-input" 
						id="<?php echo esc_attr( $unique_id ); ?>-input"
						accept="<?php echo esc_attr( $allowed_types ); ?>"
						multiple
						hidden
					>
				</div>
			</div>

			<!-- File List -->
			<div class="wp-block-wp-mcp-ai-knowledge-base__files">
				<div class="wp-block-wp-mcp-ai-knowledge-base__files-header">
					<span class="wp-block-wp-mcp-ai-knowledge-base__files-count">
						<strong class="wp-mcp-ai-knowledge-base__count">0</strong> / <?php echo esc_html( $max_files ); ?>
						<?php esc_html_e( 'files', 'wp-mcp-ai' ); ?>
					</span>
					<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__clear-all button button-link" style="display: none;">
						<?php esc_html_e( 'Remove All', 'wp-mcp-ai' ); ?>
					</button>
				</div>
				<ul class="wp-block-wp-mcp-ai-knowledge-base__file-list" role="list">
					<!-- Files will be added here dynamically -->
				</ul>
			</div>

			<!-- Hidden input to store file IDs -->
			<input type="hidden" class="wp-block-wp-mcp-ai-knowledge-base__file-ids" name="knowledge_base_files" value="">

			<!-- Progress indicator -->
			<div class="wp-block-wp-mcp-ai-knowledge-base__progress" style="display: none;">
				<div class="wp-block-wp-mcp-ai-knowledge-base__progress-bar">
					<div class="wp-block-wp-mcp-ai-knowledge-base__progress-fill"></div>
				</div>
				<span class="wp-block-wp-mcp-ai-knowledge-base__progress-text"><?php esc_html_e( 'Uploading...', 'wp-mcp-ai' ); ?></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
