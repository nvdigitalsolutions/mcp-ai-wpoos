<?php
/**
 * Example: Using Model Research and Configuration Tools
 *
 * This example demonstrates how to use the three model research tools
 * to discover, research, and add new AI models to the plugin.
 *
 * @package WP_MCP_AI
 */

// Example 1: Research a Specific Model
// =====================================

/**
 * Research the GPT-4.5 Turbo model specifications.
 *
 * This would typically be called by an AI assistant when asked to
 * "research the new gpt-4.5-turbo model" or similar.
 */
function example_research_model() {
	$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
	$research_tool = $tool_registry->get_tool( 'research_model' );

	if ( ! $research_tool ) {
		return new WP_Error( 'tool_not_found', 'Research model tool not available' );
	}

	$result = $research_tool->execute(
		array(
			'model_id'       => 'gpt-4.5-turbo',
			'provider'       => 'openai',
			'use_web_search' => true,
		),
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'Error: ' . $result->get_error_message() . "\n";
		return $result;
	}

	echo "Successfully researched GPT-4.5 Turbo:\n";
	echo "- Name: {$result['name']}\n";
	echo "- Context Window: {$result['context_window']} tokens\n";
	echo "- Cost per 1K: \${$result['cost_per_1k']}\n";
	echo "- Status: {$result['status']}\n";
	echo "- Research Confidence: {$result['_research_metadata']['confidence']}%\n";

	return $result;
}

// Example 2: Add a Researched Model to Configuration
// ==================================================

/**
 * Add a researched model to the plugin configuration.
 *
 * This adds the model to wp_mcp_ai_model_configs so it can be
 * selected in assistants and used by the orchestration layer.
 */
function example_add_model_config( $researched_config ) {
	$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
	$add_tool      = $tool_registry->get_tool( 'add_model_config' );

	if ( ! $add_tool ) {
		return new WP_Error( 'tool_not_found', 'Add model config tool not available' );
	}

	$result = $add_tool->execute(
		array(
			'model_id'  => 'gpt-4.5-turbo',
			'config'    => $researched_config,
			'overwrite' => false, // Don't overwrite if it exists.
		),
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'Error: ' . $result->get_error_message() . "\n";
		return $result;
	}

	echo "Successfully added model:\n";
	echo "- Model ID: {$result['model_id']}\n";
	echo "- Action: {$result['action']}\n";
	echo "- Message: {$result['message']}\n";

	return $result;
}

// Example 3: Complete Workflow - Research and Add
// ==============================================

/**
 * Complete workflow: Research a model and add it to configuration.
 *
 * This is what an AI assistant would do when asked:
 * "Research and add the new gpt-4.5-turbo model"
 */
function example_research_and_add_model() {
	echo "Step 1: Researching model...\n";
	$research_result = example_research_model();

	if ( is_wp_error( $research_result ) ) {
		return $research_result;
	}

	// Check confidence before adding.
	$confidence = isset( $research_result['_research_metadata']['confidence'] )
		? $research_result['_research_metadata']['confidence']
		: 0;

	if ( $confidence < 70 ) {
		echo "\nWarning: Research confidence is low ({$confidence}%).\n";
		echo "Manual verification recommended before adding.\n";
		return new WP_Error( 'low_confidence', 'Research confidence too low to auto-add' );
	}

	echo "\nStep 2: Adding model to configuration...\n";
	$add_result = example_add_model_config( $research_result );

	if ( is_wp_error( $add_result ) ) {
		return $add_result;
	}

	echo "\nSuccess! GPT-4.5 Turbo is now available for use.\n";
	return $add_result;
}

// Example 4: Discover New Models from All Providers
// ================================================

/**
 * Discover new models from all configured providers.
 *
 * Useful for periodic checks to find newly released models.
 */
function example_discover_new_models() {
	$tool_registry   = WP_MCP_AI_Tool_Registry::get_instance();
	$discovery_tool = $tool_registry->get_tool( 'discover_new_models' );

	if ( ! $discovery_tool ) {
		return new WP_Error( 'tool_not_found', 'Discover models tool not available' );
	}

	echo "Discovering new models from all providers...\n\n";

	$result = $discovery_tool->execute(
		array(
			'providers'     => array(), // Empty = check all configured.
			'auto_research' => false,    // Don't auto-research (for faster results).
		),
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'Error: ' . $result->get_error_message() . "\n";
		return $result;
	}

	// Display results.
	echo "Discovery Results:\n";
	echo "==================\n\n";

	echo "Newly Discovered Models (" . count( $result['discovered'] ) . "):\n";
	foreach ( $result['discovered'] as $model ) {
		echo "  - {$model['model_id']} ({$model['provider']})\n";
	}

	echo "\nAlready Configured (" . count( $result['already_exists'] ) . "):\n";
	foreach ( $result['already_exists'] as $model ) {
		echo "  - {$model['model_id']} ({$model['provider']})\n";
	}

	if ( ! empty( $result['recommendations'] ) ) {
		echo "\nRecommendations:\n";
		foreach ( $result['recommendations'] as $rec ) {
			echo "  - {$rec['model_id']} ({$rec['provider']}) - Confidence: {$rec['confidence']}%\n";
		}
	}

	if ( ! empty( $result['errors'] ) ) {
		echo "\nErrors:\n";
		foreach ( $result['errors'] as $provider => $error ) {
			echo "  - {$provider}: {$error}\n";
		}
	}

	return $result;
}

