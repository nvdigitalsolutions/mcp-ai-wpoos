<?php
/**
 * NV oOS LibreChat — Admin Settings Page
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page for the LibreChat addon.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_Admin_Page {

	/**
	 * Capability required.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'nvoos-librechat';

	/**
	 * Option group for settings.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'nvoos_librechat';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'tools.php',
			__( 'NV oOS LibreChat', 'nvoos-librechat' ),
			__( 'NV oOS LibreChat', 'nvoos-librechat' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			NV_oOS_LibreChat_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => NV_oOS_LibreChat_Plugin::DEFAULTS,
			)
		);

		add_settings_section(
			'nvoos_librechat_general',
			__( 'General', 'nvoos-librechat' ),
			'__return_empty_string',
			self::OPTION_GROUP
		);

		add_settings_field(
			'theme',
			__( 'Theme', 'nvoos-librechat' ),
			array( __CLASS__, 'render_select' ),
			self::OPTION_GROUP,
			'nvoos_librechat_general',
			array(
				'label_for' => 'nvoos_librechat_theme',
				'name'      => 'theme',
				'options'   => array(
					'dark'  => __( 'Dark', 'nvoos-librechat' ),
					'light' => __( 'Light', 'nvoos-librechat' ),
					'auto'  => __( 'Auto (system)', 'nvoos-librechat' ),
				),
			)
		);

		add_settings_field(
			'default_assistant_id',
			__( 'Default Assistant', 'nvoos-librechat' ),
			array( __CLASS__, 'render_assistant_select' ),
			self::OPTION_GROUP,
			'nvoos_librechat_general',
			array(
				'label_for' => 'nvoos_librechat_default_assistant_id',
				'name'      => 'default_assistant_id',
			)
		);

		add_settings_section(
			'nvoos_librechat_features',
			__( 'Feature Toggles', 'nvoos-librechat' ),
			'__return_empty_string',
			self::OPTION_GROUP
		);

		add_settings_field(
			'enable_code_interpreter',
			__( 'Code Interpreter', 'nvoos-librechat' ),
			array( __CLASS__, 'render_checkbox' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for'   => 'nvoos_librechat_enable_code_interpreter',
				'name'        => 'enable_code_interpreter',
				'description' => __( 'Enable sandboxed code execution in 8 languages (Python, JS, TS, Go, C++, Java, PHP, Rust). Requires Docker on the server.', 'nvoos-librechat' ),
			)
		);

		add_settings_field(
			'code_interpreter_timeout',
			__( 'Code Execution Timeout (seconds)', 'nvoos-librechat' ),
			array( __CLASS__, 'render_number' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for' => 'nvoos_librechat_code_interpreter_timeout',
				'name'      => 'code_interpreter_timeout',
				'min'       => 5,
				'max'       => 300,
			)
		);

		add_settings_field(
			'max_executions_per_hour',
			__( 'Max Executions Per Hour', 'nvoos-librechat' ),
			array( __CLASS__, 'render_number' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for' => 'nvoos_librechat_max_executions_per_hour',
				'name'      => 'max_executions_per_hour',
				'min'       => 1,
				'max'       => 100,
			)
		);

		add_settings_field(
			'enable_web_search',
			__( 'Web Search Reranker', 'nvoos-librechat' ),
			array( __CLASS__, 'render_checkbox' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for'   => 'nvoos_librechat_enable_web_search',
				'name'        => 'enable_web_search',
				'description' => __( 'Enable relevance-based reranking of web search results using Jina or Cohere.', 'nvoos-librechat' ),
			)
		);

		add_settings_field(
			'rerank_provider',
			__( 'Rerank Provider', 'nvoos-librechat' ),
			array( __CLASS__, 'render_select' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for' => 'nvoos_librechat_rerank_provider',
				'name'      => 'rerank_provider',
				'options'   => array(
					'jina'   => __( 'Jina AI', 'nvoos-librechat' ),
					'cohere' => __( 'Cohere', 'nvoos-librechat' ),
				),
			)
		);

		add_settings_field(
			'enable_speech',
			__( 'Speech (STT/TTS)', 'nvoos-librechat' ),
			array( __CLASS__, 'render_checkbox' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for'   => 'nvoos_librechat_enable_speech',
				'name'        => 'enable_speech',
				'description' => __( 'Enable speech-to-text and text-to-speech. Requires OpenAI API key configured in NV oOS → Settings.', 'nvoos-librechat' ),
			)
		);

		add_settings_field(
			'speech_tts_provider',
			__( 'TTS Provider', 'nvoos-librechat' ),
			array( __CLASS__, 'render_select' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for' => 'nvoos_librechat_speech_tts_provider',
				'name'      => 'speech_tts_provider',
				'options'   => array(
					''           => __( 'None (disabled)', 'nvoos-librechat' ),
					'openai'     => __( 'OpenAI TTS', 'nvoos-librechat' ),
					'elevenlabs' => __( 'ElevenLabs', 'nvoos-librechat' ),
				),
			)
		);

		add_settings_field(
			'enable_artifacts',
			__( 'Artifacts', 'nvoos-librechat' ),
			array( __CLASS__, 'render_checkbox' ),
			self::OPTION_GROUP,
			'nvoos_librechat_features',
			array(
				'label_for'   => 'nvoos_librechat_enable_artifacts',
				'name'        => 'enable_artifacts',
				'description' => __( 'Enable the Artifacts panel for viewing generated code, HTML, SVG, and Markdown artifacts inline.', 'nvoos-librechat' ),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-librechat' ) );
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'NV oOS LibreChat Settings', 'nvoos-librechat' ) );

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::OPTION_GROUP );
		submit_button();
		echo '</form>';

		echo '<hr>';

		printf( '<h2>%s</h2>', esc_html__( 'Preview', 'nvoos-librechat' ) );
		printf(
			'<p>%s <code>[nvoos_librechat]</code></p>',
			esc_html__( 'Embedded preview of the LibreChat React UI:', 'nvoos-librechat' )
		);

		echo do_shortcode( '[nvoos_librechat theme="dark" height="500px"]' );

		echo '</div>';
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$name     = $args['name'];
		$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';
		$options  = $args['options'];

		printf( '<select id="%s" name="%s[%s]">', esc_attr( $args['label_for'] ), esc_attr( NV_oOS_LibreChat_Plugin::OPTION_KEY ), esc_attr( $name ) );
		foreach ( $options as $opt_value => $opt_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $opt_value ),
				selected( $value, $opt_value, false ),
				esc_html( $opt_label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_checkbox( $args ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$name     = $args['name'];
		$value    = isset( $settings[ $name ] ) ? (bool) $settings[ $name ] : false;

		printf(
			'<input type="checkbox" id="%s" name="%s[%s]" value="1"%s />',
			esc_attr( $args['label_for'] ),
			esc_attr( NV_oOS_LibreChat_Plugin::OPTION_KEY ),
			esc_attr( $name ),
			checked( $value, true, false )
		);

		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( $args['description'] )
			);
		}
	}

	/**
	 * Render a number field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_number( $args ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$name     = $args['name'];
		$value    = isset( $settings[ $name ] ) ? absint( $settings[ $name ] ) : 0;
		$min      = isset( $args['min'] ) ? absint( $args['min'] ) : 0;
		$max      = isset( $args['max'] ) ? absint( $args['max'] ) : 999;

		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" min="%s" max="%s" step="1" class="small-text" />',
			esc_attr( $args['label_for'] ),
			esc_attr( NV_oOS_LibreChat_Plugin::OPTION_KEY ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max )
		);
	}

	/**
	 * Render an assistant dropdown.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_assistant_select( $args ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$name     = $args['name'];
		$value    = isset( $settings[ $name ] ) ? absint( $settings[ $name ] ) : 0;

		printf(
			'<select id="%s" name="%s[%s]">',
			esc_attr( $args['label_for'] ),
			esc_attr( NV_oOS_LibreChat_Plugin::OPTION_KEY ),
			esc_attr( $name )
		);
		printf(
			'<option value="0">%s</option>',
			esc_html__( '— Select —', 'nvoos-librechat' )
		);

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $assistants as $assistant ) {
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $assistant->ID ),
				selected( $value, $assistant->ID, false ),
				esc_html( $assistant->post_title )
			);
		}
		echo '</select>';
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['theme'] = isset( $input['theme'] ) && in_array( $input['theme'], array( 'dark', 'light', 'auto' ), true )
			? $input['theme']
			: 'dark';

		$sanitized['default_assistant_id'] = isset( $input['default_assistant_id'] ) ? absint( $input['default_assistant_id'] ) : 0;

		$sanitized['enable_code_interpreter'] = ! empty( $input['enable_code_interpreter'] );
		$sanitized['enable_web_search']       = ! empty( $input['enable_web_search'] );
		$sanitized['enable_speech']           = ! empty( $input['enable_speech'] );
		$sanitized['enable_artifacts']        = ! empty( $input['enable_artifacts'] );

		$sanitized['code_interpreter_timeout'] = isset( $input['code_interpreter_timeout'] )
			? max( 5, min( 300, absint( $input['code_interpreter_timeout'] ) ) )
			: 60;

		$sanitized['max_executions_per_hour'] = isset( $input['max_executions_per_hour'] )
			? max( 1, min( 100, absint( $input['max_executions_per_hour'] ) ) )
			: 10;

		$sanitized['rerank_provider'] = isset( $input['rerank_provider'] ) && in_array( $input['rerank_provider'], array( 'jina', 'cohere' ), true )
			? $input['rerank_provider']
			: 'jina';

		$sanitized['speech_tts_provider'] = isset( $input['speech_tts_provider'] ) && in_array( $input['speech_tts_provider'], array( '', 'openai', 'elevenlabs' ), true )
			? $input['speech_tts_provider']
			: '';

		$sanitized['speech_stt_provider'] = isset( $input['speech_stt_provider'] ) && in_array( $input['speech_stt_provider'], array( '', 'openai' ), true )
			? $input['speech_stt_provider']
			: '';

		return $sanitized;
	}
}
