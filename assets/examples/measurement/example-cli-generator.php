<?php
/**
 * Example: CLI Generator Wiring
 *
 * Reference snippet showing how to provide a generator callable to
 * `wp mcp-ai measurement run <suite>`. The CLI command resolves the
 * generator via the `wp_mcp_ai_cli_measurement_generator` filter so
 * the same suite can be reused with multiple generators (live model,
 * replay fixture, deterministic stub for CI smoke tests).
 *
 * Not autoloaded by the plugin — copy into your site-glue plugin.
 *
 * @package WP_MCP_AI_Examples
 * @since   1.3.0
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wp_mcp_ai_cli_measurement_generator',
	static function ( $existing, $suite_slug ) {
		// Already resolved by another listener — respect it.
		if ( is_callable( $existing ) ) {
			return $existing;
		}

		// Route per-suite. Real-world generators dispatch a chat call
		// against an assistant; this stub returns deterministic text
		// so CI can smoke-test the runner without burning tokens.
		if ( 'example-suite' === $suite_slug ) {
			return static function ( $case, $context ) {
				unset( $context );

				$prompt = '';
				if ( $case instanceof WP_MCP_AI_Eval_Case ) {
					$input  = $case->get_input();
					$prompt = isset( $input['prompt'] ) ? (string) $input['prompt'] : '';
				}

				// Minimal deterministic completion: echo the prompt back
				// with a fixed prefix so the min-length verifier passes.
				$text = 'Acknowledged: ' . $prompt;

				return array(
					'text'    => $text,
					'subject' => array( 'text' => $text ),
				);
			};
		}

		return $existing;
	},
	10,
	2
);
