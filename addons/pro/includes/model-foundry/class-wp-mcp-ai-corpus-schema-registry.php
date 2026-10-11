<?php
/**
 * Corpus schema registry — Model Foundry (Pro, Phase 1).
 *
 * Defines the versioned row schemas every corpus exporter emits and validates
 * rows against them. A schema is a small, stable contract between the Corpus
 * Foundry (writer) and any downstream trainer (reader): trainers consume
 * `train.jsonl` + `holdout.jsonl`, and the schema key names the row shape.
 *
 * Schemas (frozen on first ship — new shapes get a new key, never a mutated
 * one):
 *
 * - `chat_v1`          — OpenAI chat-format rows: {"messages":[{role,content},…]}.
 * - `trajectory_v1`    — OpenAI tool-calling rows: messages may carry
 *                        `tool_calls` on assistant messages and `tool_call_id`
 *                        on tool messages.
 * - `preference_v1`    — DPO rows: {"prompt": string, "chosen": string,
 *                        "rejected": string} (exact fields — R4).
 * - `docs_v1`          — plain-text document rows: {"text": string,
 *                        "meta": {source,path,hash,bytes}}.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned corpus row-schema registry.
 */
class WP_MCP_AI_Corpus_Schema_Registry {

	/**
	 * Schema definitions, keyed by schema slug.
	 *
	 * @return array<string,array{format:string,description:string,row_keys:array<int,string>}>
	 */
	public static function get_schemas() {
		return array(
			'chat_v1'       => array(
				'format'      => 'openai_chat_jsonl',
				'description' => __( 'OpenAI chat-format conversation rows: one "messages" array per line.', 'mcp-ai-wpoos-pro' ),
				'row_keys'    => array( 'messages' ),
			),
			'trajectory_v1' => array(
				'format'      => 'openai_chat_jsonl',
				'description' => __( 'OpenAI tool-calling rows: assistant messages may carry "tool_calls"; tool messages carry "tool_call_id".', 'mcp-ai-wpoos-pro' ),
				'row_keys'    => array( 'messages' ),
			),
			'preference_v1' => array(
				'format'      => 'openai_preference_jsonl',
				'description' => __( 'DPO preference rows: exactly "prompt", "chosen", "rejected" string fields (no lists).', 'mcp-ai-wpoos-pro' ),
				'row_keys'    => array( 'prompt', 'chosen', 'rejected' ),
			),
			'docs_v1'       => array(
				'format'      => 'text_jsonl',
				'description' => __( 'Plain-text document rows: "text" plus a "meta" provenance object.', 'mcp-ai-wpoos-pro' ),
				'row_keys'    => array( 'text', 'meta' ),
			),
		);
	}

	/**
	 * Whether a schema key is registered.
	 *
	 * @param string $key Schema key.
	 * @return bool
	 */
	public static function exists( $key ) {
		$schemas = self::get_schemas();
		return isset( $schemas[ sanitize_key( (string) $key ) ] );
	}

	/**
	 * Fetch a schema definition.
	 *
	 * @param string $key Schema key.
	 * @return array<string,mixed>|null Definition, or null when unknown.
	 */
	public static function get( $key ) {
		$schemas = self::get_schemas();
		$key     = sanitize_key( (string) $key );
		return isset( $schemas[ $key ] ) ? $schemas[ $key ] : null;
	}

	/**
	 * Validate a row against a schema.
	 *
	 * @param string       $key  Schema key.
	 * @param array<mixed> $row  Row payload.
	 * @return true|WP_Error True when valid, WP_Error otherwise.
	 */
	public static function validate_row( $key, array $row ) {
		$schema = self::get( $key );
		if ( null === $schema ) {
			return new WP_Error(
				'wp_mcp_ai_unknown_corpus_schema',
				/* translators: %s: schema key */
				sprintf( __( 'Unknown corpus schema "%s".', 'mcp-ai-wpoos-pro' ), sanitize_key( (string) $key ) )
			);
		}

		foreach ( $schema['row_keys'] as $required_key ) {
			if ( ! array_key_exists( $required_key, $row ) ) {
				return new WP_Error(
					'wp_mcp_ai_corpus_row_missing_key',
					/* translators: 1: row key, 2: schema key */
					sprintf( __( 'Corpus row is missing required key "%1$s" for schema "%2$s".', 'mcp-ai-wpoos-pro' ), $required_key, sanitize_key( (string) $key ) )
				);
			}
		}

		if ( 'preference_v1' === $key ) {
			foreach ( array( 'prompt', 'chosen', 'rejected' ) as $field ) {
				if ( ! is_string( $row[ $field ] ) || '' === trim( $row[ $field ] ) ) {
					return new WP_Error(
						'wp_mcp_ai_preference_row_invalid',
						/* translators: %s: field name */
						sprintf( __( 'Preference field "%s" must be a non-empty string (R4).', 'mcp-ai-wpoos-pro' ), $field )
					);
				}
			}
		}

		if ( 'docs_v1' === $key && ! is_string( $row['text'] ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_row_invalid', __( 'Docs row "text" must be a string.', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}
}
