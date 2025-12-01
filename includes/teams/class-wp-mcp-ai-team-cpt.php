<?php
/**
 * Team Custom Post Type.
 *
 * Handles registration and management of the team CPT.
 * Teams group multiple professionals together for deployment as a set of assistants.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the team custom post type and manages its WordPress integration.
 */
class WP_MCP_AI_Team_CPT {
	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mcp_ai_team';

	/**
	 * Meta key for team members (array of profession post IDs).
	 */
	const META_TEAM_MEMBERS = '_wp_mcp_ai_team_members';

	/**
	 * Meta key for team description.
	 */
	const META_TEAM_DESCRIPTION = '_wp_mcp_ai_team_description';

	/**
	 * Meta key for default provider for all team members.
	 */
	const META_DEFAULT_PROVIDER = '_wp_mcp_ai_team_default_provider';

	/**
	 * Meta key for default model for all team members.
	 */
	const META_DEFAULT_MODEL = '_wp_mcp_ai_team_default_model';

	/**
	 * Meta key for default temperature for all team members.
	 */
	const META_DEFAULT_TEMPERATURE = '_wp_mcp_ai_team_default_temperature';

	/**
	 * Constructor.
	 */
	public function __construct() {
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
	 * Register the team custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Teams', 'Post type general name', 'wp-mcp-ai' ),
			'singular_name'      => _x( 'Team', 'Post type singular name', 'wp-mcp-ai' ),
			'menu_name'          => _x( 'Teams', 'Admin Menu text', 'wp-mcp-ai' ),
			'name_admin_bar'     => _x( 'Team', 'Add New on Toolbar', 'wp-mcp-ai' ),
			'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
			'add_new_item'       => __( 'Add New Team', 'wp-mcp-ai' ),
			'new_item'           => __( 'New Team', 'wp-mcp-ai' ),
			'edit_item'          => __( 'Edit Team', 'wp-mcp-ai' ),
			'view_item'          => __( 'View Team', 'wp-mcp-ai' ),
			'all_items'          => __( 'Teams', 'wp-mcp-ai' ),
			'search_items'       => __( 'Search Teams', 'wp-mcp-ai' ),
			'not_found'          => __( 'No teams found.', 'wp-mcp-ai' ),
			'not_found_in_trash' => __( 'No teams found in Trash.', 'wp-mcp-ai' ),
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
			'menu_position'      => 58,
			'menu_icon'          => 'dashicons-groups',
			'supports'           => array( 'title', 'editor' ),
			'show_in_rest'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register meta fields for the team post type.
	 */
	public function register_meta() {
		// Team members.
		register_post_meta(
			self::POST_TYPE,
			self::META_TEAM_MEMBERS,
			array(
				'type'              => 'array',
				'description'       => __( 'Team member profession IDs', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_team_members' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Team description.
		register_post_meta(
			self::POST_TYPE,
			self::META_TEAM_DESCRIPTION,
			array(
				'type'              => 'string',
				'description'       => __( 'Team description', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default provider.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_PROVIDER,
			array(
				'type'              => 'string',
				'description'       => __( 'Default AI provider for team members', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default model.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_MODEL,
			array(
				'type'              => 'string',
				'description'       => __( 'Default model for team members', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);

		// Default temperature.
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFAULT_TEMPERATURE,
			array(
				'type'              => 'number',
				'description'       => __( 'Default temperature for team members', 'wp-mcp-ai' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_temperature' ),
				'auth_callback'     => '__return_true',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize team members meta field.
	 *
	 * @param mixed $members Raw members value.
	 * @return array
	 */
	public function sanitize_team_members( $members ) {
		if ( ! is_array( $members ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $members as $member_id ) {
			$member_id = absint( $member_id );
			if ( $member_id && 'mcp_ai_profession' === get_post_type( $member_id ) ) {
				$sanitized[] = $member_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize temperature meta field.
	 *
	 * @param mixed $temperature Raw temperature value.
	 * @return float|null
	 */
	public function sanitize_temperature( $temperature ) {
		if ( is_string( $temperature ) ) {
			$temperature = trim( $temperature );
		}

		if ( '' === $temperature || null === $temperature ) {
			return null;
		}

		if ( is_numeric( $temperature ) ) {
			$temperature = floatval( $temperature );
			if ( $temperature < 0 || $temperature > 2 ) {
				return null;
			}
			return $temperature;
		}

		return null;
	}

	/**
	 * Disable the block editor for the team post type.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type        Current post type being edited.
	 * @return bool
	 */
	public function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Register meta boxes for the team CPT.
	 */
	public function register_meta_boxes() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		add_meta_box(
			'wp-mcp-ai-team-members',
			__( 'Team Members', 'wp-mcp-ai' ),
			array( $this, 'render_team_members_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wp-mcp-ai-team-defaults',
			__( 'Default Settings', 'wp-mcp-ai' ),
			array( $this, 'render_defaults_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the team members meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_team_members_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_team_members_meta', 'wp_mcp_ai_team_members_meta_nonce' );

		$team_members = get_post_meta( $post->ID, self::META_TEAM_MEMBERS, true );
		if ( ! is_array( $team_members ) ) {
			$team_members = array();
		}

		// Get all available professions.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wp-mcp-ai-team-members">
			<p class="description">
				<?php esc_html_e( 'Select the professionals that make up this team. When you deploy this team, an assistant will be created for each selected professional.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<p class="notice notice-warning inline">
					<?php
					printf(
						/* translators: %s: URL to create profession */
						esc_html__( 'No professions found. Please %s first.', 'wp-mcp-ai' ),
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a profession', 'wp-mcp-ai' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<div class="wp-mcp-ai-team-members-list" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
					<?php foreach ( $professions as $profession ) : ?>
						<label style="display: block; padding: 5px 0;">
							<input
								type="checkbox"
								name="wp_mcp_ai_team_members[]"
								value="<?php echo esc_attr( $profession->ID ); ?>"
								<?php checked( in_array( (int) $profession->ID, $team_members, true ) ); ?>
							/>
							<strong><?php echo esc_html( $profession->post_title ); ?></strong>
							<?php if ( $profession->post_excerpt ) : ?>
								<span class="description"> - <?php echo esc_html( wp_trim_words( $profession->post_excerpt, 15 ) ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description" style="margin-top: 10px;">
					<?php
					printf(
						/* translators: %d: number of selected members */
						esc_html( _n( '%d professional selected', '%d professionals selected', count( $team_members ), 'wp-mcp-ai' ) ),
						absint( count( $team_members ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the default settings meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_defaults_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_team_defaults_meta', 'wp_mcp_ai_team_defaults_meta_nonce' );

		$default_provider    = get_post_meta( $post->ID, self::META_DEFAULT_PROVIDER, true );
		$default_model       = get_post_meta( $post->ID, self::META_DEFAULT_MODEL, true );
		$default_temperature = get_post_meta( $post->ID, self::META_DEFAULT_TEMPERATURE, true );

		?>
		<div class="wp-mcp-ai-team-defaults">
			<p class="description">
				<?php esc_html_e( 'These settings will be applied to all assistants created from this team.', 'wp-mcp-ai' ); ?>
			</p>

			<p>
				<label for="wp-mcp-ai-default-provider">
					<strong><?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select name="wp_mcp_ai_default_provider" id="wp-mcp-ai-default-provider" class="widefat">
					<option value=""><?php esc_html_e( '-- Use Professional Default --', 'wp-mcp-ai' ); ?></option>
					<option value="openai" <?php selected( $default_provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'wp-mcp-ai' ); ?></option>
					<option value="gemini" <?php selected( $default_provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
					<option value="anthropic" <?php selected( $default_provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
					<option value="ollama" <?php selected( $default_provider, 'ollama' ); ?>><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
					<option value="lm_studio" <?php selected( $default_provider, 'lm_studio' ); ?>><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label for="wp-mcp-ai-default-model">
					<strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input type="text" name="wp_mcp_ai_default_model" id="wp-mcp-ai-default-model" class="widefat" value="<?php echo esc_attr( $default_model ); ?>" placeholder="<?php esc_attr_e( 'e.g., gpt-4, gemini-pro', 'wp-mcp-ai' ); ?>">
				<span class="description"><?php esc_html_e( 'Leave empty to use professional default', 'wp-mcp-ai' ); ?></span>
			</p>

			<p>
				<label for="wp-mcp-ai-default-temperature">
					<strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input type="number" name="wp_mcp_ai_default_temperature" id="wp-mcp-ai-default-temperature" class="widefat" value="<?php echo esc_attr( $default_temperature ); ?>" min="0" max="2" step="0.1" placeholder="0.7">
				<span class="description"><?php esc_html_e( '0-2. Leave empty to use professional default', 'wp-mcp-ai' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save team members.
		if ( isset( $_POST['wp_mcp_ai_team_members_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_members_meta_nonce'] ) ), 'wp_mcp_ai_team_members_meta' ) ) {
			$team_members = array();
			if ( isset( $_POST['wp_mcp_ai_team_members'] ) && is_array( $_POST['wp_mcp_ai_team_members'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_team_members().
				$team_members = $this->sanitize_team_members( wp_unslash( $_POST['wp_mcp_ai_team_members'] ) );
			}
			update_post_meta( $post_id, self::META_TEAM_MEMBERS, $team_members );
		}

		// Save default settings.
		if ( isset( $_POST['wp_mcp_ai_team_defaults_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_defaults_meta_nonce'] ) ), 'wp_mcp_ai_team_defaults_meta' ) ) {
			$default_provider = isset( $_POST['wp_mcp_ai_default_provider'] ) ? sanitize_key( wp_unslash( $_POST['wp_mcp_ai_default_provider'] ) ) : '';
			if ( '' === $default_provider ) {
				delete_post_meta( $post_id, self::META_DEFAULT_PROVIDER );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_PROVIDER, $default_provider );
			}

			$default_model = isset( $_POST['wp_mcp_ai_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_default_model'] ) ) : '';
			if ( '' === $default_model ) {
				delete_post_meta( $post_id, self::META_DEFAULT_MODEL );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_MODEL, $default_model );
			}

			$default_temperature = null;
			if ( isset( $_POST['wp_mcp_ai_default_temperature'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_temperature().
				$default_temperature = $this->sanitize_temperature( wp_unslash( $_POST['wp_mcp_ai_default_temperature'] ) );
			}

			if ( null === $default_temperature ) {
				delete_post_meta( $post_id, self::META_DEFAULT_TEMPERATURE );
			} else {
				update_post_meta( $post_id, self::META_DEFAULT_TEMPERATURE, $default_temperature );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['team_members'] = __( 'Team Members', 'wp-mcp-ai' );
				$new_columns['provider']     = __( 'Provider', 'wp-mcp-ai' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		if ( 'team_members' === $column ) {
			$team_members = get_post_meta( $post_id, self::META_TEAM_MEMBERS, true );
			if ( is_array( $team_members ) && ! empty( $team_members ) ) {
				echo esc_html( count( $team_members ) ) . ' ' . esc_html( _n( 'professional', 'professionals', count( $team_members ), 'wp-mcp-ai' ) );
			} else {
				echo '<span class="description">' . esc_html__( 'No members', 'wp-mcp-ai' ) . '</span>';
			}
		} elseif ( 'provider' === $column ) {
			$provider = get_post_meta( $post_id, self::META_DEFAULT_PROVIDER, true );
			if ( $provider ) {
				$provider_labels = array(
					'openai'    => 'OpenAI',
					'gemini'    => 'Gemini',
					'anthropic' => 'Claude',
					'ollama'    => 'Ollama',
					'lm_studio' => 'LM Studio',
				);
				echo esc_html( isset( $provider_labels[ $provider ] ) ? $provider_labels[ $provider ] : $provider );
			} else {
				echo '<span class="description">' . esc_html__( 'Default', 'wp-mcp-ai' ) . '</span>';
			}
		}
	}
}
