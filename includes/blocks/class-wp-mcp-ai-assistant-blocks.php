<?php
/**
 * Gutenberg blocks integration for WP oOS Assistant widgets.
 *
 * Registers assistant-related blocks for use in the block editor.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for assistant widgets.
 */
class WP_MCP_AI_Assistant_Blocks {

	/**
	 * Initialize the blocks integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register assistant blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Assistant Defaults Block.
		register_block_type(
			'wp-mcp-ai/assistant-defaults',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_defaults_block' ),
				'attributes'      => array(
					'title'            => array(
						'type'    => 'string',
						'default' => __( 'Assistant model defaults', 'wp-mcp-ai' ),
					),
					'assistantId'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'showSystemPrompt' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Assistant Base Knowledge Block.
		register_block_type(
			'wp-mcp-ai/assistant-base-knowledge',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_base_knowledge_block' ),
				'attributes'      => array(
					'title'        => array(
						'type'    => 'string',
						'default' => __( 'Assistant knowledge base', 'wp-mcp-ai' ),
					),
					'assistantId'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'showSizes'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Assistant Prompt Shortcuts Block.
		register_block_type(
			'wp-mcp-ai/assistant-prompt-shortcuts',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_prompt_shortcuts_block' ),
				'attributes'      => array(
					'title'              => array(
						'type'    => 'string',
						'default' => __( 'Assistant prompt shortcuts', 'wp-mcp-ai' ),
					),
					'assistantId'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'showDescriptions'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showPrompt'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Assistant Tools Block.
		register_block_type(
			'wp-mcp-ai/assistant-tools',
			array(
				'render_callback' => array( __CLASS__, 'render_assistant_tools_block' ),
				'attributes'      => array(
					'title'            => array(
						'type'    => 'string',
						'default' => __( 'Available assistant tools', 'wp-mcp-ai' ),
					),
					'assistantId'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'showDescriptions' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		$asset_file = WP_MCP_AI_PATH . 'assets/js/assistant-blocks.asset.php';
		$asset_data = file_exists( $asset_file ) ? include $asset_file : array( 'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components' ), 'version' => WP_MCP_AI_VERSION );

		wp_enqueue_script(
			'wp-mcp-ai-assistant-blocks',
			WP_MCP_AI_URL . 'assets/js/assistant-blocks.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		wp_localize_script(
			'wp-mcp-ai-assistant-blocks',
			'wpMcpAiAssistantBlocks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_assistant' ),
			)
		);
	}

	/**
	 * Render the assistant defaults block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_defaults_block( $attributes ) {
		$title             = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Assistant model defaults', 'wp-mcp-ai' );
		$assistant_id      = ! empty( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$show_prompt       = isset( $attributes['showSystemPrompt'] ) ? (bool) $attributes['showSystemPrompt'] : true;

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return '<p>' . esc_html__( 'Select an assistant in the block settings to view its defaults.', 'wp-mcp-ai' ) . '</p>';
		}

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		if ( ! $config ) {
			return '<p>' . esc_html__( 'Assistant configuration not found.', 'wp-mcp-ai' ) . '</p>';
		}

		$provider_label = self::get_provider_label( isset( $config['provider'] ) ? $config['provider'] : '' );
		$model          = isset( $config['model'] ) ? $config['model'] : '';
		$temperature    = isset( $config['temperature'] ) ? $config['temperature'] : null;
		$prompt         = isset( $config['system_prompt'] ) ? $config['system_prompt'] : '';

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-assistant-defaults wp-mcp-ai-assistant-defaults">
			<h3 class="wp-mcp-ai-assistant-defaults__title"><?php echo esc_html( $title ); ?></h3>
			<dl class="wp-mcp-ai-assistant-defaults__list">
				<?php if ( ! empty( $provider_label ) ) : ?>
					<dt class="wp-mcp-ai-assistant-defaults__label"><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></dt>
					<dd class="wp-mcp-ai-assistant-defaults__value"><?php echo esc_html( $provider_label ); ?></dd>
				<?php endif; ?>

				<?php if ( ! empty( $model ) ) : ?>
					<dt class="wp-mcp-ai-assistant-defaults__label"><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></dt>
					<dd class="wp-mcp-ai-assistant-defaults__value"><?php echo esc_html( $model ); ?></dd>
				<?php endif; ?>

				<?php if ( null !== $temperature && '' !== $temperature ) : ?>
					<dt class="wp-mcp-ai-assistant-defaults__label"><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></dt>
					<dd class="wp-mcp-ai-assistant-defaults__value"><?php echo esc_html( number_format_i18n( floatval( $temperature ), 2 ) ); ?></dd>
				<?php endif; ?>
			</dl>

			<?php if ( $show_prompt && ! empty( $prompt ) ) : ?>
				<div class="wp-mcp-ai-assistant-defaults__system-prompt">
					<h4 class="wp-mcp-ai-assistant-defaults__system-prompt-heading"><?php esc_html_e( 'System prompt', 'wp-mcp-ai' ); ?></h4>
					<div class="wp-mcp-ai-assistant-defaults__system-prompt-content"><?php echo wp_kses_post( wpautop( $prompt ) ); ?></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the assistant base knowledge block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_base_knowledge_block( $attributes ) {
		$title        = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Assistant knowledge base', 'wp-mcp-ai' );
		$assistant_id = ! empty( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$show_sizes   = isset( $attributes['showSizes'] ) ? (bool) $attributes['showSizes'] : true;

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return '<p>' . esc_html__( 'Select an assistant in the block settings to view its knowledge base.', 'wp-mcp-ai' ) . '</p>';
		}

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		if ( ! $config ) {
			return '<p>' . esc_html__( 'Assistant configuration not found.', 'wp-mcp-ai' ) . '</p>';
		}

		$memory_files = isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ? $config['memory_files'] : array();
		$vector_store = isset( $config['vector_store_id'] ) ? $config['vector_store_id'] : '';

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-assistant-base-knowledge wp-mcp-ai-assistant-memory">
			<h3 class="wp-mcp-ai-assistant-memory__title"><?php echo esc_html( $title ); ?></h3>

			<?php if ( empty( $memory_files ) ) : ?>
				<p class="wp-mcp-ai-assistant-memory__notice"><?php esc_html_e( 'No base knowledge files have been attached to this assistant yet.', 'wp-mcp-ai' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-assistant-memory__files">
					<?php foreach ( $memory_files as $file_id ) : ?>
						<?php
						$file_id    = absint( $file_id );
						$attachment = get_post( $file_id );
						if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
							continue;
						}
						$file_title = get_the_title( $attachment );
						$file_url   = wp_get_attachment_url( $file_id );
						$file_size  = '';

						if ( $show_sizes ) {
							$file_path = get_attached_file( $file_id );
							if ( $file_path && file_exists( $file_path ) ) {
								$size_bytes = filesize( $file_path );
								if ( false !== $size_bytes ) {
									$file_size = size_format( (int) $size_bytes );
								}
							}
						}
						?>
						<li class="wp-mcp-ai-assistant-memory__file">
							<?php if ( $file_url ) : ?>
								<a class="wp-mcp-ai-assistant-memory__file-link" href="<?php echo esc_url( $file_url ); ?>"><?php echo esc_html( $file_title ); ?></a>
							<?php else : ?>
								<span class="wp-mcp-ai-assistant-memory__file-label"><?php echo esc_html( $file_title ); ?></span>
							<?php endif; ?>
							<?php if ( $show_sizes && ! empty( $file_size ) ) : ?>
								<span class="wp-mcp-ai-assistant-memory__file-size"><?php echo esc_html( $file_size ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $vector_store ) ) : ?>
				<div class="wp-mcp-ai-assistant-memory__vector">
					<span class="wp-mcp-ai-assistant-memory__vector-label"><?php esc_html_e( 'Vector Store ID:', 'wp-mcp-ai' ); ?></span>
					<code class="wp-mcp-ai-assistant-memory__vector-value"><?php echo esc_html( $vector_store ); ?></code>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the assistant prompt shortcuts block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_prompt_shortcuts_block( $attributes ) {
		$title             = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Assistant prompt shortcuts', 'wp-mcp-ai' );
		$assistant_id      = ! empty( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$show_descriptions = isset( $attributes['showDescriptions'] ) ? (bool) $attributes['showDescriptions'] : true;
		$show_prompt       = isset( $attributes['showPrompt'] ) ? (bool) $attributes['showPrompt'] : false;

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return '<p>' . esc_html__( 'Select an assistant in the block settings to view its prompt shortcuts.', 'wp-mcp-ai' ) . '</p>';
		}

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		if ( ! $config ) {
			return '<p>' . esc_html__( 'Assistant configuration not found.', 'wp-mcp-ai' ) . '</p>';
		}

		$shortcuts = isset( $config['tool_shortcuts'] ) && is_array( $config['tool_shortcuts'] ) ? $config['tool_shortcuts'] : array();

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-assistant-prompt-shortcuts wp-mcp-ai-assistant-shortcuts">
			<h3 class="wp-mcp-ai-assistant-shortcuts__title"><?php echo esc_html( $title ); ?></h3>

			<?php if ( empty( $shortcuts ) ) : ?>
				<p class="wp-mcp-ai-assistant-shortcuts__notice"><?php esc_html_e( 'No prompt shortcuts have been saved for this assistant yet.', 'wp-mcp-ai' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-assistant-shortcuts__list">
					<?php foreach ( $shortcuts as $shortcut ) : ?>
						<?php if ( ! is_array( $shortcut ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php
						$label       = isset( $shortcut['label'] ) ? $shortcut['label'] : '';
						$payload     = isset( $shortcut['payload'] ) ? $shortcut['payload'] : '';
						$description = isset( $shortcut['description'] ) ? $shortcut['description'] : '';
						?>
						<li class="wp-mcp-ai-assistant-shortcuts__item">
							<?php if ( ! empty( $label ) ) : ?>
								<span class="wp-mcp-ai-assistant-shortcuts__label"><?php echo esc_html( $label ); ?></span>
							<?php endif; ?>

							<?php if ( $show_descriptions && ! empty( $description ) ) : ?>
								<div class="wp-mcp-ai-assistant-shortcuts__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
							<?php endif; ?>

							<?php if ( $show_prompt && ! empty( $payload ) ) : ?>
								<pre class="wp-mcp-ai-assistant-shortcuts__payload"><?php echo esc_html( $payload ); ?></pre>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the assistant tools block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_assistant_tools_block( $attributes ) {
		$title             = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Available assistant tools', 'wp-mcp-ai' );
		$assistant_id      = ! empty( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$show_descriptions = isset( $attributes['showDescriptions'] ) ? (bool) $attributes['showDescriptions'] : true;

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return '<p>' . esc_html__( 'Select an assistant in the block settings to view its tools.', 'wp-mcp-ai' ) . '</p>';
		}

		$tools_data = self::get_assistant_tools_data( $assistant_id );
		$registered = isset( $tools_data['registered'] ) ? $tools_data['registered'] : array();
		$missing    = isset( $tools_data['missing'] ) ? $tools_data['missing'] : array();

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-assistant-tools wp-mcp-ai-assistant-tools">
			<h3 class="wp-mcp-ai-assistant-tools__title"><?php echo esc_html( $title ); ?></h3>

			<?php if ( empty( $registered ) ) : ?>
				<p class="wp-mcp-ai-assistant-tools__notice"><?php esc_html_e( 'No tools have been assigned to this assistant yet.', 'wp-mcp-ai' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-assistant-tools__list">
					<?php foreach ( $registered as $tool ) : ?>
						<li class="wp-mcp-ai-assistant-tools__item">
							<span class="wp-mcp-ai-assistant-tools__name"><?php echo esc_html( $tool['name'] ); ?></span>
							<?php if ( $show_descriptions && ! empty( $tool['description'] ) ) : ?>
								<div class="wp-mcp-ai-assistant-tools__description"><?php echo wp_kses_post( wpautop( $tool['description'] ) ); ?></div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $missing ) ) : ?>
				<div class="wp-mcp-ai-assistant-tools__missing">
					<h4 class="wp-mcp-ai-assistant-tools__missing-heading"><?php esc_html_e( 'Missing registrations', 'wp-mcp-ai' ); ?></h4>
					<ul class="wp-mcp-ai-assistant-tools__missing-list">
						<?php foreach ( $missing as $slug ) : ?>
							<li class="wp-mcp-ai-assistant-tools__missing-item"><code><?php echo esc_html( $slug ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get assistant tools data.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Tools data with registered and missing tools.
	 */
	protected static function get_assistant_tools_data( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array( 'registered' => array(), 'missing' => array() );
		}

