<?php
/**
 * Draft Lead Reply — AI-assisted reply draft using the WP MCP AI provider.
 *
 * Previously used hardcoded templates. Now sends a prompt to the configured
 * AI provider (via wp_mcp_ai_chat_completion) to generate a contextual reply
 * draft. Falls back to template-based drafts when no AI provider is available.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @since 2.4.0 Uses wp_mcp_ai_chat_completion for real AI drafting; template fallback retained.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Draft_Lead_Reply implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	public function get_slug() {
		return 'draft_lead_reply'; }

	public function get_name() {
		return __( 'Draft Lead Reply', 'mcp-ai-wpoos-pro' ); }

	public function get_description() {
		return __( 'Generate an AI-assisted reply draft for a lead message using the configured AI provider. Does NOT send — returns the draft for review.', 'mcp-ai-wpoos-pro' ); }

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'          => array( 'type' => 'integer' ),
				'incoming_message' => array(
					'type'        => 'string',
					'description' => __( 'The message you are replying to.', 'mcp-ai-wpoos-pro' ),
				),
				'channel'          => array(
					'type'    => 'string',
					'default' => 'email',
				),
				'tone'             => array(
					'type'    => 'string',
					'enum'    => array( 'friendly', 'professional', 'concise', 'urgent' ),
					'default' => 'professional',
				),
				'context_notes'    => array(
					'type'        => 'string',
					'description' => __( 'Additional context about the lead (company, role, previous interactions) to help the AI craft a better reply.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'incoming_message' ),
		); }

	public function get_required_capability() {
		return 'edit_posts'; }

	public function requires_base_pro() {
		return true; }

	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability', 'ai-call' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		$incoming = sanitize_textarea_field( $arguments['incoming_message'] );
		$tone     = sanitize_key( $arguments['tone'] ?? 'professional' );
		$channel  = sanitize_key( $arguments['channel'] ?? 'email' );

		// Gather lead context if lead_id provided.
		$context_notes = sanitize_textarea_field( $arguments['context_notes'] ?? '' );
		$lead_id       = isset( $arguments['lead_id'] ) ? absint( $arguments['lead_id'] ) : 0;

		if ( $lead_id && empty( $context_notes ) ) {
			$context_notes = $this->build_lead_context( $lead_id );
		}

		// Try AI-powered draft first.
		$ai_draft = $this->generate_ai_draft( $incoming, $tone, $channel, $context_notes );

		if ( ! is_wp_error( $ai_draft ) && ! empty( $ai_draft ) ) {
			return array(
				'success'  => true,
				'draft'    => $ai_draft,
				'tone'     => $tone,
				'channel'  => $channel,
				'message'  => __( 'AI-generated reply draft. Review before sending.', 'mcp-ai-wpoos-pro' ),
				'powered_by' => 'ai',
			);
		}

		// Fall back to template-based draft.
		$template_draft = $this->get_template_draft( $tone, $channel );

		return array(
			'success'  => true,
			'draft'    => $template_draft,
			'tone'     => $tone,
			'channel'  => $channel,
			'message'  => __( 'Template-based reply draft (AI provider unavailable). Review before sending.', 'mcp-ai-wpoos-pro' ),
			'powered_by' => 'template',
		);
	}

	/**
	 * Generate an AI-powered reply draft using wp_mcp_ai_chat_completion.
	 *
	 * @since 2.4.0
	 *
	 * @param string $incoming      The incoming message to reply to.
	 * @param string $tone          Desired tone (friendly, professional, concise, urgent).
	 * @param string $channel       Communication channel (email, sms, whatsapp).
	 * @param string $context_notes Additional lead context.
	 * @return string|WP_Error AI-generated draft or WP_Error.
	 */
	private function generate_ai_draft( $incoming, $tone, $channel, $context_notes ) {
		if ( ! function_exists( 'wp_mcp_ai_chat_completion' ) ) {
			return new WP_Error( 'ai_unavailable', __( 'AI chat completion function not available.', 'mcp-ai-wpoos-pro' ) );
		}

		// Channel-specific length limits.
		$max_length_map = array(
			'sms'      => 160,
			'whatsapp' => 1000,
			'email'    => 2000,
		);
		$max_length = $max_length_map[ $channel ] ?? 2000;

		// Tone instructions.
		$tone_instructions = array(
			'friendly'     => 'Be warm, approachable, and conversational. Use casual language.',
			'professional' => 'Be formal, polite, and business-appropriate. Use proper salutations.',
			'concise'      => 'Be very brief and to the point. Maximum 2-3 short sentences.',
			'urgent'       => 'Be prompt and direct. Convey urgency without being pushy.',
		);
		$tone_guide = $tone_instructions[ $tone ] ?? $tone_instructions['professional'];

		// Build the prompt.
		$prompt = sprintf(
			"You are a CRM assistant drafting a %s reply to a lead via %s channel. %s\n\n"
			. "Keep the reply under %d characters. Do NOT include a subject line. "
			. "Return ONLY the draft text — no markdown, no explanations, no meta-commentary.\n\n",
			$tone,
			$channel,
			$tone_guide,
			$max_length
		);

		if ( ! empty( $context_notes ) ) {
			$prompt .= "LEAD CONTEXT:\n" . $context_notes . "\n\n";
		}

		$prompt .= "INCOMING MESSAGE:\n" . $incoming . "\n\nDRAFT REPLY:";

		$response = wp_mcp_ai_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			array(
				'max_tokens'  => max( 100, min( 800, (int) ( $max_length / 2 ) ) ),
				'temperature' => 0.7,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = isset( $response['content'] ) ? trim( $response['content'] ) : '';

		if ( empty( $content ) ) {
			return new WP_Error( 'ai_empty_response', __( 'AI returned an empty draft.', 'mcp-ai-wpoos-pro' ) );
		}

		// Strip any markdown code fences that may wrap the response.
		$content = preg_replace( '/^```[\w]*\s*\n/', '', $content );
		$content = preg_replace( '/\n```\s*$/', '', $content );

		return $content;
	}

	/**
	 * Build a context string from lead post meta for AI prompting.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return string Context text.
	 */
	private function build_lead_context( $lead_id ) {
		$parts = array();

		$name = get_post_meta( $lead_id, 'first_name', true );
		if ( $name ) {
			$parts[] = sprintf( __( 'Name: %s', 'mcp-ai-wpoos-pro' ), $name );
		}

		$company = get_post_meta( $lead_id, 'company', true );
		if ( $company ) {
			$parts[] = sprintf( __( 'Company: %s', 'mcp-ai-wpoos-pro' ), $company );
		}

		$role = get_post_meta( $lead_id, 'role', true );
		if ( $role ) {
			$parts[] = sprintf( __( 'Role: %s', 'mcp-ai-wpoos-pro' ), $role );
		}

		$lifecycle = get_post_meta( $lead_id, 'lifecycle_stage', true );
		if ( $lifecycle ) {
			$parts[] = sprintf( __( 'Lifecycle: %s', 'mcp-ai-wpoos-pro' ), $lifecycle );
		}

		$score = get_post_meta( $lead_id, 'lead_score', true );
		if ( '' !== $score && null !== $score ) {
			$parts[] = sprintf( __( 'Lead Score: %s', 'mcp-ai-wpoos-pro' ), $score );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Template-based fallback drafts (used when AI is unavailable).
	 *
	 * @param string $tone    Desired tone.
	 * @param string $channel Communication channel.
	 * @return string Draft text.
	 */
	private function get_template_draft( $tone, $channel ) {
		$templates = array(
			'friendly'     => __( "Hi there!\n\nThanks so much for reaching out — we really appreciate it. I'd love to help with what you're looking for.\n\nLet's set up a quick call this week to discuss. What time works best for you?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'professional' => __( "Hello,\n\nThank you for your inquiry. I would be happy to provide more information and address any questions you may have.\n\nWould you be available for a brief call this week to discuss further?\n\nKind regards,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'concise'      => __( "Thanks for reaching out. Happy to help — when would be a good time to connect?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'urgent'       => __( "Hi,\n\nI saw your message and want to make sure we respond quickly. Let's connect ASAP — are you available today?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
		);

		$draft = $templates[ $tone ] ?? $templates['professional'];

		// Truncate for SMS.
		if ( 'sms' === $channel && mb_strlen( $draft ) > 160 ) {
			$draft = mb_substr( $draft, 0, 157 ) . '...';
		}

		return $draft;
	}
}
