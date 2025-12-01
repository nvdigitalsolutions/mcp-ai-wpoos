<?php
/**
 * Tool for creating complete WordPress sites from a plan.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Site Creator Tool
 *
 * Orchestrates the creation of a complete WordPress site based on a provided plan.
 * This tool delegates to other tools to perform tasks like creating content,
 * installing plugins, and configuring settings.
 */
class WP_MCP_AI_Tool_Site_Creator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'site_creator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Site Creator', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a complete WordPress site from a plan. The plan can include site options, plugins to install, themes to activate, and content to create (pages, posts).', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'plan' => array(
					'type'        => 'object',
					'description' => __( 'A JSON object detailing the site structure. Should include keys for "options", "theme", "plugins", and "content".', 'wp-mcp-ai' ),
					'properties'  => array(
						'options' => array(
							'type'        => 'object',
							'description' => __( 'Site options to update (e.g., {"blogname": "My Site", "blogdescription": "A great site"}).', 'wp-mcp-ai' ),
						),
						'theme'   => array(
							'type'        => 'string',
							'description' => __( 'Theme slug to install and activate (e.g., "astra").', 'wp-mcp-ai' ),
						),
						'plugins' => array(
							'type'        => 'array',
							'description' => __( 'Array of plugin slugs to install and activate.', 'wp-mcp-ai' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'content' => array(
							'type'        => 'array',
							'description' => __( 'Array of content items (pages, posts) to create.', 'wp-mcp-ai' ),
							'items'       => array(
								'type' => 'object',
							),
						),
					),
				),
			),
			'required'             => array( 'plan' ),
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
		// Check if site creator features are enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The site_creator tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create a site.', 'wp-mcp-ai' )
			);
		}

		$plan = isset( $arguments['plan'] ) ? $arguments['plan'] : null;

		if ( ! is_object( $plan ) && ! is_array( $plan ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_plan',
				__( 'Invalid plan provided. The plan must be a JSON object.', 'wp-mcp-ai' )
			);
		}

		$plan = (array) $plan;

		// Get the tool registry instance (using dependency injection pattern).
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$results = array(
			'options' => array(),
			'theme'   => null,
			'plugins' => array(),
			'content' => array(),
			'errors'  => array(),
		);

		// Step 1: Update site options.
		if ( ! empty( $plan['options'] ) && is_array( $plan['options'] ) ) {
			$results['options'] = $this->process_options( $plan['options'], $registry, $context );
		}

		// Step 2: Install and activate theme.
		if ( ! empty( $plan['theme'] ) ) {
			$results['theme'] = $this->process_theme( $plan['theme'], $registry, $context );
		}

		// Step 3: Install and activate plugins.
		if ( ! empty( $plan['plugins'] ) && is_array( $plan['plugins'] ) ) {
			$results['plugins'] = $this->process_plugins( $plan['plugins'], $registry, $context );
		}

		// Step 4: Create content (pages, posts).
		if ( ! empty( $plan['content'] ) && is_array( $plan['content'] ) ) {
			$results['content'] = $this->process_content( $plan['content'], $registry, $context );
		}

		// Collect all errors for reporting.
		$this->collect_errors( $results );

		return array(
			'success' => empty( $results['errors'] ),
			'results' => $results,
			'summary' => $this->generate_summary( $results ),
		);
	}

	/**
	 * Process site options.
	 *
	 * @param array                   $options  Options to update.
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 * @param array                   $context  Execution context.
	 * @return array Results of option updates.
	 */
	private function process_options( $options, $registry, $context ) {
		$results = array();

		foreach ( $options as $option_name => $option_value ) {
			$result = $registry->execute_tool(
				'update_option',
				array(
					'option_name'  => $option_name,
					'option_value' => $option_value,
				),
				$context
			);

			$results[ $option_name ] = $result;
		}

		return $results;
	}

	/**
	 * Process theme installation and activation.
	 *
	 * @param string                  $theme_slug Theme slug.
	 * @param WP_MCP_AI_Tool_Registry $registry   Tool registry instance.
	 * @param array                   $context    Execution context.
	 * @return array|WP_Error Result of theme installation.
	 */
	private function process_theme( $theme_slug, $registry, $context ) {
		return $registry->execute_tool(
			'install_and_activate_theme',
			array( 'slug' => $theme_slug ),
			$context
		);
	}

	/**
	 * Process plugin installations and activations.
	 *
	 * @param array                   $plugins  Plugin slugs to install.
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 * @param array                   $context  Execution context.
	 * @return array Results of plugin installations.
	 */
	private function process_plugins( $plugins, $registry, $context ) {
		$results = array();

		foreach ( $plugins as $plugin_slug ) {
			$result = $registry->execute_tool(
				'install_and_activate_plugin',
				array( 'slug' => $plugin_slug ),
				$context
			);

			$results[ $plugin_slug ] = $result;
		}

		return $results;
	}

	/**
	 * Process content creation.
	 *
	 * @param array                   $content_items Content items to create.
	 * @param WP_MCP_AI_Tool_Registry $registry      Tool registry instance.
	 * @param array                   $context       Execution context.
	 * @return array Results of content creation.
	 */
	private function process_content( $content_items, $registry, $context ) {
		$results = array();

		foreach ( $content_items as $index => $content_item ) {
			$content_item = (array) $content_item;

			$result = $registry->execute_tool(
				'save_post',
				$content_item,
				$context
			);

			$results[ $index ] = $result;
		}

		return $results;
	}

	/**
	 * Collect all errors from results.
	 *
	 * @param array $results Results array to scan for errors.
	 */
	private function collect_errors( &$results ) {
		// Check options for errors.
		if ( ! empty( $results['options'] ) ) {
			foreach ( $results['options'] as $option_name => $result ) {
				if ( is_wp_error( $result ) ) {
					$results['errors'][] = array(
						'type'    => 'option',
						'item'    => $option_name,
						'message' => $result->get_error_message(),
					);
				}
			}
		}

		// Check theme for errors.
		if ( is_wp_error( $results['theme'] ) ) {
			$results['errors'][] = array(
				'type'    => 'theme',
				'message' => $results['theme']->get_error_message(),
			);
		}

		// Check plugins for errors.
		if ( ! empty( $results['plugins'] ) ) {
			foreach ( $results['plugins'] as $plugin_slug => $result ) {
				if ( is_wp_error( $result ) ) {
					$results['errors'][] = array(
						'type'    => 'plugin',
						'item'    => $plugin_slug,
						'message' => $result->get_error_message(),
					);
				}
			}
		}

		// Check content for errors.
		if ( ! empty( $results['content'] ) ) {
			foreach ( $results['content'] as $index => $result ) {
				if ( is_wp_error( $result ) ) {
					$results['errors'][] = array(
						'type'    => 'content',
						'item'    => $index,
						'message' => $result->get_error_message(),
					);
				}
			}
		}
	}

	/**
	 * Generate a summary of the site creation process.
	 *
	 * @param array $results Results of site creation.
	 * @return string Summary message.
	 */
	private function generate_summary( $results ) {
		$summary_parts = array();

		// Count successful operations.
		$options_updated = 0;
		if ( ! empty( $results['options'] ) ) {
			foreach ( $results['options'] as $result ) {
				if ( ! is_wp_error( $result ) ) {
					++$options_updated;
				}
			}
		}

		$plugins_installed = 0;
		if ( ! empty( $results['plugins'] ) ) {
			foreach ( $results['plugins'] as $result ) {
				if ( ! is_wp_error( $result ) ) {
					++$plugins_installed;
				}
			}
		}

		$content_created = 0;
		if ( ! empty( $results['content'] ) ) {
			foreach ( $results['content'] as $result ) {
				if ( ! is_wp_error( $result ) ) {
					++$content_created;
				}
			}
		}

		// Build summary message.
		if ( $options_updated > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of options */
				_n( '%d option updated', '%d options updated', $options_updated, 'wp-mcp-ai' ),
				$options_updated
			);
		}

		if ( ! is_wp_error( $results['theme'] ) && ! empty( $results['theme'] ) ) {
			$summary_parts[] = __( 'theme activated', 'wp-mcp-ai' );
		}

		if ( $plugins_installed > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of plugins */
				_n( '%d plugin installed', '%d plugins installed', $plugins_installed, 'wp-mcp-ai' ),
				$plugins_installed
			);
		}

		if ( $content_created > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of content items */
				_n( '%d content item created', '%d content items created', $content_created, 'wp-mcp-ai' ),
				$content_created
			);
		}

		if ( ! empty( $results['errors'] ) ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of errors */
				_n( '%d error', '%d errors', count( $results['errors'] ), 'wp-mcp-ai' ),
				count( $results['errors'] )
			);
		}

		if ( empty( $summary_parts ) ) {
			return __( 'No changes made.', 'wp-mcp-ai' );
		}

		return implode( ', ', $summary_parts ) . '.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates and modifies data.
			'external-api',         // May call external APIs via delegated tools.
			'network-dependent',    // Requires internet for plugin/theme installation.
			'requires-capability',  // Requires manage_options capability.
			'state-changing',       // Modifies site state extensively.
			'async',                // May take significant time.
			'long-running',         // Could take several minutes for complex sites.
			'performance-impact',   // May temporarily affect site performance.
			'non-deterministic',    // Results vary based on external factors.
		);
	}
}
