<?php
/**
 * Completion Provider - Argument and Tool Completion
 *
 * Provides completion suggestions for tools and arguments.
 * Proof of concept for modernization roadmap Phase 4.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Completion_Provider class
 *
 * Provides intelligent completion suggestions for MCP tools,
 * arguments, and prompts based on context.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Completion_Provider {

	/**
	 * Get tool completions
	 *
	 * @param string $filter Optional filter query.
	 * @param array  $context Optional context for filtering.
	 * @return array Array of tool completions.
	 */
	public function get_tool_completions( $filter = '', $context = array() ) {
		$registry    = wp_mcp_ai_get_tool_registry();
		$tools       = $registry->get_all_tools();
		$completions = array();

		foreach ( $tools as $slug => $tool_instance ) {
			$definition = $tool_instance->get_definition();

			// Filter by query string.
			if ( ! empty( $filter ) ) {
				$searchable = strtolower( $definition['name'] . ' ' . $definition['description'] );
				if ( false === strpos( $searchable, strtolower( $filter ) ) ) {
					continue;
				}
			}

			// Filter by category if specified.
			if ( ! empty( $context['category'] ) ) {
				$tool_category = $definition['category'] ?? 'general';
				if ( $tool_category !== $context['category'] ) {
					continue;
				}
			}

			// Check user capability if context provided.
			if ( ! empty( $context['user_id'] ) ) {
				$required_capability = $definition['required_capability'] ?? 'edit_posts';
				if ( ! user_can( $context['user_id'], $required_capability ) ) {
					continue;
				}
			}

			$completions[] = array(
				'value'       => $slug,
				'label'       => $definition['name'],
				'description' => $definition['description'] ?? '',
				'category'    => $definition['category'] ?? 'general',
				'capability'  => $definition['required_capability'] ?? 'edit_posts',
				'score'       => $this->calculate_relevance_score( $slug, $definition, $filter ),
			);
		}

		// Sort by relevance score.
		usort(
			$completions,
			function( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $completions;
	}

	/**
	 * Get argument completions for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param string $argument_name Argument name.
	 * @param array  $context Current context (partial arguments, etc).
	 * @return array Array of argument completions.
	 */
	public function get_argument_completions( $tool_slug, $argument_name, $context = array() ) {
		$registry = wp_mcp_ai_get_tool_registry();
		$tool     = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return array();
		}

		// Allow tools to provide custom completions.
		if ( method_exists( $tool, 'get_argument_completions' ) ) {
			return $tool->get_argument_completions( $argument_name, $context );
		}

		// Generate basic completions from schema.
		return $this->generate_completions_from_schema( $tool, $argument_name, $context );
	}

	/**
	 * Generate completions from tool schema
	 *
	 * @param object $tool Tool instance.
	 * @param string $argument_name Argument name.
	 * @param array  $context Context data.
	 * @return array Completions array.
	 */
	private function generate_completions_from_schema( $tool, $argument_name, $context = array() ) {
		$definition = $tool->get_definition();
		$parameters = $definition['parameters'] ?? array();
		$properties = $parameters['properties'] ?? array();

		if ( ! isset( $properties[ $argument_name ] ) ) {
			return array();
		}

		$property    = $properties[ $argument_name ];
		$completions = array();

		// Handle enum values.
		if ( isset( $property['enum'] ) && is_array( $property['enum'] ) ) {
			foreach ( $property['enum'] as $value ) {
				$completions[] = array(
					'value'       => $value,
					'label'       => ucfirst( str_replace( '_', ' ', $value ) ),
					'description' => '',
					'type'        => 'enum',
				);
			}
			return $completions;
		}

		// Handle special argument types.
		switch ( $argument_name ) {
			case 'post_type':
				return $this->get_post_type_completions( $context );

			case 'taxonomy':
				return $this->get_taxonomy_completions( $context );

			case 'user_id':
				return $this->get_user_completions( $context );

			case 'capability':
				return $this->get_capability_completions( $context );

			case 'status':
			case 'post_status':
				return $this->get_post_status_completions( $context );
		}

		// Handle boolean.
		if ( isset( $property['type'] ) && 'boolean' === $property['type'] ) {
			return array(
				array(
					'value'       => true,
					'label'       => 'True',
					'description' => '',
					'type'        => 'boolean',
				),
				array(
					'value'       => false,
					'label'       => 'False',
					'description' => '',
					'type'        => 'boolean',
				),
			);
		}

		return $completions;
	}

	/**
	 * Get post type completions
	 *
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	private function get_post_type_completions( $context = array() ) {
		$post_types  = get_post_types( array( 'public' => true ), 'objects' );
		$completions = array();

		foreach ( $post_types as $post_type ) {
			$completions[] = array(
				'value'       => $post_type->name,
				'label'       => $post_type->label,
				'description' => $post_type->description ?? '',
				'type'        => 'post_type',
			);
		}

		return $completions;
	}

	/**
	 * Get taxonomy completions
	 *
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	private function get_taxonomy_completions( $context = array() ) {
		$taxonomies  = get_taxonomies( array( 'public' => true ), 'objects' );
		$completions = array();

		foreach ( $taxonomies as $taxonomy ) {
			$completions[] = array(
				'value'       => $taxonomy->name,
				'label'       => $taxonomy->label,
				'description' => $taxonomy->description ?? '',
				'type'        => 'taxonomy',
			);
		}

		return $completions;
	}

	/**
	 * Get user completions
	 *
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	private function get_user_completions( $context = array() ) {
		$users       = get_users( array( 'number' => 20 ) );
		$completions = array();

		foreach ( $users as $user ) {
			$completions[] = array(
				'value'       => $user->ID,
				'label'       => $user->display_name,
				'description' => sprintf( '%s (%s)', $user->user_email, $user->user_login ),
				'type'        => 'user',
			);
		}

		return $completions;
	}

	/**
	 * Get capability completions
	 *
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	private function get_capability_completions( $context = array() ) {
		$capabilities = array(
			'read',
			'edit_posts',
			'publish_posts',
			'edit_pages',
			'publish_pages',
			'manage_options',
			'edit_users',
			'delete_users',
		);

		$completions = array();
		foreach ( $capabilities as $cap ) {
			$completions[] = array(
				'value'       => $cap,
				'label'       => ucwords( str_replace( '_', ' ', $cap ) ),
				'description' => '',
				'type'        => 'capability',
			);
		}

		return $completions;
	}

	/**
	 * Get post status completions
	 *
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	private function get_post_status_completions( $context = array() ) {
		$statuses    = get_post_stati( array(), 'objects' );
		$completions = array();

		foreach ( $statuses as $status ) {
			$completions[] = array(
				'value'       => $status->name,
				'label'       => $status->label,
				'description' => '',
				'type'        => 'post_status',
			);
		}

		return $completions;
	}

	/**
	 * Calculate relevance score for sorting
	 *
	 * @param string $slug Tool slug.
	 * @param array  $definition Tool definition.
	 * @param string $filter Search filter.
	 * @return float Relevance score (0-1).
	 */
	private function calculate_relevance_score( $slug, $definition, $filter ) {
		if ( empty( $filter ) ) {
			return 1.0;
		}

		$filter = strtolower( $filter );
		$name   = strtolower( $definition['name'] ?? '' );
		$desc   = strtolower( $definition['description'] ?? '' );

		$score = 0.0;

		// Exact match in slug.
		if ( $slug === $filter ) {
			$score += 1.0;
		}

		// Starts with filter in name.
		if ( 0 === strpos( $name, $filter ) ) {
			$score += 0.8;
		}

		// Contains filter in name.
		if ( false !== strpos( $name, $filter ) ) {
			$score += 0.5;
		}

		// Contains filter in description.
		if ( false !== strpos( $desc, $filter ) ) {
			$score += 0.3;
		}

		return $score;
	}

	/**
	 * Get prompt completions
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $context Context data.
	 * @return array Completions array.
	 */
	public function get_prompt_completions( $assistant_id = 0, $context = array() ) {
		$completions = array();

		if ( $assistant_id > 0 ) {
			$shortcuts = get_post_meta( $assistant_id, 'prompt_shortcuts', true );

			if ( is_array( $shortcuts ) ) {
				foreach ( $shortcuts as $shortcut ) {
					$completions[] = array(
						'value'       => $shortcut['prompt'] ?? '',
						'label'       => $shortcut['label'] ?? '',
						'description' => $shortcut['description'] ?? '',
						'tool'        => $shortcut['tool'] ?? '',
						'type'        => 'custom_prompt',
					);
				}
			}
		}

		// Add default prompts from tools.
		$registry = wp_mcp_ai_get_tool_registry();
		foreach ( $registry->get_all_tools() as $slug => $tool_instance ) {
			if ( method_exists( $tool_instance, 'get_default_prompts' ) ) {
				$tool_prompts = $tool_instance->get_default_prompts();
				foreach ( $tool_prompts as $prompt ) {
					$completions[] = array(
						'value'       => $prompt['text'] ?? '',
						'label'       => $prompt['label'] ?? '',
						'description' => $prompt['description'] ?? '',
						'tool'        => $slug,
						'type'        => 'tool_prompt',
					);
				}
			}
		}

		return $completions;
	}
}
