<?php
/**
 * WP-CLI command for auto-categorizing content.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Auto-categorize WordPress content using AI.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_CLI_Content_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Auto-categorize posts based on content analysis.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post-type>]
	 * : Post type to categorize (default: post).
	 * ---
	 * default: post
	 * ---
	 *
	 * [--post-status=<status>]
	 * : Post status filter (default: publish).
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Taxonomy to use (default: category).
	 * ---
	 * default: category
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum number of posts to process.
	 *
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch (default: 20).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--min-confidence=<score>]
	 * : Minimum confidence score (0-1) for category assignment (default: 0.6).
	 * ---
	 * default: 0.6
	 * ---
	 *
	 * [--dry-run]
	 * : Preview categorization without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Categorize all published posts (dry run)
	 *     $ wp mcp-ai content auto-categorize --dry-run
	 *
	 *     # Categorize up to 50 posts
	 *     $ wp mcp-ai content auto-categorize --limit=50 --yes
	 *
	 *     # Categorize custom post type
	 *     $ wp mcp-ai content auto-categorize --post-type=article --taxonomy=article_category
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function auto_categorize( $args, $assoc_args ) {
		$this->start_timer();

		// Parse arguments.
		$post_type      = $assoc_args['post-type'] ?? 'post';
		$post_status    = $assoc_args['post-status'] ?? 'publish';
		$taxonomy       = $assoc_args['taxonomy'] ?? 'category';
		$limit          = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$batch_size     = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 20;
		$min_confidence = isset( $assoc_args['min-confidence'] ) ? floatval( $assoc_args['min-confidence'] ) : 0.6;
		$dry_run        = $this->is_dry_run( $assoc_args );

		// Validate taxonomy.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->error(
				sprintf(
					/* translators: %s: taxonomy name */
					__( 'Taxonomy "%s" does not exist.', 'mcp-ai-wpoos' ),
					$taxonomy
				)
			);
		}

		// Validate post type.
		if ( ! post_type_exists( $post_type ) ) {
			$this->error(
				sprintf(
					/* translators: %s: post type name */
					__( 'Post type "%s" does not exist.', 'mcp-ai-wpoos' ),
					$post_type
				)
			);
		}

		// Get posts to categorize.
		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $query_args );

		if ( empty( $post_ids ) ) {
			$this->warning( __( 'No posts found to categorize.', 'mcp-ai-wpoos' ) );
			return;
		}

		// Display summary.
		$this->info(
			sprintf(
				/* translators: %d: number of posts */
				__( 'Found %d posts to categorize.', 'mcp-ai-wpoos' ),
				count( $post_ids )
			)
		);

		if ( $dry_run ) {
			$this->dry_run_notice();
		}

		// Confirm.
		if ( ! $dry_run && ! $this->confirm( __( 'Continue?', 'mcp-ai-wpoos' ), $assoc_args ) ) {
			$this->warning( __( 'Operation cancelled.', 'mcp-ai-wpoos' ) );
			return;
		}

		// Get tool instance.
		$tool = $this->get_tool();
		if ( is_wp_error( $tool ) ) {
			$this->error( $tool->get_error_message() );
		}

		// Process posts in batches.
		$results = $this->batch_process(
			$post_ids,
			function ( $post_id ) use ( $tool, $taxonomy, $min_confidence, $dry_run ) {
				return $this->categorize_post( $post_id, $tool, $taxonomy, $min_confidence, $dry_run );
			},
			array(
				'batch_size'     => $batch_size,
				'progress_label' => __( 'Categorizing posts', 'mcp-ai-wpoos' ),
				'dry_run'        => $dry_run,
				'stop_on_error'  => false,
			)
		);

		// Display summary.
		$this->display_summary( $results );

		if ( $results['success_count'] > 0 ) {
			$this->success(
				sprintf(
					/* translators: %d: number of posts */
					__( 'Successfully categorized %d posts.', 'mcp-ai-wpoos' ),
					$results['success_count']
				)
			);
		}
	}

	/**
	 * Categorize a single post.
	 *
	 * @param int    $post_id        Post ID.
	 * @param object $tool           Tool instance.
	 * @param string $taxonomy       Taxonomy name.
	 * @param float  $min_confidence Minimum confidence score.
	 * @param bool   $dry_run        Dry run mode.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function categorize_post( $post_id, $tool, $taxonomy, $min_confidence, $dry_run ) {
		$result = $tool->execute(
			array(
				'post_id'        => $post_id,
				'taxonomy'       => $taxonomy,
				'min_confidence' => $min_confidence,
				'auto_assign'    => ! $dry_run,
				'max_categories' => 3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Log categorization.
		$post = get_post( $post_id );
		$categories = $result['suggestions'] ?? array();

		if ( ! empty( $categories ) ) {
			$category_names = wp_list_pluck( $categories, 'name' );
			$this->debug(
				sprintf(
					/* translators: %1$s: post title, %2$s: category names */
					__( '"%1$s" → %2$s', 'mcp-ai-wpoos' ),
					$post->post_title,
					implode( ', ', $category_names )
				)
			);
		}

		return true;
	}

	/**
	 * Get auto-categorize tool instance.
	 *
	 * @return object|WP_Error Tool instance.
	 */
	private function get_tool() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Auto_Categorize_Content' ) ) {
			return new WP_Error(
				'tool_not_found',
				__( 'Auto-categorize tool not found. Please ensure the plugin is properly installed.', 'mcp-ai-wpoos' )
			);
		}

		return new WP_MCP_AI_Tool_Auto_Categorize_Content();
	}
}

// Register command.
WP_CLI::add_command( 'mcp-ai content', 'WP_MCP_AI_CLI_Content_Command' );
