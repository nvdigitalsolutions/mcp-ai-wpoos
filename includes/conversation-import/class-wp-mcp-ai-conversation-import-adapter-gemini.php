<?php
/**
 * Google Gemini (Takeout "My Activity") export adapter.
 *
 * Parses the activity JSON produced by Google Takeout → My Activity →
 * Gemini Apps. Activity records carry the user prompt in `title`, the model
 * response as HTML in `safeHtmlItem[].html`, and the conversation anchor in
 * `titleUrl` (`https://gemini.google.com/app/{conversation_id}`). Records are
 * grouped per conversation ID and interleaved into user/assistant turns.
 *
 * @link    https://takeout.google.com/
 * @credit  Takeout Gemini parsing approach by AminaEmenena (data-extractor), MIT.
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Google Takeout Gemini Apps activity JSON.
 */
class WP_MCP_AI_Conversation_Import_Adapter_Gemini implements WP_MCP_AI_Conversation_Import_Adapter_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_platform() {
		return 'gemini';
	}

	/**
	 * Whether the decoded structure is a Gemini Takeout activity array.
	 *
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @return bool
	 */
	public function supports_decoded( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return false;
		}

		foreach ( $decoded as $record ) {
			if ( is_array( $record ) && $this->is_gemini_record( $record ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract canonical conversations from a decoded Gemini activity export.
	 *
	 * Validation happens here (outside the generator) so invalid payloads
	 * surface as WP_Error instead of an empty generator.
	 *
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @param array $options Extraction options.
	 * @return \Traversable|\WP_Error
	 */
	public function extract( $decoded, array $options = array() ) {
		if ( ! $this->supports_decoded( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_gemini_shape',
				__( 'The file does not look like a Google Takeout Gemini activity export.', 'mcp-ai-wpoos' )
			);
		}

		return $this->extract_all( $decoded, $options );
	}

	/**
	 * Yield canonical conversations from a validated Gemini export.
	 *
	 * @param mixed $decoded Validated decoded structure.
	 * @param array $options Extraction options.
	 * @return \Generator
	 */
	protected function extract_all( $decoded, array $options ) {
		$groups = $this->group_records( $decoded );

		foreach ( $groups as $group ) {
			$conversation = $this->build_conversation( $group );
			if ( null !== $conversation ) {
				yield $conversation;
			}
		}
	}

	/**
	 * Group Gemini activity records into per-conversation buckets.
	 *
	 * Records sharing a `titleUrl` conversation ID belong together. Records
	 * without a URL are bucketed individually and sorted chronologically.
	 *
	 * @param array $decoded Decoded activity array.
	 * @return array[] Grouped and time-sorted activity records.
	 */
	protected function group_records( array $decoded ) {
		$buckets = array();
		$solo    = array();

		foreach ( $decoded as $record ) {
			if ( ! is_array( $record ) || ! $this->is_gemini_record( $record ) ) {
				continue;
			}

			$conversation_id = $this->extract_conversation_id( $record );
			if ( '' === $conversation_id ) {
				$solo[] = array( $record );
				continue;
			}

			if ( ! isset( $buckets[ $conversation_id ] ) ) {
				$buckets[ $conversation_id ] = array();
			}
			$buckets[ $conversation_id ][] = $record;
		}

		$groups = array_values( $buckets );
		foreach ( $groups as &$group ) {
			$group = $this->sort_records( $group );
		}
		unset( $group );

		// Merge solo records so chronological ordering stays meaningful.
		$solo = $this->sort_records( array_merge( ...$solo ) );
		foreach ( $solo as $record ) {
			$groups[] = array( $record );
		}

		usort(
			$groups,
			function ( $a, $b ) {
				$ts_a = $this->record_timestamp( $a[0] );
				$ts_b = $this->record_timestamp( $b[0] );

				return $ts_a <=> $ts_b;
			}
		);

		return $groups;
	}

	/**
	 * Build one canonical conversation from a grouped set of records.
	 *
	 * @param array[] $group Chronologically sorted Gemini activity records.
	 * @return WP_MCP_AI_Conversation_Import_Conversation|null
	 */
	protected function build_conversation( array $group ) {
		$messages  = array();
		$source_id = '';
		$title     = '';
		$created   = 0;
		$updated   = 0;
		$model     = 'gemini';

		foreach ( $group as $record ) {
			$timestamp = $this->record_timestamp( $record );
			if ( 0 === $created || $timestamp < $created ) {
				$created = $timestamp;
			}
			if ( $timestamp > $updated ) {
				$updated = $timestamp;
			}

			if ( '' === $source_id ) {
				$source_id = $this->extract_conversation_id( $record );
			}
			if ( '' === $title && isset( $record['title'] ) && is_string( $record['title'] ) ) {
				$title = sanitize_text_field( $record['title'] );
			}

			$prompt = isset( $record['title'] ) && is_string( $record['title'] )
				? sanitize_text_field( $record['title'] )
				: '';
			if ( '' !== $prompt ) {
				$messages[] = array(
					'role'      => WP_MCP_AI_Conversation_Import_Conversation::ROLE_USER,
					'content'   => $prompt,
					'timestamp' => $timestamp,
					'hidden'    => false,
					'metadata'  => array(),
				);
			}

			$response = $this->extract_response( $record );
			if ( '' !== $response ) {
				$messages[] = array(
					'role'      => WP_MCP_AI_Conversation_Import_Conversation::ROLE_ASSISTANT,
					'content'   => $response,
					'timestamp' => $timestamp,
					'hidden'    => false,
					'metadata'  => array(),
				);
			}
		}

		if ( empty( $messages ) ) {
			return null;
		}

		if ( '' === $source_id ) {
			$source_id = substr( sha1( $title . '|' . $created ), 0, 16 );
		}

		if ( '' === $title ) {
			$title = $source_id;
		}

		return WP_MCP_AI_Conversation_Import_Conversation::create(
			$this->get_platform(),
			$source_id,
			$title,
			$created,
			$updated,
			$model,
			$messages
		);
	}

	/**
	 * Whether a decoded record belongs to Gemini Apps activity.
	 *
	 * @param array $record Raw activity record.
	 * @return bool
	 */
	protected function is_gemini_record( array $record ) {
		if ( isset( $record['header'] ) && is_string( $record['header'] ) ) {
			if ( false !== stripos( $record['header'], 'gemini' ) ) {
				return true;
			}
		}

		if ( isset( $record['products'] ) && is_array( $record['products'] ) ) {
			foreach ( $record['products'] as $product ) {
				if ( is_string( $product ) && false !== stripos( $product, 'gemini' ) ) {
					return true;
				}
			}
		}

		if ( isset( $record['titleUrl'] ) && is_string( $record['titleUrl'] ) ) {
			if ( false !== stripos( $record['titleUrl'], 'gemini.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the conversation ID from a record's titleUrl.
	 *
	 * @param array $record Raw activity record.
	 * @return string Conversation ID, or '' when unavailable.
	 */
	protected function extract_conversation_id( array $record ) {
		if ( empty( $record['titleUrl'] ) || ! is_string( $record['titleUrl'] ) ) {
			return '';
		}

		$path = wp_parse_url( $record['titleUrl'], PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		$last     = end( $segments );

		return is_string( $last ) && '' !== $last ? sanitize_text_field( $last ) : '';
	}

	/**
	 * Sort records chronologically by their activity timestamp.
	 *
	 * @param array $records Raw activity records.
	 * @return array
	 */
	protected function sort_records( array $records ) {
		usort(
			$records,
			function ( $a, $b ) {
				return $this->record_timestamp( $a ) <=> $this->record_timestamp( $b );
			}
		);

		return $records;
	}

	/**
	 * Parse a record's ISO-8601 activity time into UTC Unix seconds.
	 *
	 * @param array $record Raw activity record.
	 * @return int
	 */
	protected function record_timestamp( array $record ) {
		if ( empty( $record['time'] ) || ! is_string( $record['time'] ) ) {
			return 0;
		}

		$parsed = strtotime( $record['time'] );

		return false === $parsed ? 0 : max( 0, $parsed );
	}

	/**
	 * Extract plain-text response content from a record's safeHtmlItem blocks.
	 *
	 * @param array $record Raw activity record.
	 * @return string
	 */
	protected function extract_response( array $record ) {
		if ( empty( $record['safeHtmlItem'] ) || ! is_array( $record['safeHtmlItem'] ) ) {
			return '';
		}

		$blocks = array();
		foreach ( $record['safeHtmlItem'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['html'] ) || ! is_string( $item['html'] ) ) {
				continue;
			}

			$html = $item['html'];
			$html = preg_replace( '#<(br|/p|/div|/li)>#i', "\n", $html );
			$text = wp_strip_all_tags( $html );
			$text = wp_specialchars_decode( $text, ENT_QUOTES );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$text = preg_replace( '/\n{3,}/', "\n\n", $text );
			$text = trim( $text );

			if ( '' !== $text ) {
				$blocks[] = $text;
			}
		}

		return implode( "\n\n", $blocks );
	}
}
