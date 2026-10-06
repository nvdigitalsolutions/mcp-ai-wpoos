<?php
/**
 * WP-CLI model catalog commands for NV oOS.
 *
 * Operator surface for the model catalog: list models per provider, review
 * the discovery suggestions, and trigger a discovery run.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Inspect and refresh the NV oOS model catalog from the command line.
 *
 * ## EXAMPLES
 *
 *     # List every OpenAI model in the catalog.
 *     $ wp mcp-ai model list --provider=openai
 *
 *     # Review the latest discovery diff.
 *     $ wp mcp-ai model suggestions
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CLI_Model_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List catalog models for a provider.
	 *
	 * ## OPTIONS
	 *
	 * --provider=<provider>
	 * : Provider slug (e.g. openai, anthropic, gemini, ollama).
	 *
	 * [--search=<term>]
	 * : Filter models whose ID contains the term.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: model_id,name).
	 *
	 * ## EXAMPLES
	 *
	 *     # List OpenAI models.
	 *     $ wp mcp-ai model list --provider=openai
	 *
	 *     # Find Gemini models containing "flash".
	 *     $ wp mcp-ai model list --provider=gemini --search=flash
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$provider = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );
		$search   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'search', '' );
		$format   = $this->get_format( $assoc_args );
		$fields   = $this->get_fields( $assoc_args, array( 'model_id', 'name' ) );

		if ( '' === $provider ) {
			WP_CLI::error( __( 'Please provide --provider=<provider>.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			WP_CLI::error( __( 'Model service is not available.', 'mcp-ai-wpoos' ) );
		}

		$service = new WP_MCP_AI_Model_Service();
		$models  = $service->get_models_for_provider( sanitize_key( $provider ) );

		if ( empty( $models ) ) {
			/* translators: %s: provider slug */
			WP_CLI::log( sprintf( __( 'No models found for provider "%s".', 'mcp-ai-wpoos' ), $provider ) );
			return;
		}

		$items = array();
		foreach ( $models as $model_id => $name ) {
			if ( '' !== $search && false === stripos( (string) $model_id, (string) $search ) ) {
				continue;
			}
			$items[] = array(
				'model_id' => $model_id,
				'name'     => $name,
			);
		}

		if ( empty( $items ) ) {
			/* translators: %s: search term */
			WP_CLI::log( sprintf( __( 'No models match "%s".', 'mcp-ai-wpoos' ), $search ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'model_id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Show the latest model catalog discovery diff.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Review pending additions, sunsets, and price changes.
	 *     $ wp mcp-ai model suggestions
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function suggestions( $args, $assoc_args ) {
		$format = $this->get_format( $assoc_args );

		if ( ! class_exists( 'WP_MCP_AI_Model_Discovery_Service' ) ) {
			WP_CLI::error( __( 'Model discovery service is not available.', 'mcp-ai-wpoos' ) );
		}

		$diff = get_option( WP_MCP_AI_Model_Discovery_Service::SUGGESTIONS_OPTION, array() );

		if ( empty( $diff ) || ! is_array( $diff ) ) {
			WP_CLI::log( __( 'No discovery suggestions recorded. Run `wp mcp-ai model discover`.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( array( 'additions', 'sunsets', 'price_changes' ) as $bucket ) {
			foreach ( (array) ( $diff[ $bucket ] ?? array() ) as $entry ) {
				$items[] = array(
					'kind'     => rtrim( $bucket, 's' ),
					'provider' => isset( $entry['provider'] ) ? $entry['provider'] : '',
					'model_id' => isset( $entry['model_id'] ) ? $entry['model_id'] : '',
					'status'   => isset( $entry['status'] ) ? $entry['status'] : '',
				);
			}
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( $format, array( $diff ), array( 'additions', 'sunsets', 'price_changes', 'errors', 'status', 'generated_at' ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( __( 'Catalog is up to date — no additions, sunsets, or price changes.', 'mcp-ai-wpoos' ) );
			return;
		}

		/* translators: 1: additions, 2: sunsets, 3: price changes */
		WP_CLI::log(
			sprintf(
				__( 'Discovery diff: %1$d addition(s), %2$d sunset(s), %3$d price change(s).', 'mcp-ai-wpoos' ),
				count( (array) ( $diff['additions'] ?? array() ) ),
				count( (array) ( $diff['sunsets'] ?? array() ) ),
				count( (array) ( $diff['price_changes'] ?? array() ) )
			)
		);

		\WP_CLI\Utils\format_items( 'table', $items, array( 'kind', 'provider', 'model_id', 'status' ) );

		if ( ! empty( $diff['errors'] ) ) {
			WP_CLI::warning( __( 'Errors during discovery:', 'mcp-ai-wpoos' ) );
			foreach ( $diff['errors'] as $provider => $message ) {
				WP_CLI::warning( sprintf( '  %s: %s', $provider, $message ) );
			}
		}
	}

	/**
	 * Run a model catalog discovery pass.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<csv>]
	 * : Comma-separated provider slugs. Default: all enabled providers.
	 *
	 * [--dry-run]
	 * : Fetch and diff without persisting suggestions.
	 *
	 * ## EXAMPLES
	 *
	 *     # Discover for all enabled providers.
	 *     $ wp mcp-ai model discover
	 *
	 *     # Preview without persisting.
	 *     $ wp mcp-ai model discover --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function discover( $args, $assoc_args ) {
		$this->require_capability( 'manage_options' );

		if ( ! class_exists( 'WP_MCP_AI_Model_Discovery_Service' ) ) {
			WP_CLI::error( __( 'Model discovery service is not available.', 'mcp-ai-wpoos' ) );
		}

		$providers = array();
		$raw       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );
		if ( '' !== $raw ) {
			$providers = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		}

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( $dry_run ) {
			WP_CLI::log( WP_CLI::colorize( '%yDRY RUN MODE - Suggestions will not be persisted%n' ) );
		}

		$service = new WP_MCP_AI_Model_Discovery_Service();
		$diff    = $service->run( $providers, array( 'persist' => ! $dry_run ) );

		WP_CLI::log(
			sprintf(
				/* translators: 1: additions, 2: sunsets, 3: price changes */
				__( 'Discovery complete (%1$d addition(s), %2$d sunset(s), %3$d price change(s)).', 'mcp-ai-wpoos' ),
				count( $diff['additions'] ),
				count( $diff['sunsets'] ),
				count( $diff['price_changes'] )
			)
		);

		if ( ! empty( $diff['errors'] ) ) {
			foreach ( $diff['errors'] as $provider => $message ) {
				/* translators: 1: provider slug, 2: error message */
				WP_CLI::warning( sprintf( __( '%1$s: %2$s', 'mcp-ai-wpoos' ), $provider, $message ) );
			}
			WP_CLI::halt( 'partial' === $diff['status'] ? 1 : 0 );
			return;
		}

		WP_CLI::success( __( 'Discovery finished. Review with `wp mcp-ai model suggestions`.', 'mcp-ai-wpoos' ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai model', 'WP_MCP_AI_CLI_Model_Command' );
}
