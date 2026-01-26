<?php
/**
 * Trait for ensuring tools return proper chat client responses.
 *
 * This trait provides helper methods to ensure all tool responses include
 * displayable content for the chat client, maintaining conversation continuity
 * and proper LLM context.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Chat_Response
 *
 * Standardizes tool responses to ensure they are compatible with the chat
 * client's message persistence system. Provides methods to format responses
 * with proper message fields for UI display and LLM context.
 *
 * Usage:
 * ```php
 * class My_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Chat_Response;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         $data = $this->do_something();
 *
 *         // Ensure response has displayable message
 *         return $this->format_chat_response( $data, 'Operation completed successfully.' );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Chat_Response {

	/**
	 * Format a tool response with proper chat client fields.
	 *
	 * Ensures the response includes a user-facing message that can be displayed
	 * in the chat UI and used by the LLM for context. This prevents empty
	 * assistant messages that break conversation continuity.
	 *
	 * @param mixed  $data    The tool result data (array, string, etc.).
	 * @param string $message Optional. User-facing message. If empty, will be generated from data.
	 * @param array  $options Optional. Additional options for response formatting.
	 * @return array Standardized response array with message field.
	 */
	protected function format_chat_response( $data, $message = '', $options = array() ) {
		$defaults = array(
			'include_data'   => true,  // Include the original data in response.
			'data_key'       => 'data', // Key to use for data in response.
			'auto_generate'  => true,  // Auto-generate message if empty.
			'message_prefix' => '',    // Prefix to add to message.
			'message_suffix' => '',    // Suffix to add to message.
		);

		$options = wp_parse_args( $options, $defaults );

		// Start building response.
		$response = array();

		// Handle message field - ensure it exists and is non-empty.
		if ( empty( $message ) && $options['auto_generate'] ) {
			$message = $this->generate_message_from_data( $data );
		}

		// Apply prefix and suffix if provided.
		if ( ! empty( $options['message_prefix'] ) ) {
			$message = $options['message_prefix'] . ' ' . $message;
		}
		if ( ! empty( $options['message_suffix'] ) ) {
			$message = $message . ' ' . $options['message_suffix'];
		}

		// Always include message field (even if empty string).
		// This ensures the chat client knows there's a response.
		$response['message'] = trim( $message );

		// Include the original data if requested.
		if ( $options['include_data'] && ! empty( $data ) ) {
			// If data is already an array, merge it into response.
			if ( is_array( $data ) ) {
				// Check if data already has a 'message' key to avoid overwriting.
				if ( isset( $data['message'] ) && empty( $response['message'] ) ) {
					$response['message'] = $data['message'];
				}
				// Add data under specified key if it's not a simple key-value array.
				if ( $this->is_structured_data( $data ) ) {
					$response[ $options['data_key'] ] = $data;
				} else {
					// Merge simple arrays directly.
					$response = array_merge( $response, $data );
				}
			} else {
				// Non-array data: store under data key.
				$response[ $options['data_key'] ] = $data;
			}
		}

		return $response;
	}

	/**
	 * Format a success response with a message.
	 *
	 * Convenience method for successful operations.
	 *
	 * @param string $message Success message.
	 * @param mixed  $data    Optional. Additional data to include.
	 * @return array Formatted success response.
	 */
	protected function format_success_response( $message, $data = null ) {
		$response = array(
			'success' => true,
			'message' => $message,
		);

		if ( null !== $data ) {
			if ( is_array( $data ) ) {
				$response = array_merge( $response, $data );
			} else {
				$response['data'] = $data;
			}
		}

		return $response;
	}

	/**
	 * Format an empty result response with explanation.
	 *
	 * Used when a query or search returns no results. Ensures the chat
	 * client has a message to display instead of empty content.
	 *
	 * @param string $explanation Optional. Custom explanation message.
	 * @return array Formatted empty result response.
	 */
	protected function format_empty_result_response( $explanation = '' ) {
		if ( empty( $explanation ) ) {
			$explanation = __( 'No results found matching your query.', 'mcp-ai-wpoos' );
		}

		return array(
			'message' => $explanation,
			'results' => array(),
			'count'   => 0,
		);
	}

	/**
	 * Format a collection response (list, array of items).
	 *
	 * Standardizes responses that return collections of items.
	 *
	 * @param array  $items   Array of items.
	 * @param string $message Optional. Custom message. Auto-generated if empty.
	 * @param array  $options Optional. Additional options (pagination, etc.).
	 * @return array Formatted collection response.
	 */
	protected function format_collection_response( $items, $message = '', $options = array() ) {
		$count = is_array( $items ) ? count( $items ) : 0;

		// Auto-generate message if empty.
		if ( empty( $message ) ) {
			if ( 0 === $count ) {
				$message = __( 'No items found.', 'mcp-ai-wpoos' );
			} elseif ( 1 === $count ) {
				$message = __( 'Found 1 item.', 'mcp-ai-wpoos' );
			} else {
				/* translators: %d: number of items */
				$message = sprintf( __( 'Found %d items.', 'mcp-ai-wpoos' ), $count );
			}
		}

		$response = array(
			'message' => $message,
			'items'   => $items,
			'count'   => $count,
		);

		// Add pagination if provided.
		if ( isset( $options['page'] ) ) {
			$response['pagination'] = array(
				'page'     => absint( $options['page'] ),
				'per_page' => isset( $options['per_page'] ) ? absint( $options['per_page'] ) : 20,
				'total'    => isset( $options['total'] ) ? absint( $options['total'] ) : $count,
			);

			// Calculate total pages.
			if ( $response['pagination']['per_page'] > 0 ) {
				$response['pagination']['total_pages'] = ceil(
					$response['pagination']['total'] / $response['pagination']['per_page']
				);
			}
		}

		return $response;
	}

	/**
	 * Ensure a response array has a message field.
	 *
	 * Helper to validate and fix existing response arrays that might lack
	 * a message field. Can be used to wrap responses from external APIs.
	 *
	 * @param array  $response Existing response array.
	 * @param string $fallback_message Optional. Message to use if none exists.
	 * @return array Response with guaranteed message field.
	 */
	protected function ensure_response_message( $response, $fallback_message = '' ) {
		if ( ! is_array( $response ) ) {
			$response = array( 'data' => $response );
		}

		// Check for existing message-like fields.
		$message_keys  = array( 'message', 'text', 'summary', 'description' );
		$found_message = '';

		foreach ( $message_keys as $key ) {
			if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) && ! empty( $response[ $key ] ) ) {
				$found_message = $response[ $key ];
				break;
			}
		}

		// If no message found, use fallback or generate.
		if ( empty( $found_message ) ) {
			if ( ! empty( $fallback_message ) ) {
				$response['message'] = $fallback_message;
			} else {
				$response['message'] = $this->generate_message_from_data( $response );
			}
		} else {
			// Standardize to 'message' field.
			$response['message'] = $found_message;
		}

		return $response;
	}

	/**
	 * Generate a user-facing message from data.
	 *
	 * Attempts to create a meaningful message from structured data.
	 * Used as fallback when no explicit message is provided.
	 *
	 * @param mixed $data The data to generate message from.
	 * @return string Generated message.
	 */
	protected function generate_message_from_data( $data ) {
		// Handle WP_Error.
		if ( is_wp_error( $data ) ) {
			return $data->get_error_message();
		}

		// Handle arrays.
		if ( is_array( $data ) ) {
			// Check for common result indicators.
			if ( isset( $data['success'] ) ) {
				if ( $data['success'] ) {
					return __( 'Operation completed successfully.', 'mcp-ai-wpoos' );
				} else {
					return isset( $data['error'] )
						? $data['error']
						: __( 'Operation failed.', 'mcp-ai-wpoos' );
				}
			}

			// Check for count/items.
			if ( isset( $data['count'] ) ) {
				$count = absint( $data['count'] );
				if ( 0 === $count ) {
					return __( 'No results found.', 'mcp-ai-wpoos' );
				} elseif ( 1 === $count ) {
					return __( 'Found 1 result.', 'mcp-ai-wpoos' );
				} else {
					/* translators: %d: number of results */
					return sprintf( __( 'Found %d results.', 'mcp-ai-wpoos' ), $count );
				}
			}

			// Check for items array.
			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$count = count( $data['items'] );
				if ( 0 === $count ) {
					return __( 'No items found.', 'mcp-ai-wpoos' );
				}
				/* translators: %d: number of items */
				return sprintf( __( 'Retrieved %d items.', 'mcp-ai-wpoos' ), $count );
			}

			// Check for URL (link results).
			if ( isset( $data['url'] ) && is_string( $data['url'] ) ) {
				if ( isset( $data['label'] ) ) {
					return $data['label'];
				}
				return __( 'Resource link available.', 'mcp-ai-wpoos' );
			}

			// Check for file/attachment.
			if ( isset( $data['attachment_id'] ) || isset( $data['file_path'] ) ) {
				return __( 'File generated successfully.', 'mcp-ai-wpoos' );
			}

			// Empty array.
			if ( empty( $data ) ) {
				return __( 'Operation completed.', 'mcp-ai-wpoos' );
			}

			// Non-empty array: generic message.
			return __( 'Data retrieved successfully.', 'mcp-ai-wpoos' );
		}

		// Handle boolean.
		if ( is_bool( $data ) ) {
			return $data
				? __( 'Operation successful.', 'mcp-ai-wpoos' )
				: __( 'Operation failed.', 'mcp-ai-wpoos' );
		}

		// Handle numeric.
		if ( is_numeric( $data ) ) {
			/* translators: %s: numeric result */
			return sprintf( __( 'Result: %s', 'mcp-ai-wpoos' ), $data );
		}

		// Handle string.
		if ( is_string( $data ) ) {
			// If it's a very short string, might be an ID or status.
			if ( strlen( $data ) < 50 ) {
				/* translators: %s: string result */
				return sprintf( __( 'Result: %s', 'mcp-ai-wpoos' ), $data );
			}
			// Longer strings: truncate for message.
			return wp_trim_words( $data, 15, '...' );
		}

		// Default fallback.
		return __( 'Operation completed.', 'mcp-ai-wpoos' );
	}

	/**
	 * Check if data is structured (complex) or simple.
	 *
	 * Helps determine whether to merge data directly into response
	 * or nest it under a 'data' key.
	 *
	 * @param array $data Data array to check.
	 * @return bool True if structured/complex, false if simple.
	 */
	protected function is_structured_data( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		// Check if it's a list (numeric keys).
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return true; // List of items is structured.
		}

		// Check if any values are arrays or objects (nested structure).
		foreach ( $data as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return true;
			}
		}

		// Check for special keys that indicate structure.
		$structural_keys = array( 'items', 'results', 'data', 'attachments', 'meta', 'pagination' );
		foreach ( $structural_keys as $key ) {
			if ( isset( $data[ $key ] ) ) {
				return true;
			}
		}

		return false; // Simple key-value array.
	}
}
