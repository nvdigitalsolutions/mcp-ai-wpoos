<?php
/**
 * NV oOS Algorave — AI Addon Core Class
 *
 * Registers the 9 algorave tools with the NV oOS base plugin's tool
 * registry, and adds the AI Music Generation settings section to the
 * standalone plugin's settings page.
 *
 * @package NV_oOS_Algorave_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Algorave AI addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_AI {

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_ai_settings' ) );
	}

	/**
	 * Whether the standalone NV oOS Algorave plugin is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_standalone_active() {
		return class_exists( 'NV_oOS_Algorave', false );
	}

	/**
	 * Whether the AI addon is fully ready.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return self::is_standalone_active()
			&& nvoos_algorave_ai_is_base_active()
			&& NV_oOS_Algorave::is_enabled();
	}

	/**
	 * Fired on plugins_loaded — wire tool registration when the base plugin
	 * is present.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! self::is_standalone_active() || ! nvoos_algorave_ai_is_base_active() ) {
			return;
		}

		// Register algorave tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );

		// Also support the Pro-style lazy loading hook.
		add_action( 'wp_mcp_ai_load_pro_tools', array( __CLASS__, 'load_tools' ) );
	}

	/**
	 * Register algorave tools with the oOS tool registry.
	 *
	 * @since 1.0.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! NV_oOS_Algorave::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Load tools via the Pro-style lazy loading hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_tools() {
		if ( ! NV_oOS_Algorave::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry ) {
			return;
		}

		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * List of algorave tool classes, in registration order.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function get_tool_classes() {
		return array(
			'NV_oOS_Algorave_Tool_Generate_Pattern',
			'NV_oOS_Algorave_Tool_Modify_Pattern',
			'NV_oOS_Algorave_Tool_Play_Control',
			'NV_oOS_Algorave_Tool_Export_MIDI',
			'NV_oOS_Algorave_Tool_Sample_Manager',
			'NV_oOS_Algorave_Tool_Generate_Music_AI',
			'NV_oOS_Algorave_Tool_Visualizer',
			'NV_oOS_Algorave_Tool_Strudel_Reference',
			'NV_oOS_Algorave_Tool_MIDI_Output',
		);
	}

	/**
	 * Require all tool class files.
	 *
	 * Only called after the NV oOS base plugin has been confirmed present,
	 * because the tool classes implement its `WP_MCP_AI_Tool_Interface`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$dir = NVOOS_ALGORAVE_AI_PATH . 'includes/tools/';

		require_once $dir . 'class-nvoos-algorave-tool-generate-pattern.php';
		require_once $dir . 'class-nvoos-algorave-tool-modify-pattern.php';
		require_once $dir . 'class-nvoos-algorave-tool-play-control.php';
		require_once $dir . 'class-nvoos-algorave-tool-export-midi.php';
		require_once $dir . 'class-nvoos-algorave-tool-sample-manager.php';
		require_once $dir . 'class-nvoos-algorave-tool-generate-music-ai.php';
		require_once $dir . 'class-nvoos-algorave-tool-visualizer.php';
		require_once $dir . 'class-nvoos-algorave-tool-strudel-reference.php';
		require_once $dir . 'class-nvoos-algorave-tool-midi-output.php';
	}

	/**
	 * Register the AI Music Generation settings section and fields.
	 *
	 * Renders on the standalone plugin's "algorave-settings" page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_ai_settings() {
		if ( ! self::is_standalone_active() ) {
			return;
		}

		// Extend the standalone plugin's defaults.
		add_filter( 'nvoos_algorave/default_settings', array( __CLASS__, 'add_default_settings' ) );

		// Preserve AI keys when the standalone plugin sanitizes its settings.
		add_filter( 'nvoos_algorave/sanitize_settings', array( __CLASS__, 'sanitize_ai_settings' ), 10, 2 );

		add_settings_section(
			'nvoos_algorave_ai',
			__( 'AI Music Generation', 'nvoos-algorave-ai' ),
			'__return_false',
			'algorave-settings'
		);

		add_settings_field(
			'ai_provider',
			__( 'AI Provider', 'nvoos-algorave-ai' ),
			array( __CLASS__, 'render_select' ),
			'algorave-settings',
			'nvoos_algorave_ai',
			array(
				'id'      => 'ai_provider',
				'options' => array(
					''          => __( '— Use oOS Default —', 'nvoos-algorave-ai' ),
					'lyria'     => __( 'Google Lyria (Gemini)', 'nvoos-algorave-ai' ),
					'replicate' => __( 'Replicate', 'nvoos-algorave-ai' ),
				),
			)
		);

		add_settings_field(
			'ai_api_key',
			__( 'AI API Key', 'nvoos-algorave-ai' ),
			array( __CLASS__, 'render_password' ),
			'algorave-settings',
			'nvoos_algorave_ai',
			array(
				'id'          => 'ai_api_key',
				'description' => __( 'API key for the selected AI music generation provider (optional — uses oOS keys by default).', 'nvoos-algorave-ai' ),
			)
		);
	}

	/**
	 * Add AI defaults to the standalone plugin's settings defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param array $defaults Existing defaults.
	 * @return array
	 */
	public static function add_default_settings( $defaults ) {
		$defaults['ai_provider'] = '';
		$defaults['ai_api_key']  = '';
		return $defaults;
	}

	/**
	 * Sanitize the AI settings keys when the standalone plugin saves.
	 *
	 * @since 1.0.0
	 *
	 * @param array $sanitized Settings sanitized by the standalone plugin.
	 * @param array $input     Raw input values.
	 * @return array
	 */
	public static function sanitize_ai_settings( $sanitized, $input ) {
		$sanitized['ai_provider'] = sanitize_text_field( $input['ai_provider'] ?? '' );
		$sanitized['ai_api_key']  = sanitize_text_field( $input['ai_api_key'] ?? '' );
		return $sanitized;
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Warn if the standalone plugin is missing.
		if ( ! self::is_standalone_active() ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'NV oOS Algorave — AI requires the NV oOS Algorave plugin to be installed and activated.', 'nvoos-algorave-ai' );
			echo '</p></div>';
		}

		// Warn if the NV oOS base plugin is missing (tools need its registry).
		if ( ! nvoos_algorave_ai_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Algorave — AI registers its tools with the NV oOS base plugin. Install and activate the NV oOS plugin to use them in the chat interface.', 'nvoos-algorave-ai' );
			echo '</p></div>';
		}
	}

	/**
	 * Render a password field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_password( $args ) {
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="password"
			name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>">
			<?php foreach ( $args['options'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
