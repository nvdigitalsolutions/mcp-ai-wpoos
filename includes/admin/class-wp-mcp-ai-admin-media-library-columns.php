<?php
/**
 * Media Library Columns Integration.
 *
 * Adds AI usage and cost badges to the media library admin list page.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds AI usage and cost columns to the WordPress media library list view.
 *
 * This class integrates with the Media Library (upload.php) admin page to display
 * usage statistics and cost information for attachments that have been processed
 * by AI tools (alt text generation, caption generation, image editing, etc.).
 */
class WP_MCP_AI_Admin_Media_Library_Columns {

	/**
	 * Meta key for storing AI usage data on attachments.
	 */
	const USAGE_META_KEY = '_wp_mcp_ai_usage';

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Admin_Media_Library_Columns|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Admin_Media_Library_Columns
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Only run on admin pages.
		if ( ! is_admin() ) {
			return;
		}

		// Add the column to media library list view.
		add_filter( 'manage_media_columns', array( $this, 'add_usage_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_usage_column' ), 10, 2 );

		// Enqueue admin styles for the badges.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

		// Hook into tool execution to track per-attachment usage.
		add_action( 'wp_mcp_ai_after_tool_execution', array( $this, 'track_attachment_usage' ), 10, 4 );
	}

	/**
	 * Add the AI Usage column to the media library list view.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_usage_column( $columns ) {
		$columns['wp_mcp_ai_usage'] = __( 'AI Usage', 'wp-mcp-ai' );
		return $columns;
	}

	/**
	 * Render the AI Usage column content.
	 *
	 * @param string $column_name Column name.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public function render_usage_column( $column_name, $attachment_id ) {
		if ( 'wp_mcp_ai_usage' !== $column_name ) {
			return;
		}

		$usage = $this->get_attachment_usage( $attachment_id );

		if ( empty( $usage ) ) {
			echo '<span class="wp-mcp-ai-no-usage">—</span>';
			return;
		}

		$this->render_usage_badges( $usage, $attachment_id );
	}

	/**
	 * Render usage badges for an attachment.
	 *
	 * @param array $usage         Usage data.
	 * @param int   $attachment_id Attachment ID.
	 */
	private function render_usage_badges( $usage, $attachment_id ) {
		$total_tokens = isset( $usage['total_tokens'] ) ? absint( $usage['total_tokens'] ) : 0;
		$total_cost   = isset( $usage['total_cost'] ) ? floatval( $usage['total_cost'] ) : 0.0;
		$tool_count   = isset( $usage['tool_count'] ) ? absint( $usage['tool_count'] ) : 0;
		$last_used    = isset( $usage['last_used'] ) ? $usage['last_used'] : '';

		printf(
			'<div class="wp-mcp-ai-usage-badges" data-attachment-id="%d">',
			absint( $attachment_id )
		);

		// Tokens badge.
		if ( $total_tokens > 0 ) {
			$tokens_display = $this->format_token_count( $total_tokens );
			printf(
				'<span class="wp-mcp-ai-badge wp-mcp-ai-badge-tokens" title="%s">%s</span>',
				esc_attr(
					sprintf(
						/* translators: %s: formatted number of tokens */
						__( '%s tokens used', 'wp-mcp-ai' ),
						number_format_i18n( $total_tokens )
					)
				),
				esc_html( $tokens_display )
			);
		}

		// Cost badge.
		if ( $total_cost > 0 ) {
			$cost_display = $this->format_cost( $total_cost );
			printf(
				'<span class="wp-mcp-ai-badge wp-mcp-ai-badge-cost" title="%s">%s</span>',
				esc_attr(
					sprintf(
						/* translators: %s: formatted cost */
						__( 'AI cost: %s', 'wp-mcp-ai' ),
						$cost_display
					)
				),
				esc_html( $cost_display )
			);
		}

		// Tools badge (number of operations).
		if ( $tool_count > 0 ) {
			printf(
				'<span class="wp-mcp-ai-badge wp-mcp-ai-badge-tools" title="%s">%s</span>',
				esc_attr(
					sprintf(
						/* translators: %d: number of AI operations */
						_n( '%d AI operation', '%d AI operations', $tool_count, 'wp-mcp-ai' ),
						$tool_count
					)
				),
				esc_html(
					sprintf(
						/* translators: %d: number of AI operations */
						_n( '%d op', '%d ops', $tool_count, 'wp-mcp-ai' ),
						$tool_count
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Format token count for display.
	 *
	 * @param int $tokens Number of tokens.
	 * @return string Formatted token count.
	 */
	private function format_token_count( $tokens ) {
		if ( $tokens >= 1000000 ) {
			/* translators: %s: number of millions */
			return sprintf( __( '%sM tok', 'wp-mcp-ai' ), number_format_i18n( $tokens / 1000000, 1 ) );
		} elseif ( $tokens >= 1000 ) {
			/* translators: %s: number of thousands */
			return sprintf( __( '%sk tok', 'wp-mcp-ai' ), number_format_i18n( $tokens / 1000, 1 ) );
		}
		/* translators: %s: number of tokens */
		return sprintf( __( '%s tok', 'wp-mcp-ai' ), number_format_i18n( $tokens ) );
	}

	/**
	 * Format cost for display.
	 *
	 * @param float $cost Cost in USD.
	 * @return string Formatted cost.
	 */
	private function format_cost( $cost ) {
		if ( $cost < 0.01 ) {
			return sprintf( '$%s', number_format_i18n( $cost, 4 ) );
		} elseif ( $cost < 1.00 ) {
			return sprintf( '$%s', number_format_i18n( $cost, 3 ) );
		}
		return sprintf( '$%s', number_format_i18n( $cost, 2 ) );
	}

	/**
	 * Get usage data for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array|null Usage data or null if none.
	 */
	public function get_attachment_usage( $attachment_id ) {
		$usage = get_post_meta( $attachment_id, self::USAGE_META_KEY, true );

		if ( ! is_array( $usage ) || empty( $usage ) ) {
			return null;
		}

		return $usage;
	}

	/**
	 * Track usage for an attachment after tool execution.
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param mixed  $result    Tool execution result.
	 */
	public function track_attachment_usage( $tool_name, $arguments, $context, $result ) {
		// Only track for image-related tools.
		$image_tools = array(
			'generate_image_alt_text',
			'generate_image_caption',
			'generate_openai_image',
			'generate_gemini_image',
			'edit_gemini_image',
			'resize_image',
			'crop_image',
			'rotate_image',
			'convert_image_format',
		);

		if ( ! in_array( $tool_name, $image_tools, true ) ) {
			return;
		}

		// Get attachment ID from arguments or result.
		$attachment_id = $this->extract_attachment_id( $arguments, $result );
		if ( ! $attachment_id ) {
			return;
		}

		// Extract usage data from result.
		$token_usage = null;
		$provider    = '';
		$model       = '';
		$cost        = 0.0;

		if ( is_array( $result ) ) {
			if ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
				$token_usage = $result['usage'];
			}
			if ( isset( $result['provider'] ) ) {
				$provider = sanitize_text_field( $result['provider'] );
			}
			if ( isset( $result['model'] ) ) {
				$model = sanitize_text_field( $result['model'] );
			}
		}

		// Calculate cost if we have token usage.
		if ( $token_usage && $provider && $model && class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			$input_tokens  = isset( $token_usage['prompt_tokens'] ) ? absint( $token_usage['prompt_tokens'] ) : 0;
			$output_tokens = isset( $token_usage['completion_tokens'] ) ? absint( $token_usage['completion_tokens'] ) : 0;

			// Handle total_tokens if prompt/completion not available.
			// Split 50/50 as fallback when we don't have separate input/output counts.
			if ( 0 === $input_tokens && 0 === $output_tokens && isset( $token_usage['total_tokens'] ) ) {
				$total         = absint( $token_usage['total_tokens'] );
				$input_tokens  = intval( $total / 2 );
				$output_tokens = $total - $input_tokens;
			}

			$cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
				$provider,
				$model,
				$input_tokens,
				$output_tokens
			);
		}

		// Update attachment usage meta.
		$this->update_attachment_usage( $attachment_id, $tool_name, $token_usage, $cost, $provider, $model );
	}

	/**
	 * Extract attachment ID from tool arguments or result.
	 *
	 * @param array $arguments Tool arguments.
	 * @param mixed $result    Tool execution result.
	 * @return int|null Attachment ID or null.
	 */
	private function extract_attachment_id( $arguments, $result ) {
		// Check arguments first.
		if ( isset( $arguments['attachment_id'] ) ) {
			return absint( $arguments['attachment_id'] );
		}

		// Check result for attachment_id (for image generation tools).
		if ( is_array( $result ) && isset( $result['attachment_id'] ) ) {
			return absint( $result['attachment_id'] );
		}

		return null;
	}

	/**
	 * Update attachment usage metadata.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $tool_name     Tool that was used.
	 * @param array|null $token_usage   Token usage data.
	 * @param float      $cost          Cost in USD.
	 * @param string     $provider      AI provider.
	 * @param string     $model         Model used.
	 */
	private function update_attachment_usage( $attachment_id, $tool_name, $token_usage, $cost, $provider, $model ) {
		$existing_usage = get_post_meta( $attachment_id, self::USAGE_META_KEY, true );

		if ( ! is_array( $existing_usage ) ) {
			$existing_usage = array(
				'total_tokens' => 0,
				'total_cost'   => 0.0,
				'tool_count'   => 0,
				'tools'        => array(),
				'last_used'    => '',
			);
		}

		// Add token usage.
		if ( $token_usage && is_array( $token_usage ) ) {
			$tokens_to_add = 0;
			if ( isset( $token_usage['total_tokens'] ) ) {
				$tokens_to_add = absint( $token_usage['total_tokens'] );
			} elseif ( isset( $token_usage['prompt_tokens'] ) || isset( $token_usage['completion_tokens'] ) ) {
				$tokens_to_add  = isset( $token_usage['prompt_tokens'] ) ? absint( $token_usage['prompt_tokens'] ) : 0;
				$tokens_to_add += isset( $token_usage['completion_tokens'] ) ? absint( $token_usage['completion_tokens'] ) : 0;
			}
			$existing_usage['total_tokens'] += $tokens_to_add;
		}

		// Add cost.
		$existing_usage['total_cost'] += floatval( $cost );

		// Track tool usage.
		$existing_usage['tool_count'] += 1;

		if ( ! isset( $existing_usage['tools'][ $tool_name ] ) ) {
			$existing_usage['tools'][ $tool_name ] = 0;
		}
		$existing_usage['tools'][ $tool_name ] += 1;

		// Update last used timestamp.
		$existing_usage['last_used'] = current_time( 'mysql' );

		// Store provider/model info for the last operation.
		$existing_usage['last_provider'] = $provider;
		$existing_usage['last_model']    = $model;

		update_post_meta( $attachment_id, self::USAGE_META_KEY, $existing_usage );
	}

	/**
	 * Enqueue admin styles for the usage badges.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		// Only load on media library page.
		if ( 'upload.php' !== $hook ) {
			return;
		}

		// Register a minimal stylesheet to attach inline styles to.
		wp_register_style( 'wp-mcp-ai-media-columns', false, array(), WP_MCP_AI_VERSION );
		wp_enqueue_style( 'wp-mcp-ai-media-columns' );

		$css = '
			.wp-mcp-ai-usage-badges {
				display: flex;
				flex-wrap: wrap;
				gap: 4px;
			}
			.wp-mcp-ai-badge {
				display: inline-block;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 500;
				line-height: 1.4;
				white-space: nowrap;
			}
			.wp-mcp-ai-badge-tokens {
				background-color: #e8f4fd;
				color: #1e4d75;
				border: 1px solid #b8daef;
			}
			.wp-mcp-ai-badge-cost {
				background-color: #fef6e8;
				color: #735d24;
				border: 1px solid #f5deb3;
			}
			.wp-mcp-ai-badge-tools {
				background-color: #e8f8e8;
				color: #2e5a2e;
				border: 1px solid #b8e8b8;
			}
			.wp-mcp-ai-no-usage {
				color: #999;
			}
			@media screen and (max-width: 782px) {
				.wp-mcp-ai-usage-badges {
					flex-direction: column;
				}
			}
		';

		wp_add_inline_style( 'wp-mcp-ai-media-columns', $css );
	}

	/**
	 * Initialize the class.
	 *
	 * @return WP_MCP_AI_Admin_Media_Library_Columns
	 */
	public static function init() {
		return self::get_instance();
	}
}
