<?php
/**
 * Assistant custom post type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the assistant custom post type and associated meta boxes.
 */
class WP_MCP_AI_Assistant_CPT {
	const POST_TYPE                 = 'mcp_ai_assistant';
	const META_TOOLS                = '_wp_mcp_ai_tools';
	const META_PROVIDER             = '_wp_mcp_ai_provider';
	const META_MODEL                = '_wp_mcp_ai_model';
	const META_TEMPERATURE          = '_wp_mcp_ai_temperature';
	const META_SYSTEM_PROMPT        = '_wp_mcp_ai_system_prompt';
	const META_MEMORY_FILES         = '_wp_mcp_ai_memory_files';
	const META_VECTOR_STORE_ID      = '_wp_mcp_ai_vector_store_id';
	const META_CREDENTIALS          = WP_MCP_AI_Credentials::META_KEY;
	const META_EXTERNAL_ACTION_ID   = '_wp_mcp_ai_external_action_id';
	const META_EXTERNAL_ACTION_TYPE = '_wp_mcp_ai_external_action_type';

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Track whether the credential action script has been printed.
	 *
	 * @var bool
	 */
	protected static $credential_action_script_printed = false;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry.
	 */
	public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
		$this->registry = $registry;

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_post_type' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_post_wp_mcp_ai_issue_credential', array( $this, 'handle_issue_credential' ) );
		add_action( 'admin_post_wp_mcp_ai_revoke_credential', array( $this, 'handle_revoke_credential' ) );
		add_action( 'admin_post_wp_mcp_ai_delete_credential', array( $this, 'handle_delete_credential' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'before_delete_post', array( $this, 'cleanup_deleted_assistant_credentials' ) );
	}

	/**
	 * Register the assistant custom post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => __( 'AI Assistants', 'wp-mcp-ai' ),
			'singular_name'      => __( 'AI Assistant', 'wp-mcp-ai' ),
			'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
			'add_new_item'       => __( 'Add New Assistant', 'wp-mcp-ai' ),
			'edit_item'          => __( 'Edit Assistant', 'wp-mcp-ai' ),
			'new_item'           => __( 'New Assistant', 'wp-mcp-ai' ),
			'view_item'          => __( 'View Assistant', 'wp-mcp-ai' ),
			'search_items'       => __( 'Search Assistants', 'wp-mcp-ai' ),
			'not_found'          => __( 'No assistants found', 'wp-mcp-ai' ),
			'not_found_in_trash' => __( 'No assistants found in Trash', 'wp-mcp-ai' ),
			'all_items'          => __( 'Assistants', 'wp-mcp-ai' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'rest_base'         => 'mcp-ai-assistants',
			'capability_type'   => 'post',
			'supports'          => array( 'title', 'editor' ),
			'menu_icon'         => 'dashicons-robot',
			'has_archive'       => false,
			'rewrite'           => false,
			'show_in_nav_menus' => false,
			'map_meta_cap'      => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Disable the block editor for the assistant post type so meta boxes save correctly.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type        Current post type being edited.
	 * @return bool
	 */
	public static function disable_block_editor_for_post_type( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Register assistant post meta for REST access and sanitization.
	 */
	public static function register_meta() {
		$auth_callback = array( __CLASS__, 'meta_auth_callback' );

		register_post_meta(
			self::POST_TYPE,
			self::META_TOOLS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_tools_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PROVIDER,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_provider_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_MODEL,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_model_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_TEMPERATURE,
			array(
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 2,
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_temperature_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SYSTEM_PROMPT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_system_prompt_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_MEMORY_FILES,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_memory_files_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_VECTOR_STORE_ID,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_vector_store_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_EXTERNAL_ACTION_ID,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_id_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_EXTERNAL_ACTION_TYPE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_type_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);
	}

	/**
	 * Meta capability check for assistant meta values.
	 *
	 * @param bool         $allowed Existing permission.
	 * @param string       $meta_key Meta key being modified.
	 * @param int          $post_id Post ID.
	 * @param int          $user_id User ID.
	 * @param string|array $cap Capability name(s).
	 * @param array        $caps Primitive caps.
	 * @return bool
	 */
	public static function meta_auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
		unset( $allowed, $meta_key, $user_id, $cap, $caps );

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Sanitize tools meta value.
	 *
	 * @param mixed $tools Raw tools value.
	 * @return array
	 */
	public static function sanitize_tools_meta( $tools ) {
		if ( ! is_array( $tools ) ) {
			return array();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$sanitized = array();

		foreach ( $tools as $tool_slug ) {
			$tool_slug = sanitize_key( $tool_slug );

			if ( '' === $tool_slug ) {
				continue;
			}

			if ( null === $registry->get_tool( $tool_slug ) ) {
				continue;
			}

			$sanitized[] = $tool_slug;
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize provider meta value.
	 *
	 * @param mixed $provider Raw provider value.
	 * @return string
	 */
	public static function sanitize_provider_meta( $provider ) {
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

		$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
		if ( ! is_array( $allowed_providers ) ) {
			$allowed_providers = array( 'openai', 'gemini' );
		}

		if ( ! in_array( $provider, $allowed_providers, true ) ) {
			return '';
		}

		return $provider;
	}

	/**
	 * Sanitize model meta value.
	 *
	 * @param mixed $model Raw model value.
	 * @return string
	 */
	public static function sanitize_model_meta( $model ) {
		if ( ! is_string( $model ) ) {
			return '';
		}

		return sanitize_text_field( $model );
	}

	/**
	 * Sanitize temperature meta value.
	 *
	 * @param mixed $temperature Raw temperature value.
	 * @return float|null
	 */
	public static function sanitize_temperature_meta( $temperature ) {
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
	 * Sanitize system prompt meta value.
	 *
	 * @param mixed $prompt Raw prompt value.
	 * @return string
	 */
	public static function sanitize_system_prompt_meta( $prompt ) {
		if ( ! is_string( $prompt ) ) {
			return '';
		}

		return wp_kses_post( $prompt );
	}

	/**
	 * Sanitize memory files meta value.
	 *
	 * @param mixed $memory_files Raw memory file IDs.
	 * @return array
	 */
	public static function sanitize_memory_files_meta( $memory_files ) {
		if ( ! is_array( $memory_files ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $memory_files as $file_id ) {
			$file_id = absint( $file_id );
			if ( $file_id && 'attachment' === get_post_type( $file_id ) ) {
				$sanitized[] = $file_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize vector store ID meta value.
	 *
	 * @param mixed $vector_store_id Raw vector store ID.
	 * @return string
	 */
	public static function sanitize_vector_store_meta( $vector_store_id ) {
		if ( ! is_string( $vector_store_id ) ) {
			return '';
		}

		return sanitize_text_field( $vector_store_id );
	}

	/**
	 * Sanitize the default external action identifier meta value.
	 *
	 * @param mixed $identifier Raw identifier value.
	 * @return string
	 */
	public static function sanitize_external_action_id_meta( $identifier ) {
		if ( ! is_string( $identifier ) ) {
			return '';
		}

		return sanitize_text_field( $identifier );
	}

	/**
	 * Sanitize the default external action type meta value.
	 *
	 * @param mixed $action_type Raw action type value.
	 * @return string
	 */
	public static function sanitize_external_action_type_meta( $action_type ) {
		$action_type = is_string( $action_type ) ? sanitize_key( $action_type ) : '';

		if ( ! in_array( $action_type, array( 'workflow', 'assistant' ), true ) ) {
			return '';
		}

		return $action_type;
	}

	/**
	 * Register meta boxes for the assistant CPT.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'wp-mcp-ai-tools',
			__( 'Available Tools', 'wp-mcp-ai' ),
			array( $this, 'render_tools_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wp-mcp-ai-defaults',
			__( 'Model Defaults', 'wp-mcp-ai' ),
			array( $this, 'render_defaults_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'wp-mcp-ai-base-knowledge',
			__( 'Base Knowledge', 'wp-mcp-ai' ),
			array( $this, 'render_base_knowledge_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wp-mcp-ai-credentials',
			__( 'API Credentials', 'wp-mcp-ai' ),
			array( $this, 'render_credentials_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the credentials meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_credentials_meta_box( $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage credentials.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		$credentials = WP_MCP_AI_Credentials::get_credentials( $post->ID );

		echo '<p>' . esc_html__( 'Issue tokens for remote integrations. Store the generated token securely; it will not be shown again.', 'wp-mcp-ai' ) . '</p>';

		if ( empty( $credentials ) ) {
			echo '<p>' . esc_html__( 'No credentials have been issued for this assistant.', 'wp-mcp-ai' ) . '</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Credential ID', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Created', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $credentials as $credential ) {
				$created_at   = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
				$status       = __( 'Active', 'wp-mcp-ai' );
				$action_links = array();

				if ( ! empty( $credential['revoked_at'] ) ) {
					$status = sprintf(
						/* translators: %s: revocation timestamp */
						__( 'Revoked %s', 'wp-mcp-ai' ),
						get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
					);
				} else {
					$action_links[] = $this->build_credential_action_button(
						$post->ID,
						$credential['id'],
						'wp_mcp_ai_revoke_credential',
						'wp_mcp_ai_revoke_credential_' . $post->ID . '_' . $credential['id'],
						'wp_mcp_ai_revoke_nonce',
						__( 'Revoke', 'wp-mcp-ai' ),
						__( 'Revoke this credential? This action cannot be undone.', 'wp-mcp-ai' )
					);
				}

				$action_links[] = $this->build_credential_action_button(
					$post->ID,
					$credential['id'],
					'wp_mcp_ai_delete_credential',
					'wp_mcp_ai_delete_credential_' . $post->ID . '_' . $credential['id'],
					'wp_mcp_ai_delete_nonce',
					__( 'Delete', 'wp-mcp-ai' ),
					__( 'Delete this credential? This action cannot be undone.', 'wp-mcp-ai' ),
					'button button-secondary delete'
				);

				$actions = empty( $action_links ) ? '&#8212;' : implode( ' ', $action_links );

				echo '<tr>';
				echo '<td><code>' . esc_html( $credential['id'] ) . '</code></td>';
				echo '<td>' . esc_html( $created_at ) . '</td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td>' . $actions . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}

		$issue_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wp_mcp_ai_issue_credential',
					'post_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_issue_credential_' . $post->ID,
			'wp_mcp_ai_issue_nonce'
		);

		printf(
			'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( $issue_url ),
			esc_html__( 'Generate Credential', 'wp-mcp-ai' )
		);

		$this->print_credential_action_script();
	}

	/**
	 * Build the markup for a credential action button.
	 *
	 * @param int    $post_id        Assistant post ID.
	 * @param string $credential_id  Credential identifier.
	 * @param string $action         Admin-post action hook name.
	 * @param string $nonce_action   Action name for nonce verification.
	 * @param string $nonce_name     Nonce field name.
	 * @param string $button_label   Button label.
	 * @param string $confirm_prompt Confirmation prompt shown before submit.
	 * @param string $button_class   CSS classes to apply to the button element.
	 *
	 * @return string
	 */
	protected function build_credential_action_button( $post_id, $credential_id, $action, $nonce_action, $nonce_name, $button_label, $confirm_prompt, $button_class = 'button button-secondary' ) {
		$classes    = trim( $button_class . ' wp-mcp-ai-credential-action' );
		$attributes = array(
			'type'               => 'button',
			'class'              => $classes,
			'data-action'        => $action,
			'data-post-id'       => $post_id,
			'data-credential-id' => $credential_id,
			'data-nonce-name'    => $nonce_name,
			'data-nonce-value'   => wp_create_nonce( $nonce_action ),
			'data-endpoint'      => admin_url( 'admin-post.php' ),
		);

		if ( $confirm_prompt ) {
			$attributes['data-confirm'] = $confirm_prompt;
		}

		$attribute_string = '';
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$escaped_value     = ( 'data-endpoint' === $name ) ? esc_url( $value ) : esc_attr( $value );
			$attribute_string .= sprintf( ' %s="%s"', esc_attr( $name ), $escaped_value );
		}

		return sprintf( '<button%1$s>%2$s</button>', $attribute_string, esc_html( $button_label ) );
	}

	/**
	 * Print the JavaScript required to submit credential action buttons as POST requests.
	 */
	protected function print_credential_action_script() {
		if ( self::$credential_action_script_printed ) {
			return;
		}

		self::$credential_action_script_printed = true;
		?>
		<script type="text/javascript">
		( function() {
			function submitCredentialAction( button ) {
				if ( ! button ) {
					return;
				}

				var confirmMessage = button.getAttribute( 'data-confirm' );
				if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
					return;
				}

				var endpoint = button.getAttribute( 'data-endpoint' );
				if ( ! endpoint ) {
					return;
				}

				var form = document.createElement( 'form' );
				form.method = 'post';
				form.action = endpoint;
				form.style.display = 'none';

				var fields = {
					action: button.getAttribute( 'data-action' ),
					post_id: button.getAttribute( 'data-post-id' ),
					credential_id: button.getAttribute( 'data-credential-id' )
				};

				var nonceName = button.getAttribute( 'data-nonce-name' );
				var nonceValue = button.getAttribute( 'data-nonce-value' );

				if ( nonceName && nonceValue ) {
					fields[ nonceName ] = nonceValue;
				}

				for ( var key in fields ) {
					if ( Object.prototype.hasOwnProperty.call( fields, key ) && fields[ key ] ) {
						var input = document.createElement( 'input' );
						input.type = 'hidden';
						input.name = key;
						input.value = fields[ key ];
						form.appendChild( input );
					}
				}

				document.body.appendChild( form );
				form.submit();
			}

			document.addEventListener( 'click', function( event ) {
				var target = event.target;
				if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-credential-action' ) ) {
					event.preventDefault();
					submitCredentialAction( target );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Generate a nonce field name unique to a credential.
	 *
	 * @param string $base_name     Base nonce field name.
	 * @param string $credential_id Credential identifier.
	 * @return string
	 */
	protected function get_credential_nonce_field_name( $base_name, $credential_id ) {
		$suffix = sanitize_key( $credential_id );

		if ( '' === $suffix ) {
			return $base_name;
		}

		return $base_name . '_' . $suffix;
	}

	/**
	 * Render the tools meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_tools_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_tools_meta', 'wp_mcp_ai_tools_meta_nonce' );

		$selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
		if ( ! is_array( $selected_tools ) ) {
			$selected_tools = array();
		}

		$tools = $this->registry->get_tools();

		$external_action_id   = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_ID, true );
		$external_action_id   = self::sanitize_external_action_id_meta( $external_action_id );
		$external_action_type = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_TYPE, true );
		$external_action_type = self::sanitize_external_action_type_meta( $external_action_type );

		if ( empty( $tools ) ) {
			echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Select the tools this assistant is permitted to invoke.', 'wp-mcp-ai' ) . '</p>';

		echo '<ul class="wp-mcp-ai-tools">';
		foreach ( $tools as $tool ) {
			$slug        = $tool->get_slug();
			$is_selected = in_array( $slug, $selected_tools, true );

			echo '<li>';
			echo '<label>';
			printf(
				'<input type="checkbox" name="wp_mcp_ai_tools[]" value="%1$s" %2$s /> <strong>%3$s</strong><br/><span class="description">%4$s</span>',
				esc_attr( $slug ),
				checked( $is_selected, true, false ),
				esc_html( $tool->get_name() ),
				esc_html( $tool->get_description() )
			);
			echo '</label>';

			if ( 'run_openai_external_action' === $slug ) {
				$identifier_field_id = 'wp-mcp-ai-external-action-id';
				$type_field_id       = 'wp-mcp-ai-external-action-type';
				?>
				<div class="wp-mcp-ai-tool-defaults">
					<p>
						<label for="<?php echo esc_attr( $identifier_field_id ); ?>">
							<strong><?php esc_html_e( 'Default workflow or assistant ID', 'wp-mcp-ai' ); ?></strong>
						</label>
						<input
							type="text"
							id="<?php echo esc_attr( $identifier_field_id ); ?>"
							name="wp_mcp_ai_external_action_identifier"
							value="<?php echo esc_attr( $external_action_id ); ?>"
							class="widefat"
						/>
					</p>
					<p>
						<label for="<?php echo esc_attr( $type_field_id ); ?>">
							<strong><?php esc_html_e( 'Default action type', 'wp-mcp-ai' ); ?></strong>
						</label>
						<select id="<?php echo esc_attr( $type_field_id ); ?>" name="wp_mcp_ai_external_action_type" class="widefat">
							<option value="">
								<?php esc_html_e( 'Use runtime choice', 'wp-mcp-ai' ); ?>
							</option>
							<option value="workflow" <?php selected( $external_action_type, 'workflow' ); ?>>
								<?php esc_html_e( 'Workflow', 'wp-mcp-ai' ); ?>
							</option>
							<option value="assistant" <?php selected( $external_action_type, 'assistant' ); ?>>
								<?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?>
							</option>
						</select>
					</p>
				</div>
				<?php
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render the defaults meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_defaults_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_defaults_meta', 'wp_mcp_ai_defaults_meta_nonce' );

		$provider      = get_post_meta( $post->ID, self::META_PROVIDER, true );
		$provider      = self::sanitize_provider_meta( $provider );
		$model         = get_post_meta( $post->ID, self::META_MODEL, true );
		$temperature   = get_post_meta( $post->ID, self::META_TEMPERATURE, true );
		$system_prompt = get_post_meta( $post->ID, self::META_SYSTEM_PROMPT, true );

		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$default_provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';

		if ( '' === $provider ) {
			$provider = $default_provider;
		}

		$provider_choices = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
		if ( ! is_array( $provider_choices ) ) {
			$provider_choices = array( 'openai', 'gemini' );
		}

		if ( '' === $temperature ) {
			$temperature = '';
		}

		?>
		<p>
			<label for="wp-mcp-ai-provider"><strong><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></strong></label>
			<select id="wp-mcp-ai-provider" name="wp_mcp_ai_provider" class="widefat">
				<?php
				foreach ( $provider_choices as $choice ) {
					$choice = sanitize_key( $choice );
					if ( '' === $choice ) {
						continue;
					}

					$label = 'openai' === $choice ? __( 'OpenAI', 'wp-mcp-ai' ) : __( 'Gemini', 'wp-mcp-ai' );
					?>
					<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $provider, $choice ); ?>><?php echo esc_html( $label ); ?></option>
					<?php
				}
				?>
			</select>
		</p>
		<p>
			<label for="wp-mcp-ai-model"><strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong></label>
			<input type="text" id="wp-mcp-ai-model" name="wp_mcp_ai_model" value="<?php echo esc_attr( $model ); ?>" class="widefat" />
		</p>
		<p>
			<label for="wp-mcp-ai-temperature"><strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong></label>
			<input type="number" step="0.1" min="0" max="2" id="wp-mcp-ai-temperature" name="wp_mcp_ai_temperature" value="<?php echo esc_attr( $temperature ); ?>" class="widefat" />
		</p>
		<p>
			<label for="wp-mcp-ai-system-prompt"><strong><?php esc_html_e( 'System Prompt', 'wp-mcp-ai' ); ?></strong></label>
			<textarea id="wp-mcp-ai-system-prompt" name="wp_mcp_ai_system_prompt" class="widefat" rows="5"><?php echo esc_textarea( $system_prompt ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Render the base knowledge meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_base_knowledge_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_base_knowledge_meta', 'wp_mcp_ai_base_knowledge_meta_nonce' );

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );

		$memory_files    = get_post_meta( $post->ID, self::META_MEMORY_FILES, true );
		$vector_store_id = get_post_meta( $post->ID, self::META_VECTOR_STORE_ID, true );

		if ( ! is_array( $memory_files ) ) {
			$memory_files = array();
		}

		if ( ! is_string( $vector_store_id ) ) {
			$vector_store_id = '';
		}

		?>
		<p><?php esc_html_e( 'Select Media Library items that should be preloaded as reference material for this assistant.', 'wp-mcp-ai' ); ?></p>
		<ul id="wp-mcp-ai-memory-files-list" class="wp-mcp-ai-memory-files">
			<?php
			foreach ( $memory_files as $file_id ) :
				$file_id    = absint( $file_id );
				$attachment = get_post( $file_id );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					continue;
				}
				$title = get_the_title( $attachment );
				?>
				<li data-id="<?php echo esc_attr( $file_id ); ?>">
					<span class="wp-mcp-ai-memory-file-title"><?php echo esc_html( $title ? $title : sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id ) ); ?></span>
					<button type="button" class="button-link wp-mcp-ai-remove-memory"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
					<input type="hidden" name="wp_mcp_ai_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<button type="button" class="button" id="wp-mcp-ai-memory-select">
				<?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
			</button>
		</p>
		<p>
			<label for="wp-mcp-ai-vector-store-id"><strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong></label>
			<input type="text" id="wp-mcp-ai-vector-store-id" name="wp_mcp_ai_vector_store_id" value="<?php echo esc_attr( $vector_store_id ); ?>" class="widefat" />
			<span class="description"><?php esc_html_e( 'Optional identifier for an external vector store that should be associated with this assistant.', 'wp-mcp-ai' ); ?></span>
		</p>
		<script type="text/javascript">
		jQuery( function( $ ) {
			var frame;
			var list = $( '#wp-mcp-ai-memory-files-list' );

			function addAttachment( attachment ) {
				var id = attachment.id || attachment.ID;
				if ( ! id ) {
					return;
				}

				if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
					return;
				}

				var title = attachment.title || attachment.filename || attachment.name || '<?php echo esc_js( __( 'Attachment', 'wp-mcp-ai' ) ); ?>';
				var label = title + ' (ID: ' + id + ')';

				var item = $( '<li />', { 'data-id': id } );
				item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-title', 'text': label } ) );
				item.append( $( '<button />', { 'type': 'button', 'class': 'button-link wp-mcp-ai-remove-memory', 'text': '<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>' } ) );
				item.append( $( '<input />', { 'type': 'hidden', 'name': 'wp_mcp_ai_memory_files[]', 'value': id } ) );

				list.append( item );
			}

			$( '#wp-mcp-ai-memory-select' ).on( 'click', function( event ) {
				event.preventDefault();

				if ( frame ) {
					frame.open();
					return;
				}

				frame = wp.media({
					title: '<?php echo esc_js( __( 'Select knowledge files', 'wp-mcp-ai' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Use files', 'wp-mcp-ai' ) ); ?>'
					},
					multiple: true
				});

				frame.on( 'select', function() {
					var selection = frame.state().get( 'selection' );
					if ( ! selection ) {
						return;
					}

					selection.each( function( attachment ) {
						addAttachment( attachment.toJSON() );
					} );
				});

				frame.open();
			} );

			list.on( 'click', '.wp-mcp-ai-remove-memory', function( event ) {
				event.preventDefault();
				$( this ).closest( 'li' ).remove();
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Handle credential issuance requests from the admin UI.
	 */
	public function handle_issue_credential() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'wp_mcp_ai_issue_credential_' . $post_id, 'wp_mcp_ai_issue_nonce' );

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
		}

		$user_id = get_current_user_id();
		$issued  = WP_MCP_AI_Credentials::issue_credential( $post_id, $user_id );

		if ( is_wp_error( $issued ) ) {
			$error_code = sanitize_key( $issued->get_error_code() );
			$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
		}

		set_transient(
			$this->get_token_transient_key( $user_id ),
			array(
				'assistant_id' => $post_id,
				'token'        => $issued['token'],
			),
			10 * MINUTE_IN_SECONDS
		);

		$this->redirect_with_notice( $post_id, 'credential_created' );
	}

	/**
	 * Handle credential revocation requests from the admin UI.
	 */
	public function handle_revoke_credential() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		$post_id       = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $post_id || '' === $credential_id ) {
			wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
		}

		$nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_revoke_nonce', $credential_id );

		check_admin_referer( 'wp_mcp_ai_revoke_credential_' . $post_id . '_' . $credential_id, $nonce_field );

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
		}

		$result = WP_MCP_AI_Credentials::revoke_credential( $post_id, $credential_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$error_code = sanitize_key( $result->get_error_code() );
			$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
		}

		$this->redirect_with_notice( $post_id, 'credential_revoked' );
	}

	/**
	 * Handle credential deletion requests from the admin UI.
	 */
	public function handle_delete_credential() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		$post_id       = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $post_id || '' === $credential_id ) {
			wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
		}

		$nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_delete_nonce', $credential_id );

		check_admin_referer( 'wp_mcp_ai_delete_credential_' . $post_id . '_' . $credential_id, $nonce_field );

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
		}

		$result = WP_MCP_AI_Credentials::delete_credential( $post_id, $credential_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$error_code = sanitize_key( $result->get_error_code() );
			$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
		}

		$this->redirect_with_notice( $post_id, 'credential_deleted' );
	}

	/**
	 * Display notices related to credential management.
	 */
	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}

		$user_id       = get_current_user_id();
		$transient_key = $this->get_token_transient_key( $user_id );
		$token_notice  = get_transient( $transient_key );

		if ( is_array( $token_notice ) && isset( $token_notice['assistant_id'], $token_notice['token'] ) && absint( $token_notice['assistant_id'] ) === $post_id ) {
			delete_transient( $transient_key );

			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				sprintf(
					/* translators: %s: credential token */
					esc_html__( 'New credential issued. Copy this token now: %s', 'wp-mcp-ai' ),
					'<code>' . esc_html( $token_notice['token'] ) . '</code>'
				)
			);
		}

		$notice = isset( $_GET['wp_mcp_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_mcp_ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message    = $this->get_notice_message( $notice, $error_code );

		if ( '' === $message ) {
			return;
		}

		$class = in_array( $notice, array( 'credential_created', 'credential_revoked', 'credential_deleted' ), true ) ? 'notice-success' : 'notice-error';

		printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Build the transient key used for temporary token storage.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected function get_token_transient_key( $user_id ) {
		return 'wp_mcp_ai_new_token_' . absint( $user_id );
	}

	/**
	 * Redirect back to the assistant edit screen with a notice.
	 *
	 * @param int    $post_id Assistant post ID.
	 * @param string $notice  Notice identifier.
	 * @param array  $extra   Additional query arguments.
	 */
	protected function redirect_with_notice( $post_id, $notice, $extra = array() ) {
		$args = array_merge(
			array(
				'post'             => absint( $post_id ),
				'action'           => 'edit',
				'wp_mcp_ai_notice' => sanitize_key( $notice ),
			),
			array_change_key_case( $extra, CASE_LOWER )
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
		exit;
	}

	/**
	 * Map notice identifiers to user-facing messages.
	 *
	 * @param string $notice     Notice identifier.
	 * @param string $error_code Optional error code.
	 * @return string
	 */
	protected function get_notice_message( $notice, $error_code = '' ) {
		switch ( $notice ) {
			case 'credential_created':
				return __( 'Credential issued successfully.', 'wp-mcp-ai' );
			case 'credential_revoked':
				return __( 'Credential revoked successfully.', 'wp-mcp-ai' );
			case 'credential_deleted':
				return __( 'Credential deleted successfully.', 'wp-mcp-ai' );
			case 'credential_error':
				switch ( $error_code ) {
					case 'wp_mcp_ai_unknown_credential':
						return __( 'The requested credential could not be found.', 'wp-mcp-ai' );
					case 'wp_mcp_ai_credential_already_revoked':
						return __( 'The credential has already been revoked.', 'wp-mcp-ai' );
					case 'wp_mcp_ai_invalid_assistant':
						return __( 'Unable to manage credentials for this assistant.', 'wp-mcp-ai' );
					default:
						return __( 'An error occurred while managing the credential.', 'wp-mcp-ai' );
				}
		}

		return '';
	}

	/**
	 * Remove credential index entries when an assistant is deleted.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function cleanup_deleted_assistant_credentials( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		WP_MCP_AI_Credentials::purge_assistant_credentials( $post_id );
	}

	/**
	 * Persist assistant meta fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$tools_nonce_verified          = false;
		$defaults_nonce_verified       = false;
		$base_knowledge_nonce_verified = false;

		if ( isset( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tools_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$defaults_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$base_knowledge_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ), 'wp_mcp_ai_base_knowledge_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( ! $tools_nonce_verified && ! $defaults_nonce_verified && ! $base_knowledge_nonce_verified ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( $tools_nonce_verified ) {
			$tool_slugs = array();
			if ( isset( $_POST['wp_mcp_ai_tools'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$tool_slugs = self::sanitize_tools_meta( wp_unslash( $_POST['wp_mcp_ai_tools'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}

			update_post_meta( $post_id, self::META_TOOLS, $tool_slugs );

			$external_action_id = isset( $_POST['wp_mcp_ai_external_action_identifier'] )
				? self::sanitize_external_action_id_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_identifier'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: '';

			if ( '' === $external_action_id ) {
				delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID );
			} else {
				update_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID, $external_action_id );
			}

			$external_action_type = isset( $_POST['wp_mcp_ai_external_action_type'] )
				? self::sanitize_external_action_type_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: '';

			if ( '' === $external_action_type ) {
				delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE );
			} else {
				update_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE, $external_action_type );
			}
		}

		if ( $defaults_nonce_verified ) {
			$provider = isset( $_POST['wp_mcp_ai_provider'] )
				? self::sanitize_provider_meta( wp_unslash( $_POST['wp_mcp_ai_provider'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: '';

			if ( '' === $provider ) {
				delete_post_meta( $post_id, self::META_PROVIDER );
			} else {
				update_post_meta( $post_id, self::META_PROVIDER, $provider );
			}

			$model = isset( $_POST['wp_mcp_ai_model'] ) ? self::sanitize_model_meta( wp_unslash( $_POST['wp_mcp_ai_model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, self::META_MODEL, $model );

			$temperature = null;
			if ( isset( $_POST['wp_mcp_ai_temperature'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$temperature = self::sanitize_temperature_meta( wp_unslash( $_POST['wp_mcp_ai_temperature'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}

			if ( null === $temperature ) {
				delete_post_meta( $post_id, self::META_TEMPERATURE );
			} else {
				update_post_meta( $post_id, self::META_TEMPERATURE, $temperature );
			}

			$system_prompt = isset( $_POST['wp_mcp_ai_system_prompt'] ) ? self::sanitize_system_prompt_meta( wp_unslash( $_POST['wp_mcp_ai_system_prompt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $system_prompt );
		}

		if ( $base_knowledge_nonce_verified ) {
			$memory_files = array();
			if ( isset( $_POST['wp_mcp_ai_memory_files'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$memory_files = self::sanitize_memory_files_meta( wp_unslash( $_POST['wp_mcp_ai_memory_files'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}

			update_post_meta( $post_id, self::META_MEMORY_FILES, $memory_files );

			$vector_store_id = isset( $_POST['wp_mcp_ai_vector_store_id'] ) ? self::sanitize_vector_store_meta( wp_unslash( $_POST['wp_mcp_ai_vector_store_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, self::META_VECTOR_STORE_ID, $vector_store_id );
		}
	}

	/**
	 * Retrieve the configuration for a specific assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	public static function get_assistant_configuration( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$config = array(
			'tools'                      => get_post_meta( $assistant_id, self::META_TOOLS, true ),
			'provider'                   => get_post_meta( $assistant_id, self::META_PROVIDER, true ),
			'model'                      => get_post_meta( $assistant_id, self::META_MODEL, true ),
			'temperature'                => get_post_meta( $assistant_id, self::META_TEMPERATURE, true ),
			'system_prompt'              => get_post_meta( $assistant_id, self::META_SYSTEM_PROMPT, true ),
			'memory_files'               => get_post_meta( $assistant_id, self::META_MEMORY_FILES, true ),
			'vector_store_id'            => get_post_meta( $assistant_id, self::META_VECTOR_STORE_ID, true ),
			'external_action_identifier' => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_ID, true ),
			'external_action_type'       => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_TYPE, true ),
		);

		if ( ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		if ( ! is_string( $config['provider'] ) ) {
			$config['provider'] = '';
		} else {
			$config['provider'] = self::sanitize_provider_meta( $config['provider'] );
		}

		if ( '' === $config['model'] ) {
			$config['model'] = '';
		}

		if ( '' === $config['temperature'] ) {
			$config['temperature'] = null;
		} else {
			$config['temperature'] = floatval( $config['temperature'] );
		}

		if ( '' === $config['system_prompt'] ) {
			$config['system_prompt'] = '';
		}

		if ( ! is_array( $config['memory_files'] ) ) {
			$config['memory_files'] = array();
		}

		$config['memory_files'] = array_values( array_filter( array_map( 'absint', $config['memory_files'] ) ) );

		if ( ! is_string( $config['vector_store_id'] ) ) {
			$config['vector_store_id'] = '';
		} else {
			$config['vector_store_id'] = sanitize_text_field( $config['vector_store_id'] );
		}

		if ( ! is_string( $config['external_action_identifier'] ) ) {
			$config['external_action_identifier'] = '';
		} else {
			$config['external_action_identifier'] = self::sanitize_external_action_id_meta( $config['external_action_identifier'] );
		}

		if ( ! is_string( $config['external_action_type'] ) ) {
			$config['external_action_type'] = '';
		} else {
			$config['external_action_type'] = self::sanitize_external_action_type_meta( $config['external_action_type'] );
		}

		return $config;
	}
}
