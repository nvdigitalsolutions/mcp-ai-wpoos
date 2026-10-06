<?php
/**
 * WP-CLI media studio commands for NV oOS Pro.
 *
 * Read-only parity for the NV oOS Media Studio addon: a status summary across
 * the addon, the marketplace output pipeline, and asset provenance, plus a
 * listing of the fashion batch job store (mcp_ai_fashion_job).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Inspect NV oOS Media Studio from the command line.
 *
 * ## EXAMPLES
 *
 *     # Show the media studio status dashboard.
 *     $ wp mcp-ai media-studio status
 *
 *     # List fashion batch jobs.
 *     $ wp mcp-ai media-studio list-jobs
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Media_Studio_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Show Media Studio status summary.
	 *
	 * Surfaces that are unavailable (addon inactive, missing classes, or
	 * unregistered post types) render as "not available" instead of failing.
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
	 *     # Show status as a table.
	 *     $ wp mcp-ai media-studio status
	 *
	 *     # Export status as JSON.
	 *     $ wp mcp-ai media-studio status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$info = array();

		// Addon + AI service.
		$addon_active = class_exists( 'NV_oOS_Media_Studio_Plugin' );
		$info[]       = array(
			'key'   => 'Media Studio Addon',
			'value' => $addon_active
				? ( defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ? NVOOS_MEDIA_STUDIO_VERSION : 'active' )
				: 'not available',
		);
		$info[] = array(
			'key'   => 'AI Transform Service',
			'value' => class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ? 'available' : 'not available',
		);

		// Marketplace output pipeline (dimension profiles).
		if ( class_exists( 'NV_oOS_Media_Studio_Output_Pipeline' ) ) {
			$profiles = NV_oOS_Media_Studio_Output_Pipeline::get_profiles();
			/* translators: 1: profile count, 2: comma-separated profile slugs */
			$info[] = array(
				'key'   => 'Marketplace Pipeline',
				'value' => sprintf( __( '%1$d profiles (%2$s)', 'mcp-ai-wpoos-pro' ), count( $profiles ), implode( ', ', array_keys( $profiles ) ) ),
			);
		} else {
			$info[] = array(
				'key'   => 'Marketplace Pipeline',
				'value' => 'not available',
			);
		}

		// Batch job store (fashion bridge).
		$counts = $this->get_batch_status_counts();
		if ( null === $counts ) {
			$info[] = array(
				'key'   => 'Batch Job Store',
				'value' => 'not available',
			);
			$info[] = array(
				'key'   => 'Active Batch Jobs',
				'value' => 'not available',
			);
			$info[] = array(
				'key'   => 'Completed Batch Jobs',
				'value' => 'not available',
			);
			$info[] = array(
				'key'   => 'Failed Batch Jobs',
				'value' => 'not available',
			);
		} else {
			$active = $counts['pending'] + $counts['processing'] + $counts['review'];
			$info[] = array(
				'key'   => 'Batch Job Store',
				'value' => WP_MCP_AI_Fashion_Batch::POST_TYPE,
			);
			$info[] = array(
				'key'   => 'Active Batch Jobs',
				'value' => (string) $active,
			);
			$info[] = array(
				'key'   => 'Completed Batch Jobs',
				'value' => (string) $counts['completed'],
			);
			$info[] = array(
				'key'   => 'Failed Batch Jobs',
				'value' => (string) $counts['failed'],
			);
		}

		// Asset counts (media library + AI provenance meta).
		$assets = $this->get_asset_counts();
		$info[] = array(
			'key'   => 'Media Library Assets',
			'value' => (string) $assets['attachments'],
		);
		$info[] = array(
			'key'   => 'AI-Generated Assets',
			'value' => (string) $assets['generated'],
		);
		$info[] = array(
			'key'   => 'AI-Generated Assets (7d)',
			'value' => (string) $assets['generated_7d'],
		);
		$info[] = array(
			'key'   => 'Marketplace-Processed Assets',
			'value' => (string) $assets['processed'],
		);

		// Media worker sidecar.
		if ( class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			$sidecar = NV_oOS_Media_Studio_AI_Service::is_sidecar_available() ? 'configured' : 'not configured';
		} else {
			$sidecar = 'not available';
		}
		$info[] = array(
			'key'   => 'Sidecar (Media Worker)',
			'value' => $sidecar,
		);

		if ( 'json' === $format ) {
			$data = array();
			foreach ( $info as $row ) {
				$data[ $row['key'] ] = $row['value'];
			}
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $info as $row ) {
				WP_CLI::line( "{$row['key']}: {$row['value']}" );
			}
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $info, array( 'key', 'value' ) );
	}

	/**
	 * List fashion batch jobs.
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
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: id,status,transform,done,total,created).
	 *
	 * ## EXAMPLES
	 *
	 *     # List the latest batch jobs.
	 *     $ wp mcp-ai media-studio list-jobs
	 *
	 *     # Export jobs as JSON.
	 *     $ wp mcp-ai media-studio list-jobs --format=json
	 *
	 *     # Restrict the rendered columns.
	 *     $ wp mcp-ai media-studio list-jobs --fields=id,status,transform
	 *
	 * @subcommand list-jobs
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list_jobs( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format = $this->get_format( $assoc_args );
		$fields = $this->get_fields( $assoc_args, array( 'id', 'status', 'transform', 'done', 'total', 'created' ) );

		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) || ! post_type_exists( WP_MCP_AI_Fashion_Batch::POST_TYPE ) ) {
			WP_CLI::warning( __( 'The batch job store is not available (mcp_ai_fashion_job post type is not registered).', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Fashion_Batch::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No batch jobs found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$estimate = get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_ESTIMATE, true );

			$items[] = array(
				'id'           => $post->ID,
				'status'       => sanitize_key( (string) get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_STATUS, true ) ),
				'transform'    => sanitize_key( (string) get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_TRANSFORM, true ) ),
				'total'        => absint( get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_TOTAL, true ) ),
				'done'         => absint( get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_DONE, true ) ),
				'estimate_usd' => ( '' === $estimate || null === $estimate ) ? '' : (string) (float) $estimate,
				'product_id'   => absint( get_post_meta( $post->ID, WP_MCP_AI_Fashion_Batch::META_PRODUCT_ID, true ) ),
				'created'      => get_the_date( 'Y-m-d H:i:s', $post ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Count fashion batch jobs by status meta.
	 *
	 * @return array<string,int>|null Counts keyed by status, or null when the
	 *                                batch store is unavailable.
	 */
	private function get_batch_status_counts() {
		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) || ! post_type_exists( WP_MCP_AI_Fashion_Batch::POST_TYPE ) ) {
			return null;
		}

		$job_ids = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Fashion_Batch::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'review'     => 0,
			'completed'  => 0,
			'failed'     => 0,
		);

		foreach ( (array) $job_ids as $job_id ) {
			$status = sanitize_key( (string) get_post_meta( $job_id, WP_MCP_AI_Fashion_Batch::META_STATUS, true ) );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Collect media library and AI-provenance asset counts.
	 *
	 * @return array<string,int> Keys: attachments, generated, generated_7d, processed.
	 */
	private function get_asset_counts() {
		global $wpdb;

		$totals = wp_count_posts( 'attachment' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$generated = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_nvoos_ai_generated' )
		);
		$processed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_nvoos_ai_output_profile' )
		);
		$generated_7d = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND p.post_date >= %s",
				'_nvoos_ai_generated',
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable

		return array(
			'attachments'  => isset( $totals->inherit ) ? (int) $totals->inherit : 0,
			'generated'    => $generated,
			'generated_7d' => $generated_7d,
			'processed'    => $processed,
		);
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai media-studio', 'WP_MCP_AI_Pro_CLI_Media_Studio_Command' );
}