		$stored = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$requested = array();
		foreach ( $stored as $slug ) {
			if ( is_string( $slug ) ) {
				$slug = sanitize_key( $slug );
				if ( '' !== $slug ) {
					$requested[] = $slug;
				}
			}
		}

		$requested = array_values( array_unique( $requested ) );

		if ( empty( $requested ) ) {
			return array( 'registered' => array(), 'missing' => array() );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array( 'registered' => array(), 'missing' => $requested );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( method_exists( $registry, 'init' ) ) {
			$registry->init();
		}

		$registered = array();
		$missing    = array();

		foreach ( $requested as $slug ) {
			$tool = $registry->get_tool( $slug );

			if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
				$registered[] = array(
					'slug'        => $slug,
					'name'        => $tool->get_name(),
					'description' => $tool->get_description(),
				);
			} else {
				$missing[] = $slug;
			}
		}

		return array( 'registered' => $registered, 'missing' => $missing );
	}

	/**
	 * Convert the provider slug into a readable label.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	protected static function get_provider_label( $provider ) {
		$provider = sanitize_key( $provider );

		if ( '' === $provider ) {
			return '';
		}

		switch ( $provider ) {
			case 'openai':
				return __( 'OpenAI', 'wp-mcp-ai' );
			case 'gemini':
				return __( 'Gemini', 'wp-mcp-ai' );
			case 'ollama':
				return __( 'Ollama', 'wp-mcp-ai' );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );
	}
}

// Initialize the blocks.
WP_MCP_AI_Assistant_Blocks::init();
