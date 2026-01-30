<?php
/**
 * Tool for creating complete WordPress sites from a plan.
 *
 * @package WP_MCP_AI_Pro
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
class WP_MCP_AI_Pro_Tool_Site_Creator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

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
		return __( 'Site Creator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a complete WordPress site from a plan following 2025 best practices. The plan can include site options, plugins to install, themes to activate (with theme.json support), and content to create (pages, posts).', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'A JSON object detailing the site structure. Should include keys for "options", "theme", "plugins", and "content".', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'options' => array(
							'type'        => 'object',
							'description' => __( 'Site options to update (e.g., {"blogname": "My Site", "blogdescription": "A great site"}).', 'mcp-ai-wpoos-pro' ),
						),
						'theme'   => array(
							'type'        => 'object',
							'description' => __( 'Theme configuration including slug, type, and optional theme.json settings.', 'mcp-ai-wpoos-pro' ),
							'properties'  => array(
								'slug'             => array(
									'type'        => 'string',
									'description' => __( 'Theme slug to install and activate (e.g., "astra").', 'mcp-ai-wpoos-pro' ),
								),
								'theme_json'       => array(
									'type'        => 'object',
									'description' => __( 'Optional theme.json configuration for block themes.', 'mcp-ai-wpoos-pro' ),
								),
								'industry'         => array(
									'type'        => 'string',
									'description' => __( 'Industry for color palette (technology, healthcare, finance, ecommerce).', 'mcp-ai-wpoos-pro' ),
								),
								'custom_templates' => array(
									'type'        => 'array',
									'description' => __( 'Custom page templates.', 'mcp-ai-wpoos-pro' ),
									'items'       => array(
										'type' => 'object',
									),
								),
							),
						),
						'plugins' => array(
							'type'        => 'array',
							'description' => __( 'Array of plugin slugs to install and activate.', 'mcp-ai-wpoos-pro' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'content' => array(
							'type'        => 'array',
							'description' => __( 'Array of content items (pages, posts) to create.', 'mcp-ai-wpoos-pro' ),
							'items'       => array(
								'type' => 'object',
							),
						),
						'menus'   => array(
							'type'        => 'array',
							'description' => __( 'Navigation menus to create.', 'mcp-ai-wpoos-pro' ),
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
				__( 'The site_creator tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create a site.', 'mcp-ai-wpoos-pro' )
			);
		}

		$plan = isset( $arguments['plan'] ) ? $arguments['plan'] : null;

		if ( ! is_object( $plan ) && ! is_array( $plan ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_plan',
				__( 'Invalid plan provided. The plan must be a JSON object.', 'mcp-ai-wpoos-pro' )
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
			'menus'   => array(),
			'errors'  => array(),
		);

		// Step 1: Update site options.
		if ( ! empty( $plan['options'] ) && is_array( $plan['options'] ) ) {
			$results['options'] = $this->process_options( $plan['options'], $registry, $context );
		}

		// Step 2: Install and activate theme with theme.json support.
		if ( ! empty( $plan['theme'] ) ) {
			$results['theme'] = $this->process_theme_enhanced( $plan['theme'], $registry, $context );
		}

		// Step 3: Install and activate plugins.
		if ( ! empty( $plan['plugins'] ) && is_array( $plan['plugins'] ) ) {
			$results['plugins'] = $this->process_plugins( $plan['plugins'], $registry, $context );
		}

		// Step 4: Create content (pages, posts).
		if ( ! empty( $plan['content'] ) && is_array( $plan['content'] ) ) {
			$results['content'] = $this->process_content( $plan['content'], $registry, $context );
		}

		// Step 5: Create navigation menus.
		if ( ! empty( $plan['menus'] ) && is_array( $plan['menus'] ) ) {
			$results['menus'] = $this->process_menus( $plan['menus'], $registry, $context );
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
	 * Process theme installation and activation (legacy method for backward compatibility).
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
	 * Process enhanced theme installation with theme.json support.
	 *
	 * @since 1.3.0
	 *
	 * @param array|string            $theme_config Theme configuration (slug or object).
	 * @param WP_MCP_AI_Tool_Registry $registry     Tool registry instance.
	 * @param array                   $context      Execution context.
	 * @return array|WP_Error Result of theme installation.
	 */
	private function process_theme_enhanced( $theme_config, $registry, $context ) {
		// Support legacy string format.
		if ( is_string( $theme_config ) ) {
			return $this->process_theme( $theme_config, $registry, $context );
		}

		$theme_config = (array) $theme_config;

		// Install and activate the theme.
		$theme_slug = isset( $theme_config['slug'] ) ? sanitize_text_field( $theme_config['slug'] ) : '';
		if ( empty( $theme_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_theme_slug', __( 'Theme slug is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$install_result = $registry->execute_tool(
			'install_and_activate_theme',
			array( 'slug' => $theme_slug ),
			$context
		);

		if ( is_wp_error( $install_result ) ) {
			return $install_result;
		}

		// If theme.json configuration is provided, generate and save it.
		if ( ! empty( $theme_config['theme_json'] ) || ! empty( $theme_config['industry'] ) || ! empty( $theme_config['custom_templates'] ) ) {
			// Load theme.json generator.
			if ( ! class_exists( 'WP_MCP_AI_Theme_JSON_Generator' ) ) {
				$helper_path = dirname( __DIR__ ) . '/helpers/class-wp-mcp-ai-theme-json-generator.php';
				if ( file_exists( $helper_path ) ) {
					require_once $helper_path;
				}
			}

			if ( class_exists( 'WP_MCP_AI_Theme_JSON_Generator' ) ) {
				$theme_json_args = array(
					'theme_name' => $theme_slug,
					'theme_type' => 'block',
				);

				// Add industry-specific color palette.
				if ( ! empty( $theme_config['industry'] ) ) {
					$theme_json_args['color_palette'] = WP_MCP_AI_Theme_JSON_Generator::get_industry_color_palette(
						sanitize_key( $theme_config['industry'] )
					);
				}

				// Add custom templates.
				if ( ! empty( $theme_config['custom_templates'] ) && is_array( $theme_config['custom_templates'] ) ) {
					$theme_json_args['custom_templates'] = $theme_config['custom_templates'];
				}

				// Generate theme.json.
				$theme_json_data = WP_MCP_AI_Theme_JSON_Generator::generate( $theme_json_args );

				// Store theme.json data in result.
				$install_result['theme_json_generated'] = true;
				$install_result['theme_json_data']      = WP_MCP_AI_Theme_JSON_Generator::to_json( $theme_json_data, true );
			}
		}

		return $install_result;
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
	 * Process navigation menu creation.
	 *
	 * @since 1.3.0
	 *
	 * @param array                   $menus    Menu configurations.
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 * @param array                   $context  Execution context.
	 * @return array Results of menu creation.
	 */
	private function process_menus( $menus, $registry, $context ) {
		$results = array();

		foreach ( $menus as $index => $menu_config ) {
			$menu_config = (array) $menu_config;

			// Create the menu if it has a name.
			if ( ! empty( $menu_config['name'] ) ) {
				$menu_name = sanitize_text_field( $menu_config['name'] );
				$menu_id   = wp_create_nav_menu( $menu_name );

				if ( is_wp_error( $menu_id ) ) {
					$results[ $index ] = $menu_id;
					continue;
				}

				// Add menu items if provided.
				if ( ! empty( $menu_config['items'] ) && is_array( $menu_config['items'] ) ) {
					foreach ( $menu_config['items'] as $item_index => $item ) {
						$item = (array) $item;

						$item_args = array(
							'menu-item-title'  => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
							'menu-item-url'    => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
							'menu-item-status' => 'publish',
						);

						if ( ! empty( $item['object_id'] ) ) {
							$item_args['menu-item-object-id'] = absint( $item['object_id'] );
						}

						if ( ! empty( $item['type'] ) ) {
							$item_args['menu-item-type'] = sanitize_key( $item['type'] );
						}

						if ( ! empty( $item['object'] ) ) {
							$item_args['menu-item-object'] = sanitize_key( $item['object'] );
						}

						if ( ! empty( $item['parent'] ) ) {
							$item_args['menu-item-parent-id'] = absint( $item['parent'] );
						}

						wp_update_nav_menu_item( $menu_id, 0, $item_args );
					}
				}

				// Assign menu to location if specified.
				if ( ! empty( $menu_config['location'] ) ) {
					$locations = get_theme_mod( 'nav_menu_locations', array() );
					$locations[ sanitize_key( $menu_config['location'] ) ] = $menu_id;
					set_theme_mod( 'nav_menu_locations', $locations );
				}

				$results[ $index ] = array(
					'success' => true,
					'menu_id' => $menu_id,
					'name'    => $menu_name,
				);
			} else {
				$results[ $index ] = new WP_Error(
					'wp_mcp_ai_missing_menu_name',
					__( 'Menu name is required.', 'mcp-ai-wpoos-pro' )
				);
			}
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

		// Check menus for errors.
		if ( ! empty( $results['menus'] ) ) {
			foreach ( $results['menus'] as $index => $result ) {
				if ( is_wp_error( $result ) ) {
					$results['errors'][] = array(
						'type'    => 'menu',
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

		$menus_created = 0;
		if ( ! empty( $results['menus'] ) ) {
			foreach ( $results['menus'] as $result ) {
				if ( ! is_wp_error( $result ) ) {
					++$menus_created;
				}
			}
		}

		// Build summary message.
		if ( $options_updated > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of options */
				_n( '%d option updated', '%d options updated', $options_updated, 'mcp-ai-wpoos-pro' ),
				$options_updated
			);
		}

		if ( ! is_wp_error( $results['theme'] ) && ! empty( $results['theme'] ) ) {
			$theme_msg = __( 'theme activated', 'mcp-ai-wpoos-pro' );
			// Add note about theme.json if generated.
			if ( is_array( $results['theme'] ) && ! empty( $results['theme']['theme_json_generated'] ) ) {
				$theme_msg .= __( ' with theme.json', 'mcp-ai-wpoos-pro' );
			}
			$summary_parts[] = $theme_msg;
		}

		if ( $plugins_installed > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of plugins */
				_n( '%d plugin installed', '%d plugins installed', $plugins_installed, 'mcp-ai-wpoos-pro' ),
				$plugins_installed
			);
		}

		if ( $content_created > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of content items */
				_n( '%d content item created', '%d content items created', $content_created, 'mcp-ai-wpoos-pro' ),
				$content_created
			);
		}

		if ( $menus_created > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of menus */
				_n( '%d menu created', '%d menus created', $menus_created, 'mcp-ai-wpoos-pro' ),
				$menus_created
			);
		}

		if ( ! empty( $results['errors'] ) ) {
			$summary_parts[] = sprintf(
				/* translators: %d: number of errors */
				_n( '%d error', '%d errors', count( $results['errors'] ), 'mcp-ai-wpoos-pro' ),
				count( $results['errors'] )
			);
		}

		if ( empty( $summary_parts ) ) {
			return __( 'No changes made.', 'mcp-ai-wpoos-pro' );
		}

		return implode( ', ', $summary_parts ) . '.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
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
