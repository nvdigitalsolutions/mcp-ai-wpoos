<?php
/**
 * Tool that moderates content using OpenAI's Moderation API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for moderating content via OpenAI's Moderation API.
 *
 * Analyzes text and/or images for potentially harmful content across multiple
 * violation categories including sexual, hate, harassment, self-harm, and violence.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Moderate_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	const DEFAULT_MODEL = 'omni-moderation-latest';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'moderate_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Moderate Content', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes text or images for potentially harmful content using OpenAI Moderation API. Checks for violations across multiple categories including sexual content, hate speech, harassment, self-harm, and violence.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'input'   => array(
					'type'        => 'string',
					'description' => __( 'The text content to moderate. For batch moderation, provide an array of strings.', 'wp-mcp-ai' ),
				),
				'model'   => array(
					'type'        => 'string',
					'description' => __( 'The moderation model to use.', 'wp-mcp-ai' ),
					'enum'        => array(
						'omni-moderation-latest',
						'text-moderation-latest',
					),
					'default'     => self::DEFAULT_MODEL,
				),
				'timeout' => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array( 'input' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api'     => true,
			'consumes-tokens'  => false, // Moderation API is free to use.
			'read-only'        => true,  // Does not modify any data.
			'security'         => true,  // Used for safety and compliance.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_provider() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		// Verify user capabilities - only logged-in users with read capability can moderate.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to moderate content.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}
		}

		// Validate and sanitize input.
		if ( ! isset( $arguments['input'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'No input content was provided for moderation.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$input = $arguments['input'];

		// Handle both string and array inputs.
		if ( is_string( $input ) ) {
			$input = sanitize_textarea_field( $input );
			$input = trim( $input );

			if ( '' === $input ) {
				return new WP_Error(
					'wp_mcp_ai_empty_input',
					__( 'Input content cannot be empty.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( is_array( $input ) ) {
			// For batch moderation, sanitize each item.
			$input = array_map(
				function( $item ) {
					if ( is_string( $item ) ) {
						return sanitize_textarea_field( $item );
					}
					return $item;
				},
				$input
			);

			// Remove empty items.
			$input = array_filter( $input, function( $item ) {
				return ! empty( $item );
			} );

			if ( empty( $input ) ) {
				return new WP_Error(
					'wp_mcp_ai_empty_input',
					__( 'All input items are empty.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		} else {
			return new WP_Error(
				'wp_mcp_ai_invalid_input',
				__( 'Input must be a string or array of strings.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get model (default to omni-moderation-latest).
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL;
		if ( empty( $model ) || ! in_array( $model, array( 'omni-moderation-latest', 'text-moderation-latest' ), true ) ) {
			$model = self::DEFAULT_MODEL;
		}

		// Prepare options.
		$options = array(
			'model' => $model,
		);

		if ( isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ) {
			$timeout = absint( $arguments['timeout'] );
			if ( $timeout >= 5 ) {
				$options['timeout'] = min( 60, $timeout );
			}
		}

		// Call the OpenAI client.
		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->moderate_content( $input, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Validate response structure.
		if ( ! isset( $response['results'] ) || ! is_array( $response['results'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'OpenAI returned an invalid moderation response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Build formatted result.
		$results = $response['results'];
		$result  = array(
			'moderation_id' => isset( $response['id'] ) ? $response['id'] : '',
			'model'         => isset( $response['model'] ) ? $response['model'] : $model,
			'results_count' => count( $results ),
			'results'       => $this->format_results( $results ),
			'summary'       => $this->generate_summary( $results ),
		);

		return $result;
	}

	/**
	 * Format moderation results for readability.
	 *
	 * @param array $results Raw results from OpenAI API.
	 * @return array Formatted results.
	 */
	protected function format_results( array $results ) {
		$formatted = array();

		foreach ( $results as $index => $result ) {
			$item = array(
				'index'      => $index,
				'flagged'    => isset( $result['flagged'] ) ? (bool) $result['flagged'] : false,
				'categories' => array(),
				'scores'     => array(),
			);

			// Format flagged categories.
			if ( isset( $result['categories'] ) && is_array( $result['categories'] ) ) {
				foreach ( $result['categories'] as $category => $is_flagged ) {
					if ( $is_flagged ) {
						$item['categories'][] = $category;
					}
				}
			}

			// Get category scores (only for flagged categories or high-confidence detections).
			if ( isset( $result['category_scores'] ) && is_array( $result['category_scores'] ) ) {
				foreach ( $result['category_scores'] as $category => $score ) {
					// Include score if category is flagged or score is > 0.1.
					if ( in_array( $category, $item['categories'], true ) || $score > 0.1 ) {
						$item['scores'][ $category ] = round( $score, 4 );
					}
				}
			}

			// Add input type information if available (omni-moderation model).
			if ( isset( $result['category_applied_input_types'] ) && is_array( $result['category_applied_input_types'] ) ) {
				$item['input_types'] = $result['category_applied_input_types'];
			}

			$formatted[] = $item;
		}

		return $formatted;
	}

	/**
	 * Generate a summary of moderation results.
	 *
	 * @param array $results Raw results from OpenAI API.
	 * @return array Summary information.
	 */
	protected function generate_summary( array $results ) {
		$total_flagged  = 0;
		$all_categories = array();
		$category_counts = array();

		foreach ( $results as $result ) {
			if ( isset( $result['flagged'] ) && $result['flagged'] ) {
				++$total_flagged;

				if ( isset( $result['categories'] ) && is_array( $result['categories'] ) ) {
					foreach ( $result['categories'] as $category => $is_flagged ) {
						if ( $is_flagged ) {
							if ( ! in_array( $category, $all_categories, true ) ) {
								$all_categories[] = $category;
							}

							if ( ! isset( $category_counts[ $category ] ) ) {
								$category_counts[ $category ] = 0;
							}
							++$category_counts[ $category ];
						}
					}
				}
			}
		}

		// Sort categories by frequency.
		arsort( $category_counts );

		$summary = array(
			'total_items'       => count( $results ),
			'flagged_items'     => $total_flagged,
			'flagged_percentage' => count( $results ) > 0 ? round( ( $total_flagged / count( $results ) ) * 100, 2 ) : 0,
			'is_safe'           => 0 === $total_flagged,
			'categories_found'  => array_keys( $category_counts ),
			'category_counts'   => $category_counts,
		);

		// Add text recommendation.
		if ( $summary['is_safe'] ) {
			$summary['recommendation'] = __( 'Content appears safe for publication.', 'wp-mcp-ai' );
		} else {
			$summary['recommendation'] = sprintf(
				/* translators: %d: number of flagged items */
				_n(
					'%d item was flagged and requires review before publication.',
					'%d items were flagged and require review before publication.',
					$total_flagged,
					'wp-mcp-ai'
				),
				$total_flagged
			);
		}

		return $summary;
	}
}
