<?php
/**
 * GenAI span emitter — Proposal 091, Track E.
 *
 * Emits structured span events shaped after the OpenTelemetry GenAI semantic
 * conventions (the `gen_ai.*` attribute namespace) on the existing
 * observability rails: an audit-log event plus a `wp_mcp_ai_genai_span`
 * action hook that any consumer (including the opt-in OTLP exporter) can
 * subscribe to.
 *
 * Zero-cost when nothing consumes spans: `emit()` returns early when no
 * subscriber is registered.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GenAI span emitter.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_GenAI_Span_Emitter {

	/**
	 * Span hook name.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const SPAN_HOOK = 'wp_mcp_ai_genai_span';

	/**
	 * Emit a GenAI span event.
	 *
	 * @since 1.2.13
	 *
	 * @param string $operation  Operation name: 'chat' or 'execute_tool'.
	 * @param array  $attributes Optional attributes: model, input_tokens,
	 *                           output_tokens, finish_reason, assistant_id,
	 *                           transcript_id, provider, tool_slug.
	 * @return array|null Span payload, or null when no subscriber exists.
	 */
	public static function emit( string $operation, array $attributes = array() ): ?array {
		if ( ! has_action( self::SPAN_HOOK ) ) {
			return null;
		}

		$payload = array(
			'gen_ai.operation.name'          => sanitize_key( $operation ),
			'gen_ai.request.model'           => isset( $attributes['model'] ) ? sanitize_text_field( (string) $attributes['model'] ) : '',
			'gen_ai.usage.input_tokens'      => isset( $attributes['input_tokens'] ) ? absint( $attributes['input_tokens'] ) : 0,
			'gen_ai.usage.output_tokens'     => isset( $attributes['output_tokens'] ) ? absint( $attributes['output_tokens'] ) : 0,
			'gen_ai.response.finish_reasons' => isset( $attributes['finish_reason'] ) ? sanitize_text_field( (string) $attributes['finish_reason'] ) : '',
			'nvoos.timestamp'                => time(),
			'nvoos.assistant_id'             => isset( $attributes['assistant_id'] ) ? absint( $attributes['assistant_id'] ) : 0,
			'nvoos.transcript_id'            => isset( $attributes['transcript_id'] ) ? absint( $attributes['transcript_id'] ) : 0,
			'nvoos.provider'                 => isset( $attributes['provider'] ) ? sanitize_key( (string) $attributes['provider'] ) : '',
			'nvoos.tool_slug'                => isset( $attributes['tool_slug'] ) ? sanitize_key( (string) $attributes['tool_slug'] ) : '',
		);

		if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_event' ) ) {
			WP_MCP_AI_Logger::log_event( 'genai_span', 'GenAI span emitted.', $payload );
		}

		/**
		 * Fires for every emitted GenAI span.
		 *
		 * @since 1.2.13
		 *
		 * @param array $payload Span payload using OTel GenAI convention names.
		 */
		do_action( self::SPAN_HOOK, $payload );

		return $payload;
	}
}
