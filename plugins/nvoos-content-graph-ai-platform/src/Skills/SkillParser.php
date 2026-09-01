<?php
/**
 * Anthropic Agent Skills SKILL.md parser.
 *
 * Parses SKILL.md files following the Agent Skills specification:
 * YAML frontmatter with name/description + Markdown instructions body.
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 * @see     https://agentskills.io/specification
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses SKILL.md content into structured skill data.
 *
 * @since 1.7.0
 */
class SkillParser {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Maximum allowed length for the skill name field.
	 *
	 * @var int
	 */
	const MAX_NAME_LENGTH = 64;

	/**
	 * Maximum allowed length for the skill description field.
	 *
	 * @var int
	 */
	const MAX_DESCRIPTION_LENGTH = 1024;

	/**
	 * Maximum allowed length for the compatibility field.
	 *
	 * @var int
	 */
	const MAX_COMPATIBILITY_LENGTH = 500;

	/**
	 * Parse a SKILL.md file from a file path.
	 *
	 * @since 1.7.0
	 * @param string $file_path Absolute path to the SKILL.md file.
	 * @return array|WP_Error Parsed skill data or error.
	 */
	public function parse_file( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_file_not_found',
				__( 'The skill file could not be found or is not readable.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file.
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_file_read_error',
				__( 'Failed to read the skill file.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		return $this->parse( $content );
	}

	/**
	 * Parse raw SKILL.md content string.
	 *
	 * Extracts YAML frontmatter and Markdown body from the Agent Skills format.
	 *
	 * @since 1.7.0
	 * @param string $content Raw SKILL.md content.
	 * @return array|WP_Error Parsed skill data with keys: name, description, instructions,
	 *                        and optional: license, compatibility, metadata, allowed_tools.
	 *                        Returns WP_Error on validation failure.
	 */
	public function parse( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_empty_content',
				__( 'Skill content is empty.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		// Extract YAML frontmatter delimited by --- markers.
		$frontmatter = $this->extract_frontmatter( $content );
		if ( is_wp_error( $frontmatter ) ) {
			return $frontmatter;
		}

		// Extract Markdown body (everything after closing ---).
		$instructions = $this->extract_instructions( $content );

		// Parse YAML frontmatter into key-value pairs.
		$metadata = $this->parse_yaml_frontmatter( $frontmatter );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		// Validate required fields.
		$validation = $this->validate_metadata( $metadata );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$disable_model_invocation = false;
		if ( isset( $metadata['disable-model-invocation'] ) ) {
			$disable_model_invocation = (bool) $metadata['disable-model-invocation'];
		}

		return array(
			'name'                     => sanitize_text_field( $metadata['name'] ),
			'description'              => sanitize_text_field( $metadata['description'] ),
			'instructions'             => wp_kses_post( $instructions ),
			'license'                  => isset( $metadata['license'] ) ? sanitize_text_field( $metadata['license'] ) : '',
			'compatibility'            => isset( $metadata['compatibility'] ) ? sanitize_text_field( $metadata['compatibility'] ) : '',
			'metadata'                 => isset( $metadata['metadata'] ) && is_array( $metadata['metadata'] ) ? $this->sanitize_metadata_array( $metadata['metadata'] ) : array(),
			'allowed_tools'            => isset( $metadata['allowed-tools'] ) ? $this->parse_allowed_tools( $metadata['allowed-tools'] ) : array(),
			'disable_model_invocation' => $disable_model_invocation,
		);
	}

	/**
	 * Extract YAML frontmatter from content.
	 *
	 * @since 1.7.0
	 * @param string $content Raw SKILL.md content.
	 * @return string|WP_Error Frontmatter string or error.
	 */
	private function extract_frontmatter( $content ) {
		// Frontmatter must start at the beginning of the file with ---.
		if ( ! preg_match( '/\A---\s*\n(.*?)\n---\s*\n?/s', $content, $matches ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_no_frontmatter',
				__( 'No valid YAML frontmatter found. SKILL.md must start with --- delimited YAML.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		return $matches[1];
	}

	/**
	 * Extract instruction body from content (everything after frontmatter).
	 *
	 * @since 1.7.0
	 * @param string $content Raw SKILL.md content.
	 * @return string Markdown instructions body.
	 */
	private function extract_instructions( $content ) {
		// Remove the frontmatter block and return the rest.
		$body = preg_replace( '/\A---\s*\n.*?\n---\s*\n?/s', '', $content );

		return trim( $body );
	}

	/**
	 * Parse simple YAML frontmatter into an associative array.
	 *
	 * Supports flat key-value pairs and one-level nested metadata maps.
	 * Does not require a full YAML parser library.
	 *
	 * @since 1.7.0
	 * @param string $yaml Raw YAML frontmatter string.
	 * @return array|WP_Error Parsed key-value pairs or error.
	 */
	private function parse_yaml_frontmatter( $yaml ) {
		$result       = array();
		$lines        = explode( "\n", $yaml );
		$current_map  = null;
		$map_contents = array();

		foreach ( $lines as $line ) {
			// Skip empty lines and comments.
			if ( '' === trim( $line ) || 0 === strpos( trim( $line ), '#' ) ) {
				continue;
			}

			// Check for nested map continuation (indented key: value under a parent key).
			if ( null !== $current_map && preg_match( '/^  +(\S+):\s*(.*)$/', $line, $nested_match ) ) {
				$nested_key   = trim( $nested_match[1] );
				$nested_value = trim( $nested_match[2], " \t\n\r\0\x0B\"'" );

				$map_contents[ $nested_key ] = $nested_value;
				continue;
			}

			// Flush any pending map.
			if ( null !== $current_map ) {
				$result[ $current_map ] = $map_contents;
				$current_map            = null;
				$map_contents           = array();
			}

			// Top-level key: value.
			if ( preg_match( '/^(\S+):\s*(.*)$/', $line, $match ) ) {
				$key   = trim( $match[1] );
				$value = trim( $match[2], " \t\n\r\0\x0B\"'" );

				if ( '' === $value ) {
					// This might be a map parent (e.g., "metadata:").
					$current_map  = $key;
					$map_contents = array();
				} else {
					$result[ $key ] = $value;
				}
			}
		}

		// Flush any remaining map.
		if ( null !== $current_map ) {
			$result[ $current_map ] = $map_contents;
		}

		return $result;
	}

	/**
	 * Validate the parsed metadata fields.
	 *
	 * @since 1.7.0
	 * @param array $metadata Parsed frontmatter metadata.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function validate_metadata( $metadata ) {
		// Name is required.
		if ( empty( $metadata['name'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_missing_name',
				__( 'Skill name is required in YAML frontmatter.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		// Validate name format: lowercase, digits, hyphens only; max 64 chars.
		$name = $metadata['name'];
		if ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_name_too_long',
				/* translators: %d: maximum allowed length */
				sprintf( __( 'Skill name must not exceed %d characters.', 'nvoos-content-graph-ai-platform' ), self::MAX_NAME_LENGTH ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name ) || false !== strpos( $name, '--' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_invalid_name',
				__( 'Skill name must contain only lowercase letters, digits, and hyphens. It cannot start/end with a hyphen or contain consecutive hyphens.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		// Description is required.
		if ( empty( $metadata['description'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_missing_description',
				__( 'Skill description is required in YAML frontmatter.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $metadata['description'] ) > self::MAX_DESCRIPTION_LENGTH ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_description_too_long',
				/* translators: %d: maximum allowed length */
				sprintf( __( 'Skill description must not exceed %d characters.', 'nvoos-content-graph-ai-platform' ), self::MAX_DESCRIPTION_LENGTH ),
				array( 'status' => 400 )
			);
		}

		// Validate optional compatibility length.
		if ( ! empty( $metadata['compatibility'] ) && strlen( $metadata['compatibility'] ) > self::MAX_COMPATIBILITY_LENGTH ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_compatibility_too_long',
				/* translators: %d: maximum allowed length */
				sprintf( __( 'Skill compatibility field must not exceed %d characters.', 'nvoos-content-graph-ai-platform' ), self::MAX_COMPATIBILITY_LENGTH ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Parse allowed-tools space-delimited string into an array.
	 *
	 * @since 1.7.0
	 * @param string $tools_string Space-delimited tools string.
	 * @return array Array of tool name strings.
	 */
	private function parse_allowed_tools( $tools_string ) {
		if ( ! is_string( $tools_string ) || '' === trim( $tools_string ) ) {
			return array();
		}

		$tools = preg_split( '/\s+/', trim( $tools_string ) );

		return array_values( array_filter( array_map( 'sanitize_text_field', $tools ) ) );
	}

	/**
	 * Sanitize a metadata key-value array.
	 *
	 * @since 1.7.0
	 * @param array $metadata_array Raw metadata associative array.
	 * @return array Sanitized metadata array.
	 */
	private function sanitize_metadata_array( $metadata_array ) {
		$sanitized = array();

		foreach ( $metadata_array as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
		}

		return $sanitized;
	}
}
