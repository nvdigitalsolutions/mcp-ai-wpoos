<?php
/**
 * Admin settings for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles registration and rendering of the plugin's settings page.
 */
class WP_MCP_AI_Admin_Settings {
	const OPTION_NAME    = 'wp_mcp_ai_settings';
	const SETTINGS_GROUP = 'wp_mcp_ai_settings_group';
	const PAGE_SLUG      = 'wp-mcp-ai-settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Returns the option defaults.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'openai_api_key'       => '',
			'gemini_api_key'       => '',
			'default_assistant'    => 0,
			'enable_logging'       => false,
			'default_model'        => 'gpt-4o-mini',
			'default_gemini_model' => 'gemini-1.5-flash',
			'default_provider'     => 'openai',
			'request_timeout'      => 30,
			'auth0_domain'         => '',
			'auth0_audience'       => '',
			'auth0_required_scope' => '',
			'delete_on_uninstall'  => false,
			'crawl4ai_base_url'    => '',
			'crawl4ai_api_key'     => '',
		);
	}

	/**
	 * Retrieve the merged settings array.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::get_default_settings() );
	}

	/**
	 * Determine whether debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		$settings = self::get_settings();

		return ! empty( $settings['enable_logging'] );
	}

	/**
	 * Write a message to the PHP error log when logging is enabled.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Additional context to encode with the message.
	 */
	public static function log( $message, $context = array() ) {
		WP_MCP_AI_Logger::log_event( 'debug', (string) $message, $context );
	}

	/**
	 * Register the settings page within the WordPress admin.
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'WP OOS', 'wp-mcp-ai' ),
			__( 'WP OOS', 'wp-mcp-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings, sections, and fields exposed in the admin UI.
	 */
	public function register_settings() {
		register_setting( self::SETTINGS_GROUP, self::OPTION_NAME, array( $this, 'sanitize_settings' ) );

		add_settings_section(
			'wp_mcp_ai_openai_section',
			__( 'OpenAI Configuration', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'wp-mcp-ai' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_openai_section'
		);

		add_settings_field(
			'default_model',
			__( 'Default OpenAI Model', 'wp-mcp-ai' ),
			array( $this, 'render_default_model_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_openai_section'
		);

		add_settings_field(
			'request_timeout',
			__( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
			array( $this, 'render_timeout_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_openai_section'
		);

		add_settings_section(
			'wp_mcp_ai_gemini_section',
			__( 'Gemini Configuration', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'gemini_api_key',
			__( 'Gemini API Key', 'wp-mcp-ai' ),
			array( $this, 'render_gemini_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gemini_section'
		);

		add_settings_field(
			'default_gemini_model',
			__( 'Default Gemini Model', 'wp-mcp-ai' ),
			array( $this, 'render_default_gemini_model_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gemini_section'
		);

		add_settings_section(
			'wp_mcp_ai_authentication_section',
			__( 'Authentication', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'auth0_domain',
			__( 'Auth0 Domain', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_domain_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_field(
			'auth0_audience',
			__( 'Auth0 API Audience', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_audience_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_field(
			'auth0_required_scope',
			__( 'Required Access Scope', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_scope_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_section(
			'wp_mcp_ai_assistant_section',
			__( 'Assistant Defaults', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'default_provider',
			__( 'Default Provider', 'wp-mcp-ai' ),
			array( $this, 'render_default_provider_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_assistant_section'
		);

		add_settings_field(
			'default_assistant',
			__( 'Default Assistant', 'wp-mcp-ai' ),
			array( $this, 'render_default_assistant_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_assistant_section'
		);

		add_settings_field(
			'enable_logging',
			__( 'Enable Logging', 'wp-mcp-ai' ),
			array( $this, 'render_logging_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_assistant_section'
		);

		add_settings_section(
			'wp_mcp_ai_crawl4ai_section',
			__( 'Crawl4AI Integration', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'crawl4ai_base_url',
			__( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
			array( $this, 'render_crawl4ai_base_url_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_crawl4ai_section'
		);

		add_settings_field(
			'crawl4ai_api_key',
			__( 'Crawl4AI API Key', 'wp-mcp-ai' ),
			array( $this, 'render_crawl4ai_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_crawl4ai_section'
		);

		add_settings_section(
			'wp_mcp_ai_maintenance_section',
			__( 'Maintenance', 'wp-mcp-ai' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'delete_on_uninstall',
			__( 'Remove Data on Uninstall', 'wp-mcp-ai' ),
			array( $this, 'render_delete_on_uninstall_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_maintenance_section'
		);
	}

	/**
	 * Sanitize the submitted settings array.
	 *
	 * @param array $settings Submitted values.
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		$clean = self::get_default_settings();

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( isset( $settings['openai_api_key'] ) ) {
			$clean['openai_api_key'] = trim( sanitize_text_field( $settings['openai_api_key'] ) );
		}

		if ( isset( $settings['gemini_api_key'] ) ) {
			$clean['gemini_api_key'] = trim( sanitize_text_field( $settings['gemini_api_key'] ) );
		}

		if ( isset( $settings['default_assistant'] ) ) {
			$clean['default_assistant'] = absint( $settings['default_assistant'] );
		}

		$clean['enable_logging'] = ! empty( $settings['enable_logging'] );

		if ( isset( $settings['default_model'] ) ) {
			$clean['default_model'] = sanitize_text_field( $settings['default_model'] );
		}

		if ( isset( $settings['default_gemini_model'] ) ) {
			$clean['default_gemini_model'] = sanitize_text_field( $settings['default_gemini_model'] );
		}

		if ( isset( $settings['default_provider'] ) ) {
			$provider = sanitize_key( $settings['default_provider'] );
			$allowed  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

			if ( ! is_array( $allowed ) ) {
				$allowed = array( 'openai', 'gemini' );
			}

			if ( in_array( $provider, $allowed, true ) ) {
				$clean['default_provider'] = $provider;
			}
		}

		if ( isset( $settings['request_timeout'] ) ) {
			$timeout = absint( $settings['request_timeout'] );

			if ( $timeout > 0 ) {
				$clean['request_timeout'] = max( 5, $timeout );
			}
		}

		if ( isset( $settings['auth0_domain'] ) ) {
			$clean['auth0_domain'] = trim( sanitize_text_field( $settings['auth0_domain'] ) );
		}

		if ( isset( $settings['auth0_audience'] ) ) {
			$clean['auth0_audience'] = trim( sanitize_text_field( $settings['auth0_audience'] ) );
		}

		if ( isset( $settings['auth0_required_scope'] ) ) {
			$clean['auth0_required_scope'] = trim( sanitize_text_field( $settings['auth0_required_scope'] ) );
		}

		$clean['delete_on_uninstall'] = ! empty( $settings['delete_on_uninstall'] );

		if ( isset( $settings['crawl4ai_base_url'] ) ) {
			$base_url = trim( $settings['crawl4ai_base_url'] );

			$clean['crawl4ai_base_url'] = $base_url ? esc_url_raw( $base_url ) : '';
		}

		if ( isset( $settings['crawl4ai_api_key'] ) ) {
			$clean['crawl4ai_api_key'] = trim( sanitize_text_field( $settings['crawl4ai_api_key'] ) );
		}

		return $clean;
	}

	/**
	 * Render the settings page contents.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP OOS Settings', 'wp-mcp-ai' ); ?></h1>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Auth0 domain field.
	 */
	public function render_auth0_domain_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_domain]" value="<?php echo esc_attr( $settings['auth0_domain'] ); ?>" class="regular-text" placeholder="example.us.auth0.com" />
		<p class="description"><?php esc_html_e( 'The Auth0 tenant domain that issues access tokens for remote MCP assistants.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Auth0 audience field.
	 */
	public function render_auth0_audience_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_audience]" value="<?php echo esc_attr( $settings['auth0_audience'] ); ?>" class="regular-text" placeholder="https://api.example.com/" />
		<p class="description"><?php esc_html_e( 'Optional. When provided, bearer tokens must include this audience (or API Identifier) claim.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Auth0 scope field.
	 */
	public function render_auth0_scope_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_required_scope]" value="<?php echo esc_attr( $settings['auth0_required_scope'] ); ?>" class="regular-text" placeholder="mcp:invoke" />
		<p class="description"><?php esc_html_e( 'Optional space-delimited scope that must be present on remote bearer tokens.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the delete on uninstall checkbox.
	 */
	public function render_delete_on_uninstall_field() {
		$settings = self::get_settings();
		?>
		<label for="wp-mcp-ai-delete-on-uninstall">
			<input id="wp-mcp-ai-delete-on-uninstall" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?> />
			<?php esc_html_e( 'When uninstalling the plugin, remove assistants, settings, and other stored data.', 'wp-mcp-ai' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Leave unchecked to preserve plugin data for future installations.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the OpenAI API key field.
	 */
	public function render_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]" value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter the OpenAI secret key with access to the Chat Completions API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Gemini API key field.
	 */
	public function render_gemini_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]" value="<?php echo esc_attr( $settings['gemini_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter the Gemini API key with access to the Generative Language API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Crawl4AI base URL field.
	 */
	public function render_crawl4ai_base_url_field() {
		$settings = self::get_settings();
		?>
		<input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_base_url]" value="<?php echo esc_attr( $settings['crawl4ai_base_url'] ); ?>" class="regular-text" placeholder="https://example.com/" />
		<p class="description"><?php esc_html_e( 'Base URL for the Crawl4AI API (for example, https://localhost:11235/).', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Crawl4AI API key field.
	 */
	public function render_crawl4ai_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_api_key]" value="<?php echo esc_attr( $settings['crawl4ai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Optional bearer token that will be sent with Crawl4AI requests.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the default Gemini model field.
	 */
	public function render_default_gemini_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_gemini_model]" value="<?php echo esc_attr( $settings['default_gemini_model'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Render the default provider dropdown field.
	 */
	public function render_default_provider_field() {
		$settings = self::get_settings();
		$current  = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
		$choices  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

		if ( ! is_array( $choices ) ) {
			$choices = array( 'openai', 'gemini' );
		}
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_provider]" id="wp-mcp-ai-default-provider" class="regular-text">
			<?php
			foreach ( $choices as $choice ) {
				$choice = sanitize_key( $choice );
				if ( '' === $choice ) {
					continue;
				}

				$label = 'openai' === $choice ? __( 'OpenAI', 'wp-mcp-ai' ) : __( 'Gemini', 'wp-mcp-ai' );
				?>
				<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current, $choice ); ?>><?php echo esc_html( $label ); ?></option>
				<?php
			}
			?>
		</select>
		<p class="description"><?php esc_html_e( 'Select which provider new assistants should use when no override is set.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the default assistant dropdown field.
	 */
	public function render_default_assistant_field() {
		$settings   = self::get_settings();
		$assistants = $this->get_assistant_posts();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_assistant]" class="regular-text">
			<option value="0" <?php selected( 0, $settings['default_assistant'] ); ?>><?php esc_html_e( 'None', 'wp-mcp-ai' ); ?></option>
			<?php foreach ( $assistants as $assistant ) : ?>
				<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assistant->ID, $settings['default_assistant'] ); ?>><?php echo esc_html( $assistant->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'The assistant used by default in REST interactions when one is not provided explicitly.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render logging checkbox.
	 */
	public function render_logging_field() {
		$settings = self::get_settings();
		?>
		<label for="wp-mcp-ai-enable-logging">
			<input id="wp-mcp-ai-enable-logging" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_logging]" value="1" <?php checked( $settings['enable_logging'] ); ?> />
			<?php esc_html_e( 'Write OpenAI request and response details to the debug log.', 'wp-mcp-ai' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the default model field.
	 */
	public function render_default_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_model]" value="<?php echo esc_attr( $settings['default_model'] ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'The Chat Completions model to use when assistants do not specify one.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the timeout field.
	 */
	public function render_timeout_field() {
		$settings = self::get_settings();
		?>
		<input type="number" min="5" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[request_timeout]" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" class="small-text" />
		<p class="description"><?php esc_html_e( 'How long to wait for OpenAI responses before aborting the request.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Retrieve published assistant posts.
	 *
	 * @return WP_Post[]
	 */
	protected function get_assistant_posts() {
		$args = array(
			'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'all',
		);

		$posts = get_posts( $args );

		if ( ! $posts ) {
			return array();
		}

		return $posts;
	}
}
