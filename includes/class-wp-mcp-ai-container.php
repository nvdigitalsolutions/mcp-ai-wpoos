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

		// Core managers.
		$this->singleton(
			'router',
			function () {
				return new WP_MCP_AI_Language_Model_Router();
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
				return new WP_MCP_AI_Tool_Registry();
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
			function () {
				return new WP_MCP_AI_Assistant_Service();
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
