<?php
/**
 * Tool that returns a structured view of Site Health tests.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes Site Health tests and returns aggregated results.
 */
class WP_MCP_AI_Tool_Get_Site_Health implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Capability required to run the tool.
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_site_health';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Site Health Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Runs WordPress Site Health tests and returns grouped critical, warning, and passing results.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		WP_MCP_AI_Logger::log_event(
			'site_health_check',
			'Checking Site Health access.',
			array(
				'user_id' => $user_id,
			)
		);

		$has_capability = user_can( $user_id, self::REQUIRED_CAPABILITY );

		WP_MCP_AI_Logger::log_event(
			'site_health_capability_check',
			'User capability check completed.',
			array(
				'user_id'        => $user_id,
				'capability'     => self::REQUIRED_CAPABILITY,
				'has_capability' => $has_capability,
			)
		);

		if ( ! $user_id || ! $has_capability ) {
			WP_MCP_AI_Logger::log_error(
				'Site Health access denied - insufficient permissions.',
				array(
					'user_id'    => $user_id,
					'capability' => self::REQUIRED_CAPABILITY,
				)
			);
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view Site Health results.', 'wp-mcp-ai' ) );
		}

		$is_multisite   = is_multisite();
		$is_site_member = $is_multisite ? is_user_member_of_blog( $user_id, get_current_blog_id() ) : null;

		if ( $is_multisite && ! $is_site_member ) {
			WP_MCP_AI_Logger::log_error(
				'Site Health access denied - user not member of site in multisite.',
				array(
					'user_id'        => $user_id,
					'blog_id'        => get_current_blog_id(),
					'is_multisite'   => true,
					'is_site_member' => false,
				)
			);
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'site_health_multisite_check',
			'Multisite check completed.',
			array(
				'is_multisite'   => $is_multisite,
				'blog_id'        => get_current_blog_id(),
				'user_id'        => $user_id,
				'is_site_member' => $is_site_member,
			)
		);

		$dependencies_loaded   = $this->ensure_site_health_dependencies();
		$wp_site_health_exists = class_exists( 'WP_Site_Health', false );
		$get_tests_callable    = is_callable( array( 'WP_Site_Health', 'get_tests' ) );

		WP_MCP_AI_Logger::log_event(
			'site_health_dependency_check',
			'Site Health dependencies check completed.',
			array(
				'dependencies_loaded'   => $dependencies_loaded,
				'wp_site_health_exists' => $wp_site_health_exists,
				'get_tests_callable'    => $get_tests_callable,
			)
		);

		if ( ! $dependencies_loaded ) {
			WP_MCP_AI_Logger::log_error(
				'Site Health unavailable - dependencies could not be loaded.',
				array(
					'wp_site_health_exists' => $wp_site_health_exists,
				)
			);
			return new WP_Error( 'wp_mcp_ai_missing_dependency', __( 'The WordPress Site Health component is unavailable.', 'wp-mcp-ai' ) );
		}

		if ( ! $get_tests_callable ) {
			WP_MCP_AI_Logger::log_error(
				'Site Health unavailable - get_tests method not callable.',
				array(
					'wp_site_health_exists' => $wp_site_health_exists,
					'get_tests_callable'    => false,
				)
			);
			return new WP_Error( 'wp_mcp_ai_missing_dependency', __( 'The Site Health API is not available on this installation.', 'wp-mcp-ai' ) );
		}

		$site_health = $this->get_site_health_instance();

		if ( ! $site_health instanceof WP_Site_Health ) {
			WP_MCP_AI_Logger::log_error(
				'Site Health instance initialization failed.',
				array(
					'site_health_type' => is_object( $site_health ) ? get_class( $site_health ) : gettype( $site_health ),
				)
			);
			return new WP_Error( 'wp_mcp_ai_missing_dependency', __( 'Could not initialise the Site Health API.', 'wp-mcp-ai' ) );
		}

		$tests = WP_Site_Health::get_tests();

		$results = array(
			'critical' => array(),
			'warning'  => array(),
			'pass'     => array(),
		);

		foreach ( $this->flatten_tests( $tests ) as $test_identifier => $test_definition ) {
			$result = $this->run_single_test( $site_health, $test_definition );

			if ( empty( $result ) || ! is_array( $result ) ) {
				continue;
			}

			$bucket = $this->map_status_to_bucket( isset( $result['status'] ) ? $result['status'] : '' );

			if ( ! $bucket ) {
				continue;
			}

			$results[ $bucket ][] = $this->format_test_result( $test_identifier, $result );
		}

		return array(
			'summary' => array(
				'critical' => count( $results['critical'] ),
				'warning'  => count( $results['warning'] ),
				'pass'     => count( $results['pass'] ),
			),
			'tests'   => $results,
		);
	}

	/**
	 * Ensure the Site Health class and its dependencies are loaded.
	 *
	 * WordPress core function polyfills must be loaded BEFORE WordPress admin includes
	 * because some WordPress files (like misc.php) declare functions without checking
	 * if they exist first.
	 *
	 * @return bool
	 */
	protected function ensure_site_health_dependencies() {
		if ( class_exists( 'WP_Site_Health', false ) ) {
			return true;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}

		// Load polyfills BEFORE WordPress admin files to prevent redeclaration errors.
		require_once WP_MCP_AI_PATH . 'includes/wordpress-polyfills.php';

		$maybe_require = static function ( $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		};

		// Load WordPress admin includes in the order WordPress core loads them.
		// This ensures all function dependencies are available for Site Health tests.
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/admin.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/file.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/template.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/theme.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/misc.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/update.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/class-wp-site-health.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/class-wp-site-health-auto-updates.php' );
		$maybe_require( trailingslashit( ABSPATH ) . 'wp-admin/includes/class-wp-debug-data.php' );

		return class_exists( 'WP_Site_Health', false );
	}

	/**
	 * Retrieve a Site Health instance.
	 *
	 * @return WP_Site_Health|null
	 */
	protected function get_site_health_instance() {
		if ( is_callable( array( 'WP_Site_Health', 'get_instance' ) ) ) {
			return WP_Site_Health::get_instance();
		}

		return class_exists( 'WP_Site_Health', false ) ? new WP_Site_Health() : null;
	}

	/**
	 * Flatten direct and asynchronous tests into a single iterable map.
	 *
	 * @param array $tests Site Health test definitions.
	 * @return array<string, array>
	 */
	protected function flatten_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return array();
		}

		$tests = array_merge(
			array(
				'direct' => array(),
				'async'  => array(),
			),
			$tests
		);

		$flat = array();

		foreach ( $tests['direct'] as $identifier => $definition ) {
			$flat[ (string) $identifier ] = $definition;
		}

		foreach ( $tests['async'] as $identifier => $definition ) {
			$flat[ (string) $identifier ] = $definition;
		}

		return $flat;
	}

	/**
	 * Run a single Site Health test.
	 *
	 * @param WP_Site_Health $site_health Site Health instance.
	 * @param array          $test_definition Test configuration.
	 * @return array|null
	 */
	protected function run_single_test( WP_Site_Health $site_health, $test_definition ) {
		if ( ! is_array( $test_definition ) || empty( $test_definition['test'] ) ) {
			if ( ! empty( $test_definition['async_direct_test'] ) && is_callable( $test_definition['async_direct_test'] ) ) {
				return $this->call_site_health_test_callback( $test_definition['async_direct_test'] );
			}

			return null;
		}

		$callback = null;

		if ( is_callable( $test_definition['test'] ) ) {
			$callback = $test_definition['test'];
		} elseif ( is_string( $test_definition['test'] ) ) {
			$method = sprintf( 'get_test_%s', $test_definition['test'] );
			if ( method_exists( $site_health, $method ) && is_callable( array( $site_health, $method ) ) ) {
				$callback = array( $site_health, $method );
			} elseif ( function_exists( $test_definition['test'] ) ) {
				$callback = $test_definition['test'];
			}
		}

		if ( ! $callback && ! empty( $test_definition['async_direct_test'] ) && is_callable( $test_definition['async_direct_test'] ) ) {
			$callback = $test_definition['async_direct_test'];
		}

		if ( ! $callback ) {
			return null;
		}

		return $this->call_site_health_test_callback( $callback );
	}

	/**
	 * Execute a Site Health callback and apply the standard filter.
	 *
	 * @param callable $callback Callback to execute.
	 * @return array|null
	 */
	protected function call_site_health_test_callback( $callback ) {
		try {
			$result = call_user_func( $callback );

			if ( null === $result ) {
				return null;
			}

			/** This filter matches core Site Health behaviour. */
			$result = apply_filters( 'site_status_test_result', $result );

			return is_array( $result ) ? $result : null;
		} catch ( Throwable $e ) {
			$this->log_site_health_callback_error( $e, $callback );
			return null;
		}
	}

	/**
	 * Log an error from a Site Health test callback.
	 *
	 * @param Throwable $error    The exception or error that was thrown.
	 * @param callable  $callback The callback that threw the error.
	 */
	private function log_site_health_callback_error( Throwable $error, $callback ) {
		$callback_name = 'unknown';

		if ( is_array( $callback ) && count( $callback ) === 2 ) {
			$class_or_object = $callback[0];
			$method          = $callback[1];

			if ( is_object( $class_or_object ) ) {
				$callback_name = get_class( $class_or_object ) . '::' . $method;
			} elseif ( is_string( $class_or_object ) ) {
				$callback_name = $class_or_object . '::' . $method;
			}
		} elseif ( is_string( $callback ) ) {
			$callback_name = $callback;
		}

		WP_MCP_AI_Logger::log_error(
			'Site Health test callback threw error.',
			array(
				'error_message' => $error->getMessage(),
				'error_file'    => $error->getFile(),
				'error_line'    => $error->getLine(),
				'callback'      => $callback_name,
			)
		);
	}

	/**
	 * Map the Site Health status to one of the response buckets.
	 *
	 * @param string $status Site Health status string.
	 * @return string|null
	 */
	protected function map_status_to_bucket( $status ) {
		switch ( strtolower( (string) $status ) ) {
			case 'critical':
				return 'critical';
			case 'recommended':
				return 'warning';
			case 'good':
				return 'pass';
		}

		return null;
	}

	/**
	 * Normalise and sanitise a test result array for responses.
	 *
	 * @param string $identifier Test identifier.
	 * @param array  $result     Raw Site Health result.
	 * @return array<string, mixed>
	 */
	protected function format_test_result( $identifier, array $result ) {
		$label       = isset( $result['label'] ) ? $this->normalise_text( $result['label'] ) : '';
		$description = isset( $result['description'] ) ? $this->normalise_text( $result['description'] ) : '';
		$actions     = isset( $result['actions'] ) ? $this->normalise_text( $result['actions'] ) : '';
		$links       = isset( $result['actions'] ) ? $this->extract_links( $result['actions'] ) : array();

		$formatted = array(
			'test'           => sanitize_key( $identifier ),
			'status'         => isset( $result['status'] ) ? sanitize_key( $result['status'] ) : '',
			'label'          => $label,
			'description'    => $description,
			'recommendation' => array(
				'summary' => $actions,
				'links'   => $links,
			),
		);

		if ( ! empty( $result['badge'] ) && is_array( $result['badge'] ) ) {
			$formatted['badge'] = array(
				'label' => isset( $result['badge']['label'] ) ? $this->normalise_text( $result['badge']['label'] ) : '',
				'color' => isset( $result['badge']['color'] ) ? sanitize_text_field( $result['badge']['color'] ) : '',
			);
		}

		if ( isset( $result['fields'] ) && is_array( $result['fields'] ) ) {
			$formatted['fields'] = $this->normalise_fields( $result['fields'] );
		}

		return $formatted;
	}

	/**
	 * Sanitise Site Health fields output when available.
	 *
	 * @param array $fields Raw fields data.
	 * @return array
	 */
	protected function normalise_fields( array $fields ) {
		$sanitised = array();

		foreach ( $fields as $field ) {
			if ( empty( $field['label'] ) ) {
				continue;
			}

			$sanitised[] = array(
				'label' => $this->normalise_text( $field['label'] ),
				'value' => isset( $field['value'] ) ? $this->normalise_text( $field['value'] ) : '',
			);
		}

		return $sanitised;
	}

	/**
	 * Convert HTML to a trimmed plain-text string with normalised whitespace.
	 *
	 * @param string $value Raw HTML string.
	 * @return string
	 */
	protected function normalise_text( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Extract anchor tags from the provided HTML snippet.
	 *
	 * @param string $html HTML string potentially containing anchor elements.
	 * @return array<int, array<string, string>>
	 */
	protected function extract_links( $html ) {
		$links = array();

		if ( empty( $html ) ) {
			return $links;
		}

		if ( class_exists( 'DOMDocument', false ) ) {
			$dom                    = new DOMDocument();
			$previous_error_setting = libxml_use_internal_errors( true );
			$loaded                 = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

			if ( $loaded ) {
				foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
					$href = $anchor->getAttribute( 'href' );

					if ( empty( $href ) ) {
						continue;
					}

					$links[] = array(
						'url'   => esc_url_raw( $href ),
						'label' => $this->normalise_text( $anchor->textContent ),
					);
				}
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $previous_error_setting );

			return $links;
		}

		if ( preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$links[] = array(
					'url'   => esc_url_raw( $match[1] ),
					'label' => $this->normalise_text( $match[2] ),
				);
			}
		}

		return $links;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
