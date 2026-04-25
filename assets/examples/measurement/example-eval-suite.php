<?php
/**
 * Example: Eval Suite Registration
 *
 * Reference snippet showing how to register an eval suite via the
 * `wp_mcp_ai_register_eval_suites` hook. Companion file to
 * `example-custom-verifier.php` and `example-cli-generator.php`.
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

add_action(
	'wp_mcp_ai_register_eval_suites',
	static function ( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Eval_Suite_Registry ) {
			return;
		}

		$registry->register(
			array(
				'slug'              => 'example-suite',
				'label'             => 'Example Suite',
				'description'       => 'Demonstrates suite registration with a deterministic verifier.',
				// `generator_context` declares the candidate's provenance.
				// Verifiers that share this provenance will be refused
				// at run time — see `independence_profile` on each verifier.
				'generator_context' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4o-mini',
				),
				'cases'             => array(
					new WP_MCP_AI_Eval_Case(
						array(
							'slug'          => 'short-greeting',
							'label'         => 'Greeting must be at least 24 chars',
							'verifier_slug' => 'example_min_length',
							'input'         => array( 'prompt' => 'Greet a returning customer named Alex.' ),
							'expected'      => null, // verifier-internal.
						)
					),
					new WP_MCP_AI_Eval_Case(
						array(
							'slug'          => 'detailed-summary',
							'label'         => 'Summary must be at least 24 chars',
							'verifier_slug' => 'example_min_length',
							'input'         => array( 'prompt' => 'Summarise the contents of $POST as JSON.' ),
							'expected'      => null,
						)
					),
				),
			)
		);
	}
);
