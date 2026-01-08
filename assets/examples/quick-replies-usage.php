<?php
/**
 * Quick Replies (Interactive Buttons) Usage Examples
 *
 * This file demonstrates how to use the interactive buttons (quick replies) feature
 * to add suggested action buttons to assistant responses.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example 1: Simple Yes/No Quick Replies
 *
 * Add Yes/No buttons to help users quickly respond to questions.
 */
function wp_mcp_ai_example_yesno_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Only add quick replies if the assistant's last message contains a question
	if ( isset( $response['choices'][0]['message']['content'] ) ) {
		$content = $response['choices'][0]['message']['content'];
		
		// Simple detection: if message ends with "?" add yes/no options
		if ( substr( trim( $content ), -1 ) === '?' ) {
			return array(
				array(
					'label' => 'Yes',
					'value' => 'Yes, please continue.',
				),
				array(
					'label' => 'No',
					'value' => 'No, thank you.',
				),
			);
		}
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_yesno_quick_replies', 10, 6 );

/**
 * Example 2: Multiple Choice Quick Replies
 *
 * Provide A/B/C/D options for more complex decision making.
 */
function wp_mcp_ai_example_multiple_choice_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Check if the message contains "choose" or "select"
	if ( isset( $response['choices'][0]['message']['content'] ) ) {
		$content = strtolower( $response['choices'][0]['message']['content'] );
		
		if ( strpos( $content, 'choose' ) !== false || strpos( $content, 'select' ) !== false ) {
			return array(
				array(
					'label' => 'Option A',
					'value' => 'I choose option A',
				),
				array(
					'label' => 'Option B',
					'value' => 'I choose option B',
				),
				array(
					'label' => 'Option C',
					'value' => 'I choose option C',
				),
				array(
					'label' => 'Tell me more',
					'value' => 'Can you provide more information about these options?',
				),
			);
		}
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_multiple_choice_quick_replies', 10, 6 );

/**
 * Example 3: Context-Aware Quick Replies
 *
 * Show different quick replies based on conversation context.
 */
function wp_mcp_ai_example_contextual_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Count user messages to determine conversation stage
	$user_message_count = 0;
	if ( is_array( $messages ) ) {
		foreach ( $messages as $message ) {
			if ( isset( $message['role'] ) && 'user' === $message['role'] ) {
				++$user_message_count;
			}
		}
	}
	
	// First interaction - offer help or start
	if ( $user_message_count <= 1 ) {
		return array(
			array(
				'label' => 'Get Started',
				'value' => 'I\'d like to get started.',
			),
			array(
				'label' => 'Learn More',
				'value' => 'Tell me more about what you can do.',
			),
			array(
				'label' => 'Help',
				'value' => 'I need help.',
			),
		);
	}
	
	// Later interactions - offer common actions
	if ( $user_message_count >= 3 ) {
		return array(
			array(
				'label' => 'Continue',
				'value' => 'Please continue.',
			),
			array(
				'label' => 'Start Over',
				'value' => 'Let\'s start over from the beginning.',
			),
			array(
				'label' => 'Done',
				'value' => 'I\'m done, thank you!',
			),
		);
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_contextual_quick_replies', 10, 6 );

/**
 * Example 4: Assistant-Specific Quick Replies
 *
 * Show different quick replies for different assistants.
 */
function wp_mcp_ai_example_assistant_specific_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Check assistant configuration for a custom prompt or instruction
	if ( ! empty( $assistant_config['system_prompt'] ) ) {
		$system_prompt = strtolower( $assistant_config['system_prompt'] );
		
		// Customer support assistant
		if ( strpos( $system_prompt, 'support' ) !== false || strpos( $system_prompt, 'help' ) !== false ) {
			return array(
				array(
					'label' => 'Yes, solved',
					'value' => 'Yes, my issue is resolved. Thank you!',
				),
				array(
					'label' => 'Need more help',
					'value' => 'I still need assistance with this.',
				),
				array(
					'label' => 'Talk to human',
					'value' => 'I\'d like to speak with a human agent.',
				),
			);
		}
		
		// Sales assistant
		if ( strpos( $system_prompt, 'sales' ) !== false || strpos( $system_prompt, 'product' ) !== false ) {
			return array(
				array(
					'label' => 'View Products',
					'value' => 'Show me your products.',
				),
				array(
					'label' => 'Get Pricing',
					'value' => 'I\'d like to see pricing information.',
				),
				array(
					'label' => 'Contact Sales',
					'value' => 'I want to speak with your sales team.',
				),
			);
		}
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_assistant_specific_quick_replies', 10, 6 );

/**
 * Example 5: Tool-Based Quick Replies
 *
 * Show quick replies based on tools that were executed.
 */
function wp_mcp_ai_example_tool_based_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Check if the response has tool calls
	if ( isset( $response['choices'][0]['message']['tool_calls'] ) && ! empty( $response['choices'][0]['message']['tool_calls'] ) ) {
		$tool_calls = $response['choices'][0]['message']['tool_calls'];
		
		// Check for specific tool types
		foreach ( $tool_calls as $tool_call ) {
			if ( ! isset( $tool_call['function']['name'] ) ) {
				continue;
			}
			
			$tool_name = $tool_call['function']['name'];
			
			// Image generation tool
			if ( strpos( $tool_name, 'image' ) !== false || strpos( $tool_name, 'dalle' ) !== false ) {
				return array(
					array(
						'label' => 'Generate Another',
						'value' => 'Generate another image with similar style.',
					),
					array(
						'label' => 'Modify This',
						'value' => 'Can you modify this image?',
					),
					array(
						'label' => 'Looks Great',
						'value' => 'This looks perfect, thank you!',
					),
				);
			}
			
			// Search or research tool
			if ( strpos( $tool_name, 'search' ) !== false || strpos( $tool_name, 'research' ) !== false ) {
				return array(
					array(
						'label' => 'Search More',
						'value' => 'Search for more information on this topic.',
					),
					array(
						'label' => 'Refine Search',
						'value' => 'Let me refine my search criteria.',
					),
					array(
						'label' => 'That\'s Enough',
						'value' => 'I have what I need, thank you.',
					),
				);
			}
		}
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_tool_based_quick_replies', 10, 6 );

/**
 * Example 6: Conditional Quick Replies with Rate Limiting
 *
 * Only show quick replies sometimes to avoid overwhelming users.
 */
function wp_mcp_ai_example_conditional_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Get or initialize counter
	$counter_key = 'wp_mcp_ai_quick_reply_counter_' . $assistant_id;
	$counter     = get_transient( $counter_key );
	
	if ( false === $counter ) {
		$counter = 0;
	}
	
	// Increment counter
	++$counter;
	set_transient( $counter_key, $counter, 3600 ); // 1 hour expiry
	
	// Only show quick replies every 3rd message
	if ( $counter % 3 === 0 ) {
		return array(
			array(
				'label' => 'Continue',
				'value' => 'Please continue.',
			),
			array(
				'label' => 'More Info',
				'value' => 'Tell me more about this.',
			),
			array(
				'label' => 'Done',
				'value' => 'I\'m all set, thanks!',
			),
		);
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_conditional_quick_replies', 10, 6 );

/**
 * Example 7: Emoji-Enhanced Quick Replies
 *
 * Add emojis to make buttons more engaging.
 */
function wp_mcp_ai_example_emoji_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	// Check if this is a greeting or introduction
	if ( isset( $response['choices'][0]['message']['content'] ) ) {
		$content = strtolower( $response['choices'][0]['message']['content'] );
		
		if ( strpos( $content, 'hello' ) !== false || strpos( $content, 'hi' ) !== false || strpos( $content, 'welcome' ) !== false ) {
			return array(
				array(
					'label' => '👋 Say Hello',
					'value' => 'Hello! Nice to meet you.',
				),
				array(
					'label' => '💡 Learn More',
					'value' => 'Tell me what you can help me with.',
				),
				array(
					'label' => '🚀 Get Started',
					'value' => 'Let\'s get started right away!',
				),
			);
		}
	}
	
	return $quick_replies;
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_emoji_quick_replies', 10, 6 );

/**
 * Example 8: Localized Quick Replies
 *
 * Support multiple languages based on WordPress locale.
 */
function wp_mcp_ai_example_localized_quick_replies( $quick_replies, $response, $assistant_id, $messages, $assistant_config, $request ) {
	$locale = get_locale();
	
	// Spanish
	if ( 'es_ES' === $locale || 'es_MX' === $locale ) {
		return array(
			array(
				'label' => 'Sí',
				'value' => 'Sí, por favor continúa.',
			),
			array(
				'label' => 'No',
				'value' => 'No, gracias.',
			),
			array(
				'label' => 'Más información',
				'value' => '¿Puedes darme más información?',
			),
		);
	}
	
	// French
	if ( 'fr_FR' === $locale ) {
		return array(
			array(
				'label' => 'Oui',
				'value' => 'Oui, s\'il vous plaît continuez.',
			),
			array(
				'label' => 'Non',
				'value' => 'Non, merci.',
			),
			array(
				'label' => 'Plus d\'infos',
				'value' => 'Pouvez-vous me donner plus d\'informations?',
			),
		);
	}
	
	// German
	if ( 'de_DE' === $locale ) {
		return array(
			array(
				'label' => 'Ja',
				'value' => 'Ja, bitte fahren Sie fort.',
			),
			array(
				'label' => 'Nein',
				'value' => 'Nein, danke.',
			),
			array(
				'label' => 'Mehr Info',
				'value' => 'Können Sie mir mehr Informationen geben?',
			),
		);
	}
	
	// Default English
	return array(
		array(
			'label' => 'Yes',
			'value' => 'Yes, please continue.',
		),
		array(
			'label' => 'No',
			'value' => 'No, thank you.',
		),
		array(
			'label' => 'More Info',
			'value' => 'Can you give me more information?',
		),
	);
}
// add_filter( 'wp_mcp_ai_quick_replies', 'wp_mcp_ai_example_localized_quick_replies', 10, 6 );
