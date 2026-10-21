<?php
/**
 * Research extractor for the console Records feature (Proposal 084
 * Phase 4, W11 slice 3 — create-from-research).
 *
 * Subclasses the base deep-research tool so the provider chain, merged
 * settings, and content normalization live in one place: the endpoint
 * runs a deep_research pass, then this class maps the report onto the
 * entity's schema fields with a single bounded completion.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Deep_Research' ) && file_exists( WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-deep-research.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-deep-research.php';
}

/**
 * Maps a research report onto an entity's schema fields via one
 * provider completion.
 */
class WP_MCP_AI_Pro_Records_Research_Extractor extends WP_MCP_AI_Tool_Deep_Research {

	/**
	 * Max report characters fed to the extraction call (bounds the
	 * prompt token cost).
	 *
	 * @var int
	 */
	const MAX_REPORT_CHARS = 12000;

	/**
	 * Extract a title + schema fields from a research report.
	 *
	 * @since 2.4.0
	 * @param array  $entity_config Entity config (columns list from the schema endpoint).
	 * @param string $query         The original research query.
	 * @param string $report        Research report text.
	 * @return array|WP_Error Fields keyed by meta key with a `title` entry.
	 */
	public function extract_fields( $entity_config, $query, $report ) {
		$columns = isset( $entity_config['columns'] ) && is_array( $entity_config['columns'] ) ? $entity_config['columns'] : array();
		if ( empty( $columns ) ) {
			return new WP_Error(
				'records_no_schema',
				__( 'The entity has no configured fields to extract.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$contract = array();
		$allowed  = array( 'title' );
		foreach ( $columns as $column ) {
			if ( empty( $column['key'] ) ) {
				continue;
			}
			$key        = sanitize_key( $column['key'] );
			$label      = isset( $column['label'] ) ? sanitize_text_field( $column['label'] ) : $key;
			$type       = isset( $column['type'] ) ? sanitize_key( $column['type'] ) : 'string';
			$contract[] = sprintf( '%s (%s, %s)', $key, $label, $type );
			$allowed[]  = $key;
		}

		$report = is_string( $report ) ? $report : '';
		$report = substr( $report, 0, self::MAX_REPORT_CHARS );

		$system = __( 'You extract structured CRM fields from research reports. Return ONLY a valid JSON object, no prose, no markdown fences.', 'mcp-ai-wpoos-pro' );
		$prompt = sprintf(
			/* translators: 1: query, 2: field contract. */
			__( "Research query: %1\$s\n\nExtract a JSON object with exactly these keys:\n\"title\" (string) and %2\$s.\nUse null for unknown values. Return only the JSON object.\n\nResearch report:\n%3\$s", 'mcp-ai-wpoos-pro' ),
			$query,
			implode( ', ', $contract ),
			$report
		);

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$ai_setup = $this->get_ai_setup();
		if ( is_wp_error( $ai_setup ) ) {
			return $ai_setup;
		}

		$result = $ai_setup['client']->create_chat_completion(
			$messages,
			array(
				'model'       => $ai_setup['model'],
				'temperature' => 0.2,
				'max_tokens'  => 800,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = '';
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			$content = $this->extract_text_content( $result['choices'][0]['message']['content'] );
		} elseif ( isset( $result['reasoning_content'] ) && is_string( $result['reasoning_content'] ) ) {
			$content = trim( $result['reasoning_content'] );
		}

		$fields = $this->parse_fields_json( $content, $allowed );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return $fields;
	}

	/**
	 * Parse the completion's JSON payload, keeping only allowed keys.
	 *
	 * Public so the PHPUnit suite can exercise it without a provider.
	 *
	 * @since 2.4.0
	 * @param string   $raw_content Raw completion content.
	 * @param string[] $allowed     Allowed top-level keys.
	 * @return array|WP_Error Parsed fields (strings only) or error.
	 */
	public function parse_fields_json( $raw_content, $allowed ) {
		$raw_content = is_string( $raw_content ) ? trim( $raw_content ) : '';
		if ( '' === $raw_content ) {
			return new WP_Error(
				'records_empty_extraction',
				__( 'The provider returned an empty extraction.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 502 )
			);
		}

		// Strip markdown fences when present.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $raw_content, $matches ) ) {
			$raw_content = trim( $matches[1] );
		} else {
			$start = strpos( $raw_content, '{' );
			$end   = strrpos( $raw_content, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$raw_content = substr( $raw_content, $start, $end - $start + 1 );
			}
		}

		$decoded = json_decode( $raw_content, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'records_invalid_extraction',
				__( 'The provider did not return valid JSON for the extraction.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 502 )
			);
		}

		$fields = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$value          = $decoded[ $key ];
				$fields[ $key ] = is_scalar( $value ) && null !== $value ? sanitize_text_field( (string) $value ) : '';
			}
		}

		return $fields;
	}
}
