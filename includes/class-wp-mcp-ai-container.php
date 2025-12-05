<?php
/**
 * Dependency Injection Container
 *
 * Simple PSR-11 inspired DI container for managing plugin dependencies.
 * Part of Phase 4 refactoring (Milestone 10).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Container class
 *
 * Manages service instantiation and dependency injection.
 * Implements singleton pattern for shared instances.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Container {

	/**
	 * Registered service definitions
	 *
	 * @var array
	 */
	private $definitions = array();

	/**
	 * Resolved service instances
	 *
	 * @var array
	 */
	private $instances = array();

	/**
	 * Singleton instance
	 *
	 * @var WP_MCP_AI_Container|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_Container Container instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton
	 */
	private function __construct() {
		// Register default services.
		$this->register_default_services();
	}

	/**
	 * Register a service definition
	 *
	 * @param string   $id       Service identifier.
	 * @param callable $factory  Factory function that creates the service.
	 * @param bool     $shared   Whether to use singleton pattern (default true).
	 */
	public function register( $id, callable $factory, $shared = true ) {
		$this->definitions[ $id ] = array(
			'factory' => $factory,
			'shared'  => $shared,
		);

		// Clear cached instance if re-registering.
		if ( isset( $this->instances[ $id ] ) ) {
			unset( $this->instances[ $id ] );
		}
	}

	/**
	 * Register a shared instance (singleton)
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory function.
	 */
	public function singleton( $id, callable $factory ) {
		$this->register( $id, $factory, true );
	}

	/**
	 * Register a transient instance (new instance each time)
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory function.
	 */
	public function transient( $id, callable $factory ) {
		$this->register( $id, $factory, false );
	}

	/**
	 * Set a pre-created instance
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $instance Service instance.
	 */
	public function set( $id, $instance ) {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Get a service from the container
	 *
	 * @param string $id Service identifier.
	 * @return mixed Service instance.
	 * @throws Exception If service not found.
	 */
	public function get( $id ) {
		// Return cached instance if available and shared.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Check if service is registered.
		if ( ! isset( $this->definitions[ $id ] ) ) {
			throw new Exception(
				sprintf(
					'Service "%s" not found in container.',
					$id
				)
			);
		}

		$definition = $this->definitions[ $id ];
		$factory    = $definition['factory'];

		// Create instance.
		$instance = call_user_func( $factory, $this );

		// Cache if shared.
		if ( $definition['shared'] ) {
			$this->instances[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if service exists
	 *
	 * @param string $id Service identifier.
	 * @return bool True if exists.
	 */
	public function has( $id ) {
		return isset( $this->definitions[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Make an instance with automatic dependency resolution
	 *
	 * @param string $class_name Class name to instantiate.
	 * @param array  $params     Additional parameters.
	 * @return object Class instance.
	 * @throws ReflectionException If class doesn't exist.
	 */
	public function make( $class_name, $params = array() ) {
		$reflection = new ReflectionClass( $class_name );

		// If no constructor, just create instance.
		$constructor = $reflection->getConstructor();
		if ( ! $constructor ) {
			return new $class_name();
		}

		// Resolve constructor dependencies.
		$dependencies = array();
		foreach ( $constructor->getParameters() as $parameter ) {
			$param_name = $parameter->getName();

			// Use provided parameter if available.
			if ( isset( $params[ $param_name ] ) ) {
				$dependencies[] = $params[ $param_name ];
				continue;
			}

			// Try to resolve from container using type hint.
			$type = $parameter->getType();
			if ( $type && ! $type->isBuiltin() ) {
				$type_name = $type->getName();
				if ( $this->has( $type_name ) ) {
					$dependencies[] = $this->get( $type_name );
					continue;
				}
			}

			// Use default value if available.
			if ( $parameter->isDefaultValueAvailable() ) {
				$dependencies[] = $parameter->getDefaultValue();
				continue;
			}

			// Cannot resolve.
			throw new Exception(
				sprintf(
					'Cannot resolve parameter "%s" for class "%s".',
					$param_name,
					$class_name
				)
			);
		}

		return $reflection->newInstanceArgs( $dependencies );
	}

	/**
	 * Register default services
	 */
	private function register_default_services() {
		// Repositories.
		$this->singleton(
			'repository.assistant',
			function () {
				return new WP_MCP_AI_Assistant_Repository();
			}
		);

		$this->singleton(
			'repository.credential',
			function () {
				return new WP_MCP_AI_Credential_Repository();
			}
		);

		$this->singleton(
			'repository.settings',
			function () {
				return new WP_MCP_AI_Settings_Repository();
			}
		);

		$this->singleton(
			'repository.transcript',
			function () {
				return new WP_MCP_AI_Transcript_Repository();
			}
		);

		// Language model clients.
		$this->singleton(
			'client.openai',
			function () {
				return new WP_MCP_AI_OpenAI_Client();
			}
		);

		$this->singleton(
			'client.gemini',
			function () {
				return new WP_MCP_AI_Gemini_Client();
			}
		);

		$this->singleton(
			'client.ollama',
			function () {
				return new WP_MCP_AI_Ollama_Client();
			}
		);

		$this->singleton(
			'client.lm_studio',
			function () {
				return new WP_MCP_AI_LM_Studio_Client();
			}
		);

		$this->singleton(
			'client.anthropic',
			function () {
				return new WP_MCP_AI_Anthropic_Client();
			}
		);

		// Core managers.
		$this->singleton(
			'router',
			function ( $container ) {
				return new WP_MCP_AI_Language_Model_Router(
					$container->get( 'client.openai' ),
					$container->get( 'client.gemini' ),
					$container->get( 'client.ollama' ),
					$container->get( 'client.lm_studio' ),
					$container->get( 'client.anthropic' )
				);
			}
		);

		$this->singleton(
			'rate_limiter',
			function () {
				return new WP_MCP_AI_Rate_Limit_Manager();
			}
		);

		$this->singleton(
			'token_budget_manager',
			function () {
				return new WP_MCP_AI_Token_Budget_Manager();
			}
		);

		$this->singleton(
			'tool_registry',
			function () {
				return WP_MCP_AI_Tool_Registry::get_instance();
			}
		);

		// Core components.
		$this->singleton(
			'assistant_cpt',
			function ( $container ) {
				return new WP_MCP_AI_Assistant_CPT( $container->get( 'tool_registry' ) );
			}
		);

		$this->singleton(
			'crawl4ai_local_api',
			function () {
				return new WP_MCP_AI_Crawl4AI_Local_API();
			}
		);

		// REST API components.
		$this->singleton(
			'rest.authenticator',
			function () {
				return new WP_MCP_AI_REST_Authenticator();
			}
		);

		$this->singleton(
			'rest.validator',
			function () {
				return new WP_MCP_AI_REST_Validator();
			}
		);

		$this->singleton(
			'rest.sse_handler',
			function () {
				return new WP_MCP_AI_SSE_Handler();
			}
		);

		$this->singleton(
			'rest_controller',
			function ( $container ) {
				return new WP_MCP_AI_REST(
					$container->get( 'tool_registry' ),
					$container->get( 'router' ),
					$container->get( 'rest.authenticator' ),
					$container->get( 'rest.validator' ),
					$container->get( 'rest.sse_handler' )
				);
			}
		);

		$this->singleton(
			'rest.teams_controller',
			function () {
				return new WP_MCP_AI_REST_Teams_Controller();
			}
		);

		$this->singleton(
			'shortcodes',
			function () {
				return new WP_MCP_AI_Shortcodes();
			}
		);

		$this->singleton(
			'federation',
			function ( $container ) {
				return new WP_MCP_AI_Federation( $container->get( 'tool_registry' ) );
			}
		);

		// Admin components.
		$this->singleton(
			'admin.cron_manager',
			function () {
				return new WP_MCP_AI_Admin_Cron_Manager();
			}
		);

		$this->singleton(
			'admin.token_manager',
			function () {
				return new WP_MCP_AI_Admin_Token_Manager();
			}
		);

		$this->singleton(
			'admin.test_assistant',
			function () {
				return new WP_MCP_AI_Admin_Test_Assistant();
			}
		);

		$this->singleton(
			'admin.test_profession',
			function () {
				return new WP_MCP_AI_Admin_Test_Profession();
			}
		);

		$this->singleton(
			'admin.test_team',
			function () {
				return new WP_MCP_AI_Admin_Test_Team();
			}
		);

		$this->singleton(
			'admin.ajax_handlers',
			function () {
				return new WP_MCP_AI_Admin_AJAX_Handlers();
			}
		);

		$this->singleton(
			'admin.settings_base',
			function () {
				return new WP_MCP_AI_Admin_Settings_Base();
			}
		);

		$this->singleton(
			'admin.settings_renderer',
			function ( $container ) {
				return new WP_MCP_AI_Admin_Settings_Renderer(
					$container->get( 'admin.settings_base' )
				);
			}
		);

		$this->singleton(
			'admin.oauth_manager',
			function () {
				return new WP_MCP_AI_OAuth_Manager();
			}
		);

		$this->singleton(
			'admin.settings_dashboard',
			function () {
				return new WP_MCP_AI_Settings_Dashboard();
			}
		);

		$this->singleton(
			'admin.plugins_integration',
			function () {
				return new WP_MCP_AI_Admin_Plugins_Integration();
			}
		);

		$this->singleton(
			'admin.gmail_crawl_integration',
			function () {
				return new WP_MCP_AI_Admin_Gmail_Crawl_Integration();
			}
		);

		$this->singleton(
			'admin.custom_filters_applicator',
			function () {
				return new WP_MCP_AI_Custom_Filters_Applicator();
			}
		);

		$this->singleton(
			'admin.auth0_setup',
			function () {
				return new WP_MCP_AI_Auth0_Setup();
			}
		);

		$this->singleton(
			'admin.crawl4ai_monitor',
			function () {
				return new WP_MCP_AI_Admin_Crawl4AI_Monitor();
			}
		);

		// Settings sections.
		$this->singleton(
			'section.overview',
			function () {
				return new WP_MCP_AI_Section_Overview();
			}
		);

		$this->singleton(
			'section.general',
			function () {
				return new WP_MCP_AI_Section_General();
			}
		);

		$this->singleton(
			'section.chat_client',
			function () {
				return new WP_MCP_AI_Section_Chat_Client();
			}
		);

		$this->singleton(
			'section.custom_filters',
			function () {
				return new WP_MCP_AI_Section_Custom_Filters();
			}
		);

		$this->singleton(
			'section.providers',
			function () {
				return new WP_MCP_AI_Section_Providers();
			}
		);

		$this->singleton(
			'section.authentication',
			function () {
				return new WP_MCP_AI_Section_Authentication();
			}
		);

		$this->singleton(
			'section.tools',
			function () {
				return new WP_MCP_AI_Section_Tools();
			}
		);

		$this->singleton(
			'section.orchestration',
			function () {
				return new WP_MCP_AI_Section_Orchestration();
			}
		);

		$this->singleton(
			'section.integrations',
			function () {
				return new WP_MCP_AI_Section_Integrations();
			}
		);

		$this->singleton(
			'section.plugins_integration',
			function () {
				return new WP_MCP_AI_Section_Plugins_Integration();
			}
		);

		$this->singleton(
			'section.token_manager',
			function () {
				return new WP_MCP_AI_Section_Token_Manager();
			}
		);

		$this->singleton(
			'section.security',
			function () {
				return new WP_MCP_AI_Section_Security();
			}
		);

		$this->singleton(
			'section.performance',
			function () {
				return new WP_MCP_AI_Section_Performance();
			}
		);

		$this->singleton(
			'section.advanced',
			function () {
				return new WP_MCP_AI_Section_Advanced();
			}
		);

		$this->singleton(
			'section.media',
			function () {
				return new WP_MCP_AI_Section_Media();
			}
		);

		$this->singleton(
			'section.comments',
			function () {
				return new WP_MCP_AI_Section_Comments();
			}
		);

		$this->singleton(
			'section.site_creator',
			function () {
				return new WP_MCP_AI_Section_Site_Creator();
			}
		);

		// Services.
		$this->singleton(
			'service.chat',
			function ( $container ) {
				return new WP_MCP_AI_Chat_Service(
					$container->get( 'router' ),
					$container->get( 'rate_limiter' ),
					$container->get( 'token_budget_manager' ),
					$container->get( 'tool_registry' )
				);
			}
		);

		$this->singleton(
			'service.assistant',
			function ( $container ) {
				return new WP_MCP_AI_Assistant_Service(
					$container->get( 'settings_repository' )
				);
			}
		);

		$this->singleton(
			'service.tool',
			function ( $container ) {
				return new WP_MCP_AI_Tool_Service(
					$container->get( 'tool_registry' )
				);
			}
		);

		$this->singleton(
			'service.file',
			function () {
				return new WP_MCP_AI_File_Service();
			}
		);

		$this->singleton(
			'service.cron_status',
			function () {
				return new WP_MCP_AI_Cron_Status_Service();
			}
		);
	}

	/**
	 * Clear all instances (for testing)
	 */
	public function clear() {
		$this->instances = array();
	}

	/**
	 * Get all registered service IDs
	 *
	 * @return array Service IDs.
	 */
	public function get_registered_services() {
		return array_keys( $this->definitions );
	}
}