// Example 5: Discover and Auto-Research
// ====================================

/**
 * Discover new models and automatically research them.
 *
 * This is more thorough but slower as it researches each new model.
 */
function example_discover_and_research() {
	$tool_registry   = WP_MCP_AI_Tool_Registry::get_instance();
	$discovery_tool = $tool_registry->get_tool( 'discover_new_models' );

	if ( ! $discovery_tool ) {
		return new WP_Error( 'tool_not_found', 'Discover models tool not available' );
	}

	echo "Discovering and researching new models (this may take a while)...\n\n";

	$result = $discovery_tool->execute(
		array(
			'providers'     => array( 'openai', 'gemini' ), // Specific providers.
			'auto_research' => true,                        // Auto-research each model.
		),
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'Error: ' . $result->get_error_message() . "\n";
		return $result;
	}

	// Process discovered models with research.
	foreach ( $result['discovered'] as $model ) {
		if ( isset( $model['research'] ) && ! is_wp_error( $model['research'] ) ) {
			$research = $model['research'];
			$confidence = $research['_research_metadata']['confidence'] ?? 0;

			echo "Model: {$model['model_id']}\n";
			echo "  - Confidence: {$confidence}%\n";
			echo "  - Context Window: {$research['context_window']} tokens\n";
			echo "  - Cost: \${$research['cost_per_1k']} per 1K\n\n";

			// Auto-add high-confidence models.
			if ( $confidence >= 80 ) {
				echo "  → Auto-adding (high confidence)...\n";
				$add_tool = $tool_registry->get_tool( 'add_model_config' );

				if ( $add_tool ) {
					$add_result = $add_tool->execute(
						array(
							'model_id' => $model['model_id'],
							'config'   => $research,
							'overwrite' => false,
						),
						array(
							'user_id' => get_current_user_id(),
						)
					);

					if ( is_wp_error( $add_result ) ) {
						echo "  ✗ Failed: {$add_result->get_error_message()}\n\n";
					} else {
						echo "  ✓ Added successfully!\n\n";
					}
				}
			}
		}
	}

	return $result;
}

// Example 6: Update Existing Model Configuration
// =============================================

/**
 * Update an existing model's configuration.
 *
 * Useful when pricing changes or rate limits are updated.
 */
function example_update_model_config() {
	$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
	$add_tool      = $tool_registry->get_tool( 'add_model_config' );

	if ( ! $add_tool ) {
		return new WP_Error( 'tool_not_found', 'Add model config tool not available' );
	}

	// Get existing config first.
	$existing = WP_MCP_AI_Model_Config::get_model_config( 'gpt-4o' );

	if ( ! $existing ) {
		return new WP_Error( 'model_not_found', 'Model not found in configuration' );
	}

	// Update the pricing.
	$existing['cost_per_1k'] = 0.0075; // New pricing.

	$result = $add_tool->execute(
		array(
			'model_id'  => 'gpt-4o',
			'config'    => $existing,
			'overwrite' => true, // Must overwrite to update.
		),
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'Error: ' . $result->get_error_message() . "\n";
		return $result;
	}

	echo "Successfully updated GPT-4o pricing to \${$existing['cost_per_1k']} per 1K tokens\n";
	return $result;
}

// Usage Examples
// =============

// Uncomment to run examples (must be in WordPress admin context with proper capabilities):

// Example 1: Research a single model.
// example_research_model();

// Example 2: Complete workflow - research and add.
// example_research_and_add_model();

// Example 3: Discover new models (fast, no research).
// example_discover_new_models();

// Example 4: Discover and research (slower, thorough).
// example_discover_and_research();

// Example 5: Update existing model.
// example_update_model_config();

/**
 * WP-CLI Command Example
 * ======================
 *
 * These tools can also be used via WP-CLI:
 *
 * # Research a model
 * wp mcp-ai tool execute research_model --model_id=gpt-4.5-turbo --provider=openai
 *
 * # Add a model (with JSON config)
 * wp mcp-ai tool execute add_model_config --model_id=gpt-4.5-turbo --config='{"name":"GPT-4.5 Turbo",...}'
 *
 * # Discover new models
 * wp mcp-ai tool execute discover_new_models --providers='["openai","gemini"]'
 */

/**
 * REST API Example
 * ================
 *
 * These tools are exposed via the REST API:
 *
 * POST /wp-json/mcp-ai/v1/tools
 * {
 *   "tool": "research_model",
 *   "arguments": {
 *     "model_id": "gpt-4.5-turbo",
 *     "provider": "openai"
 *   }
 * }
 *
 * Authentication required: Assistant credentials or WordPress nonce.
 */
