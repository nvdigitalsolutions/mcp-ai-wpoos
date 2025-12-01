<?php
/**
 * Profession Custom Post Type.
 *
 * Handles registration and management of the profession CPT.
 * This class follows separation of concerns - it only handles
 * WordPress registration, hooks, and admin UI integration.
 * Business logic is delegated to the service layer.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the profession custom post type and manages its WordPress integration.
 */
class WP_MCP_AI_Profession_CPT {
	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mcp_ai_profession';

	/**
	 * Meta key for profession category (advisory, creative, technical, etc.).
	 */
	const META_CATEGORY = '_wp_mcp_ai_profession_category';

	/**
	 * Meta key for expertise areas (array).
	 */
	const META_EXPERTISE = '_wp_mcp_ai_profession_expertise';

	/**
	 * Meta key for default tools (array of tool slugs).
	 */
	const META_DEFAULT_TOOLS = '_wp_mcp_ai_profession_default_tools';

	/**
	 * Meta key for role description.
	 */
	const META_ROLE_DESCRIPTION = '_wp_mcp_ai_profession_role_description';

	/**
	 * Meta key for warnings/disclaimers (array).
	 */
	const META_WARNINGS = '_wp_mcp_ai_profession_warnings';

	/**
	 * Meta key for knowledge base content.
	 */
	const META_KNOWLEDGE_BASE = '_wp_mcp_ai_profession_knowledge_base';

	/**
	 * Meta key for memory files (array of attachment IDs).
	 */
	const META_MEMORY_FILES = '_wp_mcp_ai_profession_memory_files';

	/**
	 * Meta key for vector store ID.
	 */
	const META_VECTOR_STORE_ID = '_wp_mcp_ai_profession_vector_store_id';

	/**
	 * Meta key for supported MIME types (array).
	 */
	const META_SUPPORTED_MIME_TYPES = '_wp_mcp_ai_profession_supported_mime_types';

	/**
	 * Meta key for associated assistant ID for testing.
	 */
	const META_ASSOCIATED_ASSISTANT = '_wp_mcp_ai_profession_associated_assistant';

	/**
	 * Metabox instances.
	 *
	 * @var array<string, WP_MCP_AI_Metabox_Base>
	 */
	protected $metaboxes = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Initialize metaboxes.
		$this->init_metaboxes();

		// Register hooks.
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Initialize metabox instances.
	 */
	protected function init_metaboxes() {
		// Load metabox classes.
		require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes-loader.php';

		// Initialize metabox instances.
		$this->metaboxes = array(
			'details'        => new WP_MCP_AI_Profession_Metabox_Details(),
			'expertise'      => new WP_MCP_AI_Profession_Metabox_Expertise(),
			'base-knowledge' => new WP_MCP_AI_Profession_Metabox_Base_Knowledge(),
			'defaults'       => new WP_MCP_AI_Profession_Metabox_Defaults(),
		);
	}

