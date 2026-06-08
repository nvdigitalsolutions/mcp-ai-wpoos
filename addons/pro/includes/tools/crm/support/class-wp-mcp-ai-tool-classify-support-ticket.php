<?php
/**
 * Classify Support Ticket Tool
 *
 * AI-assisted categorisation: suggests category and priority
 * based on ticket body content using keyword heuristics.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@inheritdoc}
 */
class WP_MCP_AI_Tool_Classify_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Classify Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'classify_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Classify Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI categorisation: suggests category and priority for a support ticket based on content analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Support ticket post ID to classify.', 'mcp-ai-wpoos-pro' ),
				),
				'body'          => array(
					'type'        => 'string',
					'description' => __( 'Alternative: ticket body text to classify (if no ticket_id provided).', 'mcp-ai-wpoos-pro' ),
				),
				'apply_results' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, save the suggested category and priority to the ticket. Default: false.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$ticket_id   = absint( $arguments['ticket_id'] ?? 0 );
		$apply       = ! empty( $arguments['apply_results'] );
		$ticket_body = '';

		if ( $ticket_id ) {
			$ticket = get_post( $ticket_id );
			if ( ! $ticket || 'mcp_ai_support_ticket' !== $ticket->post_type ) {
				return new WP_Error( 'ticket_not_found', __( 'Support ticket not found.', 'mcp-ai-wpoos-pro' ) );
			}
			$ticket_body = $ticket->post_title . ' ' . $ticket->post_content;
		} else {
			$ticket_body = sanitize_textarea_field( $arguments['body'] ?? '' );
			if ( empty( $ticket_body ) ) {
				return new WP_Error( 'no_content', __( 'Provide either ticket_id or body to classify.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$body_lower = strtolower( $ticket_body );

		// Heuristic category classification.
		$keywords = array(
			'bug'             => array( 'bug', 'error', 'crash', 'broken', 'not working', 'issue', 'fail', 'exception', '500', '404' ),
			'feature_request' => array( 'feature request', 'add', 'can you', 'would be nice', 'suggestion', 'enhancement', 'wish', 'integrate' ),
			'billing'         => array( 'billing', 'invoice', 'payment', 'charge', 'refund', 'credit', 'price', 'subscription', 'plan', 'upgrade', 'downgrade' ),
			'account'         => array( 'account', 'login', 'password', 'access', 'permission', 'user', 'role', 'profile', 'register', 'sign up', 'signup' ),
			'question'        => array( 'how do', 'how to', 'what is', 'where', 'when', '?', 'help', 'guide', 'documentation', 'explain' ),
		);

		$category_scores = array();
		foreach ( $keywords as $cat => $terms ) {
			$score = 0;
			foreach ( $terms as $term ) {
				$count  = substr_count( $body_lower, $term );
				$score += $count * ( strlen( $term ) > 4 ? 2 : 1 );
			}
			$category_scores[ $cat ] = $score;
		}

		arsort( $category_scores );
		$suggested_category = key( $category_scores );
		$top_score          = reset( $category_scores );
		$confidence         = $top_score > 0 ? min( 100, (int) round( ( $top_score / max( 5, count( preg_split( '/\s+/', $body_lower ) ? preg_split( '/\s+/', $body_lower ) : array( '1' ) ) ) ) * 100 ) ) : 0;

		// For empty tickets, default to question.
		if ( 0 === $top_score ) {
			$suggested_category = 'question';
			$confidence         = 50;
		}

		// Heuristic priority classification.
		$priority_keywords = array(
			'p1_critical' => array( 'urgent', 'critical', 'emergency', 'down', 'outage', 'asap', 'immediately', 'blocked', 'cannot', 'can\'t', 'crash', 'all data', 'lost' ),
			'p2_high'     => array( 'important', 'broken', 'not working', 'issue', 'problem', 'fail', 'error', 'bug' ),
		);

		$priority_scores = array(
			'p4_low'      => 0,
			'p3_medium'   => 0,
			'p2_high'     => 0,
			'p1_critical' => 0,
		);
		foreach ( $priority_keywords as $pri => $terms ) {
			foreach ( $terms as $term ) {
				$count                    = substr_count( $body_lower, $term );
				$priority_scores[ $pri ] += $count * 3;
			}
		}

		arsort( $priority_scores );
		$suggested_priority = key( $priority_scores );
		$pri_top            = reset( $priority_scores );
		if ( 0 === $pri_top ) {
			$suggested_priority = 'p3_medium';
		}

		// Apply to ticket if requested.
		if ( $apply && $ticket_id ) {
			update_post_meta( $ticket_id, '_ticket_category', $suggested_category );

			$current_priority = get_post_meta( $ticket_id, '_ticket_priority', true );
			if ( ! $current_priority || $current_priority !== $suggested_priority ) {
				update_post_meta( $ticket_id, '_ticket_priority', $suggested_priority );
				if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
					$sla = WP_MCP_AI_Support_Ticket_CPT::calculate_sla_targets( $suggested_priority, $ticket->post_date );
					update_post_meta( $ticket_id, '_ticket_sla_first_response_by', $sla['first_response_by'] );
					update_post_meta( $ticket_id, '_ticket_sla_resolution_by', $sla['resolution_by'] );
				}
			}
		}

		return $this->format_success_response(
			__( 'Ticket classified.', 'mcp-ai-wpoos-pro' ),
			array(
				'suggested_category' => $suggested_category,
				'category_scores'    => $category_scores,
				'confidence'         => $confidence,
				'suggested_priority' => $suggested_priority,
				'priority_scores'    => $priority_scores,
				'applied'            => $apply && $ticket_id,
			)
		);
	}
}
