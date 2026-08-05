<?php
/**
 * NV oOS Embedded AI Addon — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * provider bridge, asset enqueuing, and WebChat integration
 * for the embedded AI addon.
 *
 * @package NV_oOS_Embedded
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Embedded AI Addon.
 *
 * @since 0.1.0
 *
 * phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Addon class follows NV_oOS_ naming convention.
 * phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid -- Matches NV_oOS_Algorave convention.
 */
class NV_oOS_Embedded {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_embedded_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin and register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_embedded_is_base_active() ) {
			return;
		}

		// Migrate settings from scattered options to unified key (v0.2.0).
		self::maybe_migrate_settings();

		// Register built-in backends via the registry.
		add_action( 'nvoos_embedded_backends_init', array( __CLASS__, 'register_default_backends' ) );

		// Initialize abilities registration (WordPress 6.9+).
		NV_oOS_Embedded_Abilities::init();

		// Register Site Health checks (v0.2.0).
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_tests' ) );

		// Register embedded provider bridge with the language model router.
		add_filter( 'wp_mcp_ai_embedded_chat_completion', array( __CLASS__, 'handle_embedded_chat_completion' ), 10, 3 );

		// Register embedded script enqueue hook.
		add_action( 'wp_mcp_ai_enqueue_embedded_scripts', array( __CLASS__, 'enqueue_embedded_scripts' ), 10, 4 );

		// Register embedded provider availability check.
		add_filter( 'wp_mcp_ai_is_embedded_provider_available', array( __CLASS__, 'is_embedded_provider_available' ) );

		// Register embedded server model check.
		add_filter( 'wp_mcp_ai_is_embedded_server_model', array( __CLASS__, 'is_embedded_server_model' ), 10, 2 );

		// Register embedded config endpoint filter.
		add_filter( 'wp_mcp_ai_embedded_client_config', array( __CLASS__, 'get_embedded_client_config' ), 10, 2 );

		// Register WebLLM script registration.
		add_action( 'wp_mcp_ai_register_embedded_scripts', array( __CLASS__, 'register_embedded_scripts' ) );

		// Register STT/voice scripts.
		add_action( 'wp_mcp_ai_register_embedded_scripts', array( __CLASS__, 'register_stt_scripts' ) );

		// Register transcribe REST endpoint.
		add_action( 'rest_api_init', array( __CLASS__, 'register_transcribe_endpoint' ) );

		// Extend embedded client config with voice settings.
		add_filter( 'wp_mcp_ai_embedded_client_config', array( __CLASS__, 'add_voice_config' ), 10, 2 );

		// Register voice CSS.
		add_action( 'wp_mcp_ai_enqueue_embedded_scripts', array( __CLASS__, 'enqueue_voice_styles' ), 10, 4 );

		// Load webchat if enabled.
		self::maybe_load_webchat();

		// Register webchat tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_webchat_tools' ) );

		// Fire backend init action so built-in and third-party backends register.
		do_action( 'nvoos_embedded_backends_init' );
	}

	/**
	 * Show admin notices when base plugin is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( nvoos_embedded_is_base_active() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'NV oOS Embedded AI addon requires the NV oOS (Open Operator System) base plugin to be active.', 'nvoos-embedded' )
		);
	}

	/**
	 * Check whether the embedded addon is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get default settings for the embedded addon.
	 *
	 * @since 0.2.0
	 *
	 * @return array Default settings keyed by option name.
	 */
	public static function get_default_settings() {
		return array(
			'enabled'               => true,
			// Inference backends.
			'inference_backend'     => 'auto',
			'client_model'          => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			'server_model'          => 'granite-3.1-2b-instruct',
			'server_binary_path'    => '',
			'server_max_tokens'     => 512,
			'server_temperature'    => 0.7,
			'server_context_window' => 2048,
			// Voice / STT.
			'enable_voice_mode'     => false,
			'stt_backend'           => 'whisper_cpp_wasm',
			'stt_model'             => 'tiny.en',
			'vad_threshold'         => 0.5,
			'vad_silence_ms'        => 800,
			'gemma4_audio_endpoint' => '',
			// Feature flags.
			'enable_tool_calling'   => false,
			'enable_multimodal'     => false,
			'enable_langchain'      => false,
			// WebChat.
			'enable_webchat'        => false,
			'webchat_max_rooms'     => 50,
		);
	}

	/**
	 * Migrate settings from scattered options to unified key.
	 *
	 * Runs once on plugin update. Preserves old keys for 1 release cycle
	 * so rollback is safe.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function maybe_migrate_settings() {
		$migration_done = get_option( 'nvoos_embedded_settings_migrated_v020', false );
		if ( $migration_done ) {
			return;
		}

		$defaults     = self::get_default_settings();
		$new_settings = get_option( self::OPTION_KEY, array() );

		// Merge from wp_mcp_ai_settings.
		$base_settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $base_settings['enable_webchat_integration'] ) ) {
			$new_settings['enable_webchat'] = true;
		}

		// Merge from standalone feature flags.
		if ( get_option( 'wp_mcp_ai_enable_webllm_tools', false ) ) {
			$new_settings['enable_tool_calling'] = true;
		}
		if ( get_option( 'wp_mcp_ai_enable_webllm_vision', false ) ) {
			$new_settings['enable_multimodal'] = true;
		}

		$new_settings = array_merge( $defaults, $new_settings );
		update_option( self::OPTION_KEY, $new_settings, 'yes' );
		update_option( 'nvoos_embedded_settings_migrated_v020', true );

		// Clean up old standalone feature-flag options.
		delete_option( 'wp_mcp_ai_enable_webllm_tools' );
		delete_option( 'wp_mcp_ai_enable_webllm_vision' );
	}

	/**
	 * Register built-in LLM backends with the registry.
	 *
	 * Hooked into nvoos_embedded_backends_init. Third-party plugins can
	 * register additional backends on the nvoos_embedded_backends_registered
	 * action that fires after this.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_default_backends() {
		$registry = NV_oOS_Embedded_Backend_Registry::get_instance();

		// Register LLM backends.
		$registry->register_llm_backend( new NV_oOS_Embedded_Client_Backend() );
		$registry->register_llm_backend( new NV_oOS_Embedded_Server_Backend() );

		/**
		 * Fires after default embedded backends are registered.
		 *
		 * Plugins can use this to register additional backends or
		 * override existing ones (unregister + register pattern).
		 *
		 * @since 0.2.0
		 *
		 * @param NV_oOS_Embedded_Backend_Registry $registry The backend registry.
		 */
		do_action( 'nvoos_embedded_backends_registered', $registry );
	}

	/**
	 * Register WordPress Site Health tests for the embedded addon.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tests Site Health tests.
	 * @return array Modified tests.
	 */
	public static function register_site_health_tests( $tests ) {
		if ( ! class_exists( 'NV_oOS_Embedded_Backend_Registry' ) ) {
			return $tests;
		}

		$registry = NV_oOS_Embedded_Backend_Registry::get_instance();

		foreach ( $registry->get_all_llm_backends() as $slug => $backend ) {
			$health = $backend->get_health_status();

			if ( ! isset( $health['test'] ) ) {
				continue;
			}

			$test_data = $health['test'];
			$test_key  = isset( $test_data['test'] ) ? $test_data['test'] : 'nvoos_embedded_' . $slug;
			$is_direct = 'critical' === $health['status'];

			$callback = function () use ( $backend, $health ) {
				$result                = array();
				$result['label']       = $health['label'];
				$result['status']      = $health['status'];
				$result['badge']       = isset( $health['test']['badge'] ) ? $health['test']['badge'] : array(
					'label' => __( 'Embedded AI', 'nvoos-embedded' ),
					'color' => 'blue',
				);
				$result['description'] = isset( $health['test']['description'] ) ? $health['test']['description'] : $health['description'];
				$result['actions']     = isset( $health['actions'] ) ? $health['actions'] : '';
				$result['test']        = $test_key;

				return $result;
			};

			if ( $is_direct ) {
				$tests['direct'][ $test_key ] = array(
					'label' => $health['label'],
					'test'  => $callback,
				);
			} else {
				$tests['async'][ $test_key ] = array(
					'label' => $health['label'],
					'test'  => $callback,
				);
			}
		}

		return $tests;
	}

	/**
	 * Handle embedded provider chat completion via filter.
	 *
	 * Bridges the language model router's `wp_mcp_ai_embedded_chat_completion` filter
	 * to the actual embedded client implementation.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result   Current result (null if not yet handled).
	 * @param array $messages Chat messages array.
	 * @param array $options  Request options.
	 * @return array|WP_Error Chat completion result.
	 */
	public static function handle_embedded_chat_completion( $result, $messages, $options ) {
		// If another handler already returned a result, don't override.
		if ( null !== $result ) {
			return $result;
		}

		$registry = NV_oOS_Embedded_Backend_Registry::get_instance();
		$backend  = $registry->get_active_llm_backend();

		if ( ! $backend ) {
			return new WP_Error(
				'no_embedded_backend',
				__( 'No embedded inference backend is available.', 'nvoos-embedded' )
			);
		}

		return $backend->create_chat_completion( $messages, $options );
	}

	/**
	 * Check if embedded provider is available.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $available Current availability status.
	 * @return bool True if embedded provider is available.
	 */
	public static function is_embedded_provider_available( $available ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress filter callback.
		return true;
	}

	/**
	 * Check if a model slug is a server-side embedded model.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $is_server Current server model status.
	 * @param string $model     Model slug to check.
	 * @return bool True if model is a server-side GGUF model.
	 */
	public static function is_embedded_server_model( $is_server, $model ) {
		if ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			return WP_MCP_AI_Embedded_Client::is_server_model_slug( $model );
		}
		return $is_server;
	}

	/**
	 * Get embedded client configuration for REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param array $config       Current config array.
	 * @param int   $assistant_id Assistant ID.
	 * @return array Configuration array.
	 */
	public static function get_embedded_client_config( $config, $assistant_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback.
		// Config is already built by the REST chat controller.
		return $config;
	}

	/**
	 * Register embedded LLM scripts (WebLLM loader, client, tool adapter, function calling).
	 *
	 * Hooked into wp_mcp_ai_register_embedded_scripts to let the addon register
	 * scripts from its own assets directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_embedded_scripts() {
		$version = NVOOS_EMBEDDED_VERSION;

		// Use minified assets in production, source files when SCRIPT_DEBUG is on.
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

		// Core WebLLM loader.
		if ( ! wp_script_is( 'webllm-loader', 'registered' ) ) {
			wp_register_script(
				'webllm-loader',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-loader' . $suffix,
				array(),
				$version,
				true
			);
		}

		// Embedded LLM client.
		if ( ! wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-embedded-llm-client',
				NVOOS_EMBEDDED_URL . 'assets/js/embedded-llm-client' . $suffix,
				array( 'webllm-loader' ),
				$version,
				true
			);
		}

		// Tool adapter.
		if ( ! wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-webllm-tool-adapter',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-tool-adapter' . $suffix,
				array(),
				$version,
				true
			);
		}

		// Function calling client.
		if ( ! wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-webllm-function-calling',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-function-calling-client' . $suffix,
				array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-webllm-tool-adapter' ),
				$version,
				true
			);
		}
	}

	/**
	 * Enqueue embedded LLM scripts when needed.
	 *
	 * Called via the wp_mcp_ai_enqueue_embedded_scripts action from the shortcode.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $needs_embedded Whether embedded provider scripts are needed.
	 * @param bool $has_tools      Whether the assistant has tools configured.
	 * @param bool $has_system_prompt Whether the assistant has a system prompt.
	 * @param bool $has_knowledge  Whether the assistant has knowledge files.
	 * @return void
	 */
	public static function enqueue_embedded_scripts( $needs_embedded, $has_tools, $has_system_prompt, $has_knowledge ) {
		if ( ! $needs_embedded ) {
			return;
		}

		wp_enqueue_script( 'webllm-loader' );
		wp_enqueue_script( 'wp-mcp-ai-embedded-llm-client' );

		if ( $has_tools || $has_system_prompt || $has_knowledge ) {
			wp_enqueue_script( 'wp-mcp-ai-webllm-tool-adapter' );
			wp_enqueue_script( 'wp-mcp-ai-webllm-function-calling' );
		}

		// Enqueue voice tool calling bridge if voice mode + tools enabled.
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $settings['enable_voice_mode'] ) && ( $has_tools || $has_system_prompt || $has_knowledge ) ) {
			wp_enqueue_script( 'nvoos-voice-tool-bridge' );
		}
	}

	/**
	 * Conditionally load webchat integration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function maybe_load_webchat() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_webchat_integration'] ) ) {
			return;
		}

		// Load webchat CPT.
		require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-cpt.php';
		WP_MCP_AI_WebChat_CPT::init();

		// Register WebChat meta fields with JetEngine for listing/discovery.
		if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
			WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_webchat' );
		}

		// Load JetEngine WebChat Messages CCT if JetEngine is active.
		if ( function_exists( 'jet_engine' ) ) {
			require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-jetengine-webchat-messages-cct.php';
			WP_MCP_AI_JetEngine_WebChat_Messages_CCT::bootstrap();
		}

		// Load WebChat Signaling REST Controller.
		require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-signaling-rest-controller.php';
		add_action(
			'rest_api_init',
			function () {
				$controller = new WP_MCP_AI_WebChat_Signaling_REST_Controller();
				$controller->register_routes();
			}
		);

		// Load WebChat Settings page.
		if ( is_admin() ) {
			$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
			if ( ! $is_base ) {
				require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-settings-page.php';
			}
		}
	}

	// ── Voice / STT Methods ────────────────────────────────────────

	/**
	 * Register STT and voice scripts.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_stt_scripts() {
		$version = NVOOS_EMBEDDED_VERSION;
		$suffix  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

		// Audio capture service.
		if ( ! wp_script_is( 'nvoos-audio-capture', 'registered' ) ) {
			wp_register_script(
				'nvoos-audio-capture',
				NVOOS_EMBEDDED_URL . 'assets/js/audio-capture-service' . $suffix,
				array(),
				$version,
				true
			);
		}

		// STT service API.
		if ( ! wp_script_is( 'nvoos-stt-service-api', 'registered' ) ) {
			wp_register_script(
				'nvoos-stt-service-api',
				NVOOS_EMBEDDED_URL . 'assets/js/stt-service-api' . $suffix,
				array(),
				$version,
				true
			);
		}

		// VAD processor.
		if ( ! wp_script_is( 'nvoos-stt-vad', 'registered' ) ) {
			wp_register_script(
				'nvoos-stt-vad',
				NVOOS_EMBEDDED_URL . 'assets/js/stt-vad-processor' . $suffix,
				array(),
				$version,
				true
			);
		}

		// whisper.cpp WASM backend.
		if ( ! wp_script_is( 'nvoos-stt-whisper-cpp', 'registered' ) ) {
			wp_register_script(
				'nvoos-stt-whisper-cpp',
				NVOOS_EMBEDDED_URL . 'assets/js/stt-whisper-cpp-backend' . $suffix,
				array( 'nvoos-stt-service-api' ),
				$version,
				true
			);
		}

		// Gemma 4 audio backend.
		if ( ! wp_script_is( 'nvoos-stt-gemma4', 'registered' ) ) {
			wp_register_script(
				'nvoos-stt-gemma4',
				NVOOS_EMBEDDED_URL . 'assets/js/stt-gemma4-backend' . $suffix,
				array( 'nvoos-stt-service-api', 'nvoos-audio-capture' ),
				$version,
				true
			);
		}

		// Transformers.js Whisper backend.
		if ( ! wp_script_is( 'nvoos-stt-transformers', 'registered' ) ) {
			wp_register_script(
				'nvoos-stt-transformers',
				NVOOS_EMBEDDED_URL . 'assets/js/stt-transformers-backend' . $suffix,
				array( 'nvoos-stt-service-api' ),
				$version,
				true
			);
		}

		// Voice mode embedded UI.
		if ( ! wp_script_is( 'nvoos-voice-mode-embedded', 'registered' ) ) {
			wp_register_script(
				'nvoos-voice-mode-embedded',
				NVOOS_EMBEDDED_URL . 'assets/js/voice-mode-embedded' . $suffix,
				array( 'nvoos-audio-capture', 'nvoos-stt-service-api', 'nvoos-stt-vad' ),
				$version,
				true
			);
		}

		// Voice tool calling bridge (v1.3.0).
		if ( ! wp_script_is( 'nvoos-voice-tool-bridge', 'registered' ) ) {
			wp_register_script(
				'nvoos-voice-tool-bridge',
				NVOOS_EMBEDDED_URL . 'assets/js/voice-tool-calling-bridge' . $suffix,
				array(),
				$version,
				true
			);
		}
	}

	/**
	 * Enqueue voice mode CSS styles.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $needs_embedded  Whether embedded is needed.
	 * @param bool $has_tools       Whether tools exist.
	 * @param bool $has_system_prompt Whether system prompt exists.
	 * @param bool $has_knowledge   Whether knowledge files exist.
	 * @return void
	 */
	public static function enqueue_voice_styles( $needs_embedded, $has_tools, $has_system_prompt, $has_knowledge ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress action callback.
		if ( ! $needs_embedded ) {
			return;
		}

		$settings = get_option( 'nvoos_embedded_settings', array() );
		if ( empty( $settings['enable_voice_mode'] ) ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-voice-embedded',
			NVOOS_EMBEDDED_URL . 'assets/css/voice-embedded.css',
			array(),
			NVOOS_EMBEDDED_VERSION
		);
	}

	/**
	 * Add voice configuration to embedded client config.
	 *
	 * @since 1.2.0
	 *
	 * @param array $config       Current config.
	 * @param int   $assistant_id Assistant ID.
	 * @return array
	 */
	public static function add_voice_config( $config, $assistant_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback.
		$settings = get_option( 'nvoos_embedded_settings', array() );

		$config['enableVoiceMode'] = ! empty( $settings['enable_voice_mode'] );
		$config['sttBackend']      = isset( $settings['stt_backend'] ) ? sanitize_key( $settings['stt_backend'] ) : 'whisper_cpp_wasm';
		$config['sttModel']        = isset( $settings['stt_model'] ) ? sanitize_text_field( $settings['stt_model'] ) : 'tiny.en';
		$config['vadThreshold']    = isset( $settings['vad_threshold'] ) ? floatval( $settings['vad_threshold'] ) : 0.01;
		$config['sttEndpoint']     = isset( $settings['gemma4_audio_endpoint'] ) ? esc_url_raw( $settings['gemma4_audio_endpoint'] ) : '';
		$config['sttConfig']       = array(
			'wasmJsUrl' => NVOOS_EMBEDDED_URL . 'assets/stt/whisper.js',
			'workerUrl' => NVOOS_EMBEDDED_URL . 'assets/js/stt-whisper-cpp-worker' . ( ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js' ),
			'modelUrl'  => NVOOS_EMBEDDED_URL . 'assets/stt/models/',
		);
		$config['restNonce']       = wp_create_nonce( 'wp_rest' );

		return $config;
	}

	/**
	 * Register the embedded transcribe REST endpoint.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_transcribe_endpoint() {
		register_rest_route(
			'mcp-ai/v1',
			'/embedded/transcribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function () {
					return is_user_logged_in() || apply_filters(
						'nvoos_embedded_allow_guest_transcribe',
						false
					);
				},
				'callback'            => array( __CLASS__, 'handle_transcribe_request' ),
				'args'                => array(
					'audio'        => array(
						'description' => __( 'Base64-encoded audio or data URI.', 'nvoos-embedded' ),
						'type'        => 'string',
						'required'    => true,
					),
					'model'        => array(
						'description' => __( 'Model identifier.', 'nvoos-embedded' ),
						'type'        => 'string',
						'default'     => 'gemma4:e4b',
					),
					'language'     => array(
						'description' => __( 'Language code.', 'nvoos-embedded' ),
						'type'        => 'string',
						'default'     => 'en',
					),
					'unified_mode' => array(
						'description' => __( 'Use unified STT+LLM mode.', 'nvoos-embedded' ),
						'type'        => 'boolean',
						'default'     => false,
					),
					'prompt'       => array(
						'description' => __( 'Prompt for unified mode.', 'nvoos-embedded' ),
						'type'        => 'string',
						'default'     => '',
					),
				),
			)
		);
	}

	/**
	 * Handle POST /embedded/transcribe request.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_transcribe_request( WP_REST_Request $request ) {
		// Rate limiting: max 30 requests per minute per user/IP.
		$user_id    = get_current_user_id();
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_key   = 'nvoos_transcribe_rate_' . ( $user_id ? 'u' . $user_id : 'ip_' . md5( $ip ) );
		$rate_count = get_transient( $rate_key );

		if ( false === $rate_count ) {
			set_transient( $rate_key, 1, 60 );
		} elseif ( $rate_count >= 30 ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many transcription requests. Please wait before trying again.', 'nvoos-embedded' ),
				array( 'status' => 429 )
			);
		} else {
			set_transient( $rate_key, $rate_count + 1, 60 );
		}

		$audio_data   = $request->get_param( 'audio' );
		$model        = $request->get_param( 'model' );
		$language     = $request->get_param( 'language' );
		$unified_mode = (bool) $request->get_param( 'unified_mode' );
		$prompt       = $request->get_param( 'prompt' );

		if ( empty( $audio_data ) ) {
			return new WP_Error(
				'missing_audio',
				__( 'Audio data is required.', 'nvoos-embedded' ),
				array( 'status' => 400 )
			);
		}

		$transcriber = new WP_MCP_AI_Embedded_Transcribe();
		$result      = $transcriber->transcribe(
			$audio_data,
			array(
				'model'        => $model,
				'language'     => $language,
				'unified_mode' => $unified_mode,
				'prompt'       => $prompt,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Register webchat tools with the oOS tool registry.
	 *
	 * @since 0.1.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_webchat_tools( $registry ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_webchat_integration'] ) ) {
			return;
		}

		$tool_dir = NVOOS_EMBEDDED_PATH . 'includes/webchat/tools/';

		$tool_files = array(
			'class-wp-mcp-ai-tool-create-webchat-room.php',
			'class-wp-mcp-ai-tool-get-webchat-room.php',
			'class-wp-mcp-ai-tool-list-webchat-rooms.php',
			'class-wp-mcp-ai-tool-get-webchat-status.php',
			'class-wp-mcp-ai-tool-get-webchat-messages.php',
			'class-wp-mcp-ai-tool-save-webchat-message.php',
			'class-wp-mcp-ai-tool-send-webchat-message.php',
		);

		foreach ( $tool_files as $file ) {
			$path = $tool_dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		$tool_classes = array(
			'WP_MCP_AI_Tool_Create_WebChat_Room',
			'WP_MCP_AI_Tool_Get_WebChat_Room',
			'WP_MCP_AI_Tool_List_WebChat_Rooms',
			'WP_MCP_AI_Tool_Get_WebChat_Status',
			'WP_MCP_AI_Tool_Get_WebChat_Messages',
			'WP_MCP_AI_Tool_Save_WebChat_Message',
			'WP_MCP_AI_Pro_Tool_Send_WebChat_Message',
		);

		foreach ( $tool_classes as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$tool = new $class_name();
				$registry->register_tool( $tool );
			}
		}
	}
}