	/**
	 * Register the profession custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Professions', 'Post type general name', 'wp-mcp-ai' ),
			'singular_name'         => _x( 'Profession', 'Post type singular name', 'wp-mcp-ai' ),
			'menu_name'             => _x( 'Professions', 'Admin Menu text', 'wp-mcp-ai' ),
			'name_admin_bar'        => _x( 'Profession', 'Add New on Toolbar', 'wp-mcp-ai' ),
			'add_new'               => __( 'Add New', 'wp-mcp-ai' ),
			'add_new_item'          => __( 'Add New Profession', 'wp-mcp-ai' ),
			'new_item'              => __( 'New Profession', 'wp-mcp-ai' ),
			'edit_item'             => __( 'Edit Profession', 'wp-mcp-ai' ),
			'view_item'             => __( 'View Profession', 'wp-mcp-ai' ),
			'all_items'             => __( 'All Professions', 'wp-mcp-ai' ),
			'search_items'          => __( 'Search Professions', 'wp-mcp-ai' ),
			'parent_item_colon'     => __( 'Parent Professions:', 'wp-mcp-ai' ),
			'not_found'             => __( 'No professions found.', 'wp-mcp-ai' ),
			'not_found_in_trash'    => __( 'No professions found in Trash.', 'wp-mcp-ai' ),
			'featured_image'        => _x( 'Profession Icon', 'Overrides the "Featured Image" phrase', 'wp-mcp-ai' ),
			'set_featured_image'    => _x( 'Set profession icon', 'Overrides the "Set featured image" phrase', 'wp-mcp-ai' ),
			'remove_featured_image' => _x( 'Remove profession icon', 'Overrides the "Remove featured image" phrase', 'wp-mcp-ai' ),
			'use_featured_image'    => _x( 'Use as profession icon', 'Overrides the "Use as featured image" phrase', 'wp-mcp-ai' ),
			'archives'              => _x( 'Profession archives', 'The post type archive label', 'wp-mcp-ai' ),
			'insert_into_item'      => _x( 'Insert into profession', 'Overrides the "Insert into post" phrase', 'wp-mcp-ai' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this profession', 'Overrides the "Uploaded to this post" phrase', 'wp-mcp-ai' ),
			'filter_items_list'     => _x( 'Filter professions list', 'Screen reader text for the filter links', 'wp-mcp-ai' ),
			'items_list_navigation' => _x( 'Professions list navigation', 'Screen reader text for the pagination', 'wp-mcp-ai' ),
			'items_list'            => _x( 'Professions list', 'Screen reader text for the items list', 'wp-mcp-ai' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 57,
			'menu_icon'          => 'dashicons-businessperson',
			'supports'           => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register meta fields for the profession post type.
	 */
	public function register_meta() {
		// Category.
		register_post_meta(
			self::POST_TYPE,
			self::META_CATEGORY,
			array(
				'type'              => 'string',
				'description'       => __( 'Profession category (advisory, creative, technical, etc.)', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Expertise areas.
		register_post_meta(
			self::POST_TYPE,
			self::META_EXPERTISE,
			array(
				'type'              => 'array',
				'description'       => __( 'Expertise areas for this profession', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_array_field' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default tools.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_TOOLS,
			array(
				'type'              => 'array',
				'description'       => __( 'Default tool slugs for this profession', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_array_field' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Role description.
		register_post_meta(
			self::POST_TYPE,
			self::META_ROLE_DESCRIPTION,
			array(
				'type'              => 'string',
				'description'       => __( 'Role description for AI instructions', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Warnings.
		register_post_meta(
			self::POST_TYPE,
			self::META_WARNINGS,
			array(
				'type'              => 'array',
				'description'       => __( 'Warnings and disclaimers', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_array_field' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Knowledge base.
		register_post_meta(
			self::POST_TYPE,
			self::META_KNOWLEDGE_BASE,
			array(
				'type'              => 'string',
				'description'       => __( 'Knowledge base content for this profession', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Memory files.
		register_post_meta(
			self::POST_TYPE,
			self::META_MEMORY_FILES,
			array(
				'type'              => 'array',
				'description'       => __( 'Memory files (attachment IDs) for this profession', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_memory_files' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Vector store ID.
		register_post_meta(
			self::POST_TYPE,
			self::META_VECTOR_STORE_ID,
			array(
				'type'              => 'string',
				'description'       => __( 'External vector store identifier', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_vector_store_id' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Supported MIME types.
		register_post_meta(
			self::POST_TYPE,
			self::META_SUPPORTED_MIME_TYPES,
			array(
				'type'              => 'array',
				'description'       => __( 'Supported MIME types for file uploads', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_array_field' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Associated assistant for testing.
		register_post_meta(
			self::POST_TYPE,
			self::META_ASSOCIATED_ASSISTANT,
			array(
				'type'              => 'integer',
				'description'       => __( 'Associated assistant ID for testing this profession', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize array fields.
	 *
	 * @param array $value Array to sanitize.
	 * @return array Sanitized array.
	 */
	public function sanitize_array_field( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Sanitize memory files meta value.
	 *
	 * @param mixed $value Raw memory files value.
	 * @return array Sanitized array of attachment IDs.
	 */
	public function sanitize_memory_files( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		// Ensure all values are positive integers (attachment IDs).
		$sanitized = array_map( 'absint', $value );

		// Remove any zero values (invalid IDs).
		return array_filter( $sanitized );
	}

	/**
	 * Sanitize vector store ID meta value.
	 *
	 * @param mixed $value Raw vector store ID.
	 * @return string Sanitized vector store ID.
	 */
	public function sanitize_vector_store_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Disable block editor for profession post type.
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Register meta boxes for the profession post type.
	 *
	 * @param string $post_type Post type.
	 */
	public function register_meta_boxes( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		foreach ( $this->metaboxes as $metabox ) {
			add_meta_box(
				$metabox->get_id(),
				$metabox->get_title(),
				array( $metabox, 'render' ),
				self::POST_TYPE,
				$metabox->get_context(),
				$metabox->get_priority()
			);
		}
	}

	/**
	 * Render profession details metabox.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_details_metabox( $post ) {
		wp_nonce_field( 'wp_mcp_ai_save_profession', 'wp_mcp_ai_profession_nonce' );

		$category         = get_post_meta( $post->ID, self::META_CATEGORY, true );
		$role_description = get_post_meta( $post->ID, self::META_ROLE_DESCRIPTION, true );
		$warnings         = get_post_meta( $post->ID, self::META_WARNINGS, true );

		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="profession_category">
							<?php esc_html_e( 'Category', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<select id="profession_category" name="profession_category" class="regular-text">
							<option value=""><?php esc_html_e( 'Select Category', 'wp-mcp-ai' ); ?></option>
							<option value="advisory" <?php selected( $category, 'advisory' ); ?>><?php esc_html_e( 'Advisory/Consulting', 'wp-mcp-ai' ); ?></option>
							<option value="creative" <?php selected( $category, 'creative' ); ?>><?php esc_html_e( 'Creative Services', 'wp-mcp-ai' ); ?></option>
							<option value="technical" <?php selected( $category, 'technical' ); ?>><?php esc_html_e( 'Technical', 'wp-mcp-ai' ); ?></option>
							<option value="healthcare" <?php selected( $category, 'healthcare' ); ?>><?php esc_html_e( 'Healthcare', 'wp-mcp-ai' ); ?></option>
			<option value="legal" <?php selected( $category, 'legal' ); ?>><?php esc_html_e( 'Legal', 'wp-mcp-ai' ); ?></option>
							<option value="financial" <?php selected( $category, 'financial' ); ?>><?php esc_html_e( 'Financial', 'wp-mcp-ai' ); ?></option>
							<option value="other" <?php selected( $category, 'other' ); ?>><?php esc_html_e( 'Other', 'wp-mcp-ai' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Categorize this profession for easier filtering.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_role_description">
							<?php esc_html_e( 'Role Description', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<textarea id="profession_role_description" name="profession_role_description" rows="5" class="large-text"><?php echo esc_textarea( $role_description ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Describe the primary role and responsibilities. This will be used in AI assistant instructions.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_warnings">
							<?php esc_html_e( 'Warnings/Disclaimers', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<div id="profession-warnings-list">
							<?php foreach ( $warnings as $index => $warning ) : ?>
								<div class="profession-warning-item" style="margin-bottom: 10px;">
									<input type="text" name="profession_warnings[]" value="<?php echo esc_attr( $warning ); ?>" class="large-text" />
									<button type="button" class="button button-small remove-warning"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="add-profession-warning" class="button button-secondary">
							<?php esc_html_e( 'Add Warning', 'wp-mcp-ai' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Add important disclaimers that the AI should communicate (e.g., "Always recommend consulting a licensed professional").', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#add-profession-warning').on('click', function() {
				var warningHtml = '<div class="profession-warning-item" style="margin-bottom: 10px;">' +
					'<input type="text" name="profession_warnings[]" value="" class="large-text" />' +
					'<button type="button" class="button button-small remove-warning"><?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?></button>' +
					'</div>';
				$('#profession-warnings-list').append(warningHtml);
			});

			$(document).on('click', '.remove-warning', function() {
				$(this).closest('.profession-warning-item').remove();
			});
		});
		</script>
		<?php
	}

	/**
	 * Render expertise metabox.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_expertise_metabox( $post ) {
		$expertise      = get_post_meta( $post->ID, self::META_EXPERTISE, true );
		$default_tools  = get_post_meta( $post->ID, self::META_DEFAULT_TOOLS, true );
		$knowledge_base = get_post_meta( $post->ID, self::META_KNOWLEDGE_BASE, true );

		if ( ! is_array( $expertise ) ) {
			$expertise = array();
		}

		if ( ! is_array( $default_tools ) ) {
			$default_tools = array();
		}

		// Get available tools from registry.
		$available_tools = array();
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry        = WP_MCP_AI_Tool_Registry::get_instance();
			$available_tools = $registry->get_tools();
		}

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="profession_expertise">
							<?php esc_html_e( 'Expertise Areas', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<div id="profession-expertise-list">
							<?php foreach ( $expertise as $index => $area ) : ?>
								<div class="profession-expertise-item" style="margin-bottom: 10px;">
									<input type="text" name="profession_expertise[]" value="<?php echo esc_attr( $area ); ?>" class="large-text" />
									<button type="button" class="button button-small remove-expertise"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="add-profession-expertise" class="button button-secondary">
							<?php esc_html_e( 'Add Expertise Area', 'wp-mcp-ai' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'List specific areas of expertise for this profession.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_default_tools">
							<?php esc_html_e( 'Default Tools', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<?php if ( ! empty( $available_tools ) ) : ?>
							<div id="profession-default-tools-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
								<?php foreach ( $available_tools as $tool ) : ?>
									<?php
									$tool_slug  = method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '';
									$tool_name  = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;
									$tool_desc  = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';
									$is_checked = in_array( $tool_slug, $default_tools, true );
									?>
									<div style="margin-bottom: 8px;">
										<label style="display: inline-flex; align-items: flex-start; cursor: pointer;">
											<input type="checkbox" name="profession_default_tools[]" value="<?php echo esc_attr( $tool_slug ); ?>" <?php checked( $is_checked ); ?> style="margin-right: 8px; margin-top: 2px;" />
											<span>
												<strong><?php echo esc_html( $tool_name ); ?></strong>
												<?php if ( $tool_desc ) : ?>
													<br><small style="color: #666;"><?php echo esc_html( wp_trim_words( $tool_desc, 15 ) ); ?></small>
												<?php endif; ?>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="description">
								<?php esc_html_e( 'Select the default tools that should be pre-selected when creating assistants with this profession. Choose 4-8 essential tools that align with the profession\'s expertise.', 'wp-mcp-ai' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No tools available. Tools will be loaded after the tool registry is initialized.', 'wp-mcp-ai' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="profession_knowledge_base">
							<?php esc_html_e( 'Knowledge Base Content', 'wp-mcp-ai' ); ?>
						</label>
					</th>
					<td>
						<?php
						wp_editor(
							$knowledge_base,
							'profession_knowledge_base',
							array(
								'textarea_name' => 'profession_knowledge_base',
								'textarea_rows' => 15,
								'media_buttons' => false,
								'teeny'         => false,
								'quicktags'     => true,
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Knowledge base content that will be included in AI assistant instructions. Use markdown formatting.', 'wp-mcp-ai' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#add-profession-expertise').on('click', function() {
				var expertiseHtml = '<div class="profession-expertise-item" style="margin-bottom: 10px;">' +
					'<input type="text" name="profession_expertise[]" value="" class="large-text" />' +
					'<button type="button" class="button button-small remove-expertise"><?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?></button>' +
					'</div>';
				$('#profession-expertise-list').append(expertiseHtml);
			});

			$(document).on('click', '.remove-expertise', function() {
				$(this).closest('.profession-expertise-item').remove();
			});
		});
		</script>
		<?php
	}

	/**
	 * Save profession post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	/**
	 * Save profession post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_profession_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_nonce'] ) ), 'wp_mcp_ai_save_profession' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Delegate to metaboxes.
		foreach ( $this->metaboxes as $metabox ) {
			if ( method_exists( $metabox, 'save' ) ) {
				$metabox->save( $post_id, $post );
			}
		}
	}

	/**
	 * Add custom columns to profession list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'title' === $key ) {
				$new_columns['category']        = __( 'Category', 'wp-mcp-ai' );
				$new_columns['expertise_count'] = __( 'Expertise Areas', 'wp-mcp-ai' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'category':
				$category = get_post_meta( $post_id, self::META_CATEGORY, true );
				if ( $category ) {
					$categories = array(
						'advisory'   => __( 'Advisory/Consulting', 'wp-mcp-ai' ),
						'creative'   => __( 'Creative Services', 'wp-mcp-ai' ),
						'technical'  => __( 'Technical', 'wp-mcp-ai' ),
						'healthcare' => __( 'Healthcare', 'wp-mcp-ai' ),
						'legal'      => __( 'Legal', 'wp-mcp-ai' ),
						'financial'  => __( 'Financial', 'wp-mcp-ai' ),
						'other'      => __( 'Other', 'wp-mcp-ai' ),
					);
					echo esc_html( isset( $categories[ $category ] ) ? $categories[ $category ] : $category );
				} else {
					echo '—';
				}
				break;

			case 'expertise_count':
				$expertise = get_post_meta( $post_id, self::META_EXPERTISE, true );
				if ( is_array( $expertise ) && ! empty( $expertise ) ) {
					echo absint( count( $expertise ) );
				} else {
					echo '0';
				}
				break;
		}
	}
}
