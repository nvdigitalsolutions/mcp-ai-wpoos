<?php
/**
 * Slash Command Prompts Bridge
 *
 * Exposes registered slash commands as MCP prompt templates
 * (`prompts/list` / `prompts/get`), following the MCP spec's own division of
 * labour: tools are model-controlled, prompts are user-controlled. Clients
 * that support MCP prompts surface these entries as user-invoked commands
 * (e.g. `/mcp__server__slash.social-post` in Claude Code), and the prompt
 * body instructs the assistant to call the underlying tool — the same tool
 * the in-chat slash command delegates to via WP_MCP_AI_Slash_Command_Tool_Adapter.
 *
 * One declarative registry (the slash-command handler) therefore feeds both
 * surfaces; no third implementation of the workflow exists.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Slash_Command_Prompts
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Slash_Command_Prompts {

	/**
	 * Prompt name prefix.
	 *
	 * @var string
	 */
	const PREFIX = 'slash.';

	/**
	 * Build prompts/list entries for every registered slash command.
	 *
	 * @since 2.2.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_prompts() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return array();
		}

		$out = array();
		foreach ( $handler->get_commands() as $name => $config ) {
			$out[] = self::build_prompt( $name, $config );
		}

		return $out;
	}

	/**
	 * Build prompts/list entries for the slash commands of one toolkit.
	 *
	 * Toolkit slugs are normalised (kebab vs snake) so both the declarative
	 * toolkit manager ('video_production') and the per-toolkit MCP servers
	 * ('video-production') can address the same commands.
	 *
	 * @since 2.2.0
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_toolkit( $toolkit_slug ) {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return array();
		}

		$wanted = self::normalize_slug( $toolkit_slug );
		$out    = array();

		foreach ( $handler->get_commands() as $name => $config ) {
			$toolkit = isset( $config['toolkit'] ) ? $config['toolkit'] : '';
			if ( '' === $toolkit || self::normalize_slug( $toolkit ) !== $wanted ) {
				continue;
			}

			$out[] = self::build_prompt( $name, $config );
		}

		return $out;
	}

	/**
	 * Render a prompts/get message for a slash-command prompt.
	 *
	 * The prompt body is a user-message instruction telling the assistant to
	 * execute the workflow through the backing tool (when one is declared) or
	 * through the chat slash-command surface otherwise. Parameters passed by
	 * the client are substituted into the invocation line.
	 *
	 * @since 2.2.0
	 *
	 * @param string $prompt_name Full prompt name (with self::PREFIX).
	 * @param array  $arguments   Client-supplied prompt arguments.
	 * @return string|null Prompt message text, or null when the prompt is unknown.
	 */
	public static function render( $prompt_name, $arguments = array() ) {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return null;
		}

		$name = self::strip_prefix( $prompt_name );
		if ( '' === $name ) {
			return null;
		}

		$config = $handler->get_command( $name );
		if ( false === $config ) {
			return null;
		}

		$invocation = '/' . $name;
		$parts      = array();

		foreach ( (array) $arguments as $key => $value ) {
			if ( is_int( $key ) || true === $value ) {
				$parts[] = '--' . sanitize_key( (string) $key );
				continue;
			}
			$parts[] = sprintf( '--%s="%s"', sanitize_key( (string) $key ), sanitize_text_field( (string) $value ) );
		}

		if ( $parts ) {
			$invocation .= ' ' . implode( ' ', $parts );
		}

		$description = isset( $config['description'] ) && '' !== $config['description']
			? (string) $config['description']
			: $name;

		$lines = array(
			sprintf(
				/* translators: 1: command description, 2: invocation */
				__( 'Task: %1$s. Execute it as %2$s.', 'mcp-ai-wpoos' ),
				$description,
				$invocation
			),
		);

		if ( ! empty( $config['tool'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: tool slug */
				__( 'Backing tool: %s. Call it with the mapped arguments and report the canonical result envelope.', 'mcp-ai-wpoos' ),
				esc_html( (string) $config['tool'] )
			);
		}

		if ( ! empty( $config['usage'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: usage example */
				__( 'Usage example: %s', 'mcp-ai-wpoos' ),
				esc_html( (string) $config['usage'] )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a single prompts/list entry for a command.
	 *
	 * @param string $name   Command name.
	 * @param array  $config Command configuration.
	 * @return array<string,mixed>
	 */
	protected static function build_prompt( $name, $config ) {
		$arguments = array();
		foreach ( (array) ( isset( $config['parameters'] ) ? $config['parameters'] : array() ) as $param => $def ) {
			$arguments[] = array(
				'name'        => sanitize_key( (string) $param ),
				'description' => isset( $def['description'] ) ? (string) $def['description'] : '',
				'required'    => ! empty( $def['required'] ),
			);
		}

		$toolkit  = isset( $config['toolkit'] ) ? sanitize_key( (string) $config['toolkit'] ) : '';
		$tool     = isset( $config['tool'] ) ? sanitize_key( (string) $config['tool'] ) : '';
		$metadata = array(
			'slash_command' => $name,
			'capability'    => isset( $config['capability'] ) ? (string) $config['capability'] : 'edit_posts',
		);

		if ( '' !== $toolkit ) {
			$metadata['toolkit'] = $toolkit;
		}
		if ( '' !== $tool ) {
			$metadata['tool'] = $tool;
		}

		return array(
			'name'        => self::PREFIX . $name,
			'description' => self::render( self::PREFIX . $name ),
			'arguments'   => $arguments,
			'metadata'    => $metadata,
		);
	}

	/**
	 * Strip the prompt-name prefix.
	 *
	 * @param string $prompt_name Full prompt name.
	 * @return string Command name (empty when the prefix does not match).
	 */
	protected static function strip_prefix( $prompt_name ) {
		$prompt_name = sanitize_text_field( (string) $prompt_name );
		if ( 0 !== strpos( $prompt_name, self::PREFIX ) ) {
			return '';
		}

		return sanitize_key( substr( $prompt_name, strlen( self::PREFIX ) ) );
	}

	/**
	 * Normalise a toolkit slug for comparison (kebab <-> snake).
	 *
	 * @param string $slug Toolkit slug.
	 * @return string Normalised slug (hyphens only).
	 */
	protected static function normalize_slug( $slug ) {
		return str_replace( '_', '-', sanitize_key( (string) $slug ) );
	}
}
