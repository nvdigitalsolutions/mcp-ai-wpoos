<?php
/**
 * Tool for mining/bulk-ingesting agent memory from various sources.
 *
 * MemPalace-inspired Phase 2 enhancement. Takes a source (post type query,
 * URL list, or raw text array), chunks long content, and bulk-creates
 * verbatim memory records scoped to a chosen wing/room.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk-ingest memory records from posts, URLs, or raw text.
 *
 * Reuses {@see WP_MCP_AI_Tool_Store_Agent_Context} for the actual writes so
 * sanitization, the verbatim contract, and the
 * `wp_mcp_ai_memory_pre_store_transform` filter all behave identically to a
 * single-record store.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Mine_Agent_Memory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Default maximum characters per chunk when splitting long content.
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 4000;

	/**
	 * Hard upper bound on total records created in a single mining run.
	 *
	 * @var int
	 */
	const MAX_RECORDS_PER_RUN = 200;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'mine_agent_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Mine Agent Memory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk-ingests memory records from a source (WordPress post type query, list of URLs, or raw text items) into the agent memory store. Each item is stored as a verbatim record so the original wording is preserved, optionally scoped to a wing/room. Long content is chunked. Mirrors MemPalace\'s "mine" workflow.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'     => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier.', 'mcp-ai-wpoos' ),
				),
				'source'       => array(
					'type'        => 'string',
					'description' => __( 'Where to mine from. "posts" runs a WP_Query against the chosen post type; "urls" fetches a list of URLs via the existing URL ingestion path; "text" stores each provided text item verbatim.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'posts', 'urls', 'text' ),
				),
				'post_query'   => array(
					'type'        => 'object',
					'description' => __( 'When source=posts, the WP_Query arguments. Defaults: post_type=post, post_status=publish, posts_per_page=20.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'post_type'      => array(
							'type'        => array( 'string', 'array' ),
							'description' => __( 'Post type(s) to query.', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'post_status'    => array(
							'type'        => array( 'string', 'array' ),
							'description' => __( 'Post status(es) to include.', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'posts_per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of posts to mine.', 'mcp-ai-wpoos' ),
							'minimum'     => 1,
							'maximum'     => self::MAX_RECORDS_PER_RUN,
						),
						's'              => array(
							'type'        => 'string',
							'description' => __( 'Optional search keyword.', 'mcp-ai-wpoos' ),
						),
						'category'       => array(
							'type'        => 'integer',
							'description' => __( 'Optional category ID.', 'mcp-ai-wpoos' ),
						),
					),
				),
				'urls'         => array(
					'type'        => 'array',
					'description' => __( 'When source=urls, the list of URLs to fetch and store.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'items'        => array(
					'type'        => 'array',
					'description' => __( 'When source=text, the list of items to store verbatim. Each item must have a title and content; tags and metadata are optional.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'    => array( 'type' => 'string' ),
							'content'  => array( 'type' => 'string' ),
							'tags'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'metadata' => array( 'type' => 'object' ),
						),
					),
				),
				'wing'         => array(
					'type'        => 'string',
					'description' => __( 'Optional wing (project/person scope) applied to every mined record.', 'mcp-ai-wpoos' ),
				),
				'room'         => array(
					'type'        => 'string',
					'description' => __( 'Optional room (topic cluster within a wing) applied to every mined record.', 'mcp-ai-wpoos' ),
				),
				'context_type' => array(
					'type'        => 'string',
					'description' => __( 'Context type to use for every mined record. Defaults to "note".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'learning', 'fact', 'preference', 'pattern', 'workflow', 'decision', 'result', 'insight', 'note', 'generic' ),
					'default'     => 'note',
				),
				'tags'         => array(
					'type'        => 'array',
					'description' => __( 'Tags applied to every mined record (in addition to any per-item tags).', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'importance'   => array(
					'type'        => 'string',
					'description' => __( 'Importance level for every mined record.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
					'default'     => 'medium',
				),
				'chunk_size'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum characters per record. Items longer than this are split into multiple sequential records sharing the same title (with a chunk index suffix).', 'mcp-ai-wpoos' ),
					'minimum'     => 500,
					'maximum'     => 16000,
					'default'     => self::DEFAULT_CHUNK_SIZE,
				),
				'verbatim'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to store records verbatim (skipping the pre-store transform filter). Defaults to true — mining is intended for raw ingestion.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'ttl'          => array(
					'type'        => 'integer',
					'description' => __( 'Time-to-live in seconds for every mined record.', 'mcp-ai-wpoos' ),
					'minimum'     => 3600,
					'maximum'     => 31536000,
				),
				'dry_run'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, plans the mining run and reports what would be stored without writing anything.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'agent_id', 'source' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['agent_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID is required.', 'mcp-ai-wpoos' ),
			);
		}
		if ( empty( $arguments['source'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Source is required.', 'mcp-ai-wpoos' ),
			);
		}

		$agent_id     = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$source       = sanitize_key( $arguments['source'] );
		$wing         = isset( $arguments['wing'] ) ? sanitize_text_field( $arguments['wing'] ) : '';
		$room         = isset( $arguments['room'] ) ? sanitize_text_field( $arguments['room'] ) : '';
		$context_type = isset( $arguments['context_type'] ) ? sanitize_key( $arguments['context_type'] ) : 'note';
		$importance   = isset( $arguments['importance'] ) ? sanitize_key( $arguments['importance'] ) : 'medium';
		$base_tags    = isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ? array_map( 'sanitize_text_field', $arguments['tags'] ) : array();
		$chunk_size   = isset( $arguments['chunk_size'] ) ? max( 500, min( 16000, absint( $arguments['chunk_size'] ) ) ) : self::DEFAULT_CHUNK_SIZE;
		$verbatim     = isset( $arguments['verbatim'] ) ? (bool) $arguments['verbatim'] : true;
		$dry_run      = ! empty( $arguments['dry_run'] );
		$ttl          = isset( $arguments['ttl'] ) ? max( 3600, min( 31536000, absint( $arguments['ttl'] ) ) ) : 0;

		// Collect raw items from the chosen source.
		switch ( $source ) {
			case 'posts':
				$items = $this->collect_from_posts( isset( $arguments['post_query'] ) && is_array( $arguments['post_query'] ) ? $arguments['post_query'] : array() );
				break;
			case 'urls':
				$items = $this->collect_from_urls( isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ? $arguments['urls'] : array() );
				break;
			case 'text':
				$items = $this->collect_from_text( isset( $arguments['items'] ) && is_array( $arguments['items'] ) ? $arguments['items'] : array() );
				break;
			default:
				return array(
					'success' => false,
					/* translators: %s: source value */
					'message' => sprintf( __( 'Unsupported source "%s".', 'mcp-ai-wpoos' ), $source ),
				);
		}

		if ( is_wp_error( $items ) ) {
			return array(
				'success' => false,
				'message' => $items->get_error_message(),
			);
		}

		if ( empty( $items ) ) {
			return array(
				'success'   => true,
				'message'   => __( 'No items found to mine.', 'mcp-ai-wpoos' ),
				'mined'     => array(),
				'count'     => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'dry_run'   => $dry_run,
			);
		}

		// Chunk long items.
		$prepared = array();
		foreach ( $items as $item ) {
			if ( empty( $item['title'] ) || empty( $item['content'] ) ) {
				continue;
			}
			$chunks = $this->chunk_text( (string) $item['content'], $chunk_size );
			$count  = count( $chunks );
			foreach ( $chunks as $i => $chunk ) {
				$title = $count > 1
					? sprintf(
						/* translators: 1: original title, 2: chunk index, 3: total chunks */
						__( '%1$s (part %2$d/%3$d)', 'mcp-ai-wpoos' ),
						$item['title'],
						$i + 1,
						$count
					)
					: $item['title'];

				$item_tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? $item['tags'] : array();
				$tags      = array_values( array_unique( array_filter( array_merge( $base_tags, $item_tags ) ) ) );

				$prepared[] = array(
					'title'      => $title,
					'content'    => $chunk,
					'tags'       => $tags,
					'metadata'   => isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array(),
					'source_ref' => isset( $item['source_ref'] ) ? $item['source_ref'] : '',
				);

				if ( count( $prepared ) >= self::MAX_RECORDS_PER_RUN ) {
					break 2;
				}
			}
		}

		// Persist via the existing store_agent_context tool to honour every
		// downstream contract (sanitization, verbatim, transforms, indexing).
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$store     = $registry->get_tool( 'store_agent_context' );
		$mined     = array();
		$failed    = 0;
		$plan_only = $dry_run || ! $store;

		foreach ( $prepared as $record ) {
			if ( $plan_only ) {
				$mined[] = array(
					'title'      => $record['title'],
					'tags'       => $record['tags'],
					'wing'       => $wing,
					'room'       => $room,
					'planned'    => true,
				);
				continue;
			}

			$store_args = array(
				'agent_id'     => $agent_id,
				'context_type' => $context_type,
				'context_data' => array(
					'title'      => $record['title'],
					'content'    => $record['content'],
					'tags'       => $record['tags'],
					'importance' => $importance,
					'metadata'   => array_merge(
						$record['metadata'],
						array(
							'mined_from' => $source,
							'source_ref' => $record['source_ref'],
						)
					),
				),
				'wing'         => $wing,
				'room'         => $room,
				'verbatim'     => $verbatim,
			);
			if ( $ttl > 0 ) {
				$store_args['ttl'] = $ttl;
			}

			$result = $store->execute( $store_args, $context );
			if ( ! empty( $result['success'] ) && ! empty( $result['context_id'] ) ) {
				$mined[] = array(
					'context_id' => $result['context_id'],
					'title'      => $record['title'],
					'wing'       => $wing,
					'room'       => $room,
					'tags'       => $record['tags'],
				);
			} else {
				++$failed;
			}
		}

		$message = $dry_run
			? sprintf(
				/* translators: 1: number of items, 2: source */
				__( '[DRY RUN] Would mine %1$d records from %2$s.', 'mcp-ai-wpoos' ),
				count( $mined ),
				$source
			)
			: sprintf(
				/* translators: 1: number of items, 2: source */
				__( 'Mined %1$d records from %2$s.', 'mcp-ai-wpoos' ),
				count( $mined ),
				$source
			);

		return array(
			'success' => true,
			'message' => $message,
			'count'   => count( $mined ),
			'failed'  => $failed,
			'dry_run' => $dry_run,
			'mined'   => $mined,
			'wing'    => $wing,
			'room'    => $room,
			'source'  => $source,
		);
	}

	/**
	 * Collect raw items from a WP_Query.
	 *
	 * @param array $query_args Query arguments.
	 * @return array<int,array{title:string,content:string,tags:array,metadata:array,source_ref:string}>
	 */
	private function collect_from_posts( $query_args ) {
		$defaults = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
		);
		$args     = array_merge( $defaults, array_intersect_key( $query_args, array_flip( array( 'post_type', 'post_status', 'posts_per_page', 's', 'category', 'tag' ) ) ) );

		// Sanitize.
		if ( isset( $args['posts_per_page'] ) ) {
			$args['posts_per_page'] = max( 1, min( self::MAX_RECORDS_PER_RUN, (int) $args['posts_per_page'] ) );
		}
		if ( isset( $args['s'] ) ) {
			$args['s'] = sanitize_text_field( $args['s'] );
		}
		if ( isset( $args['category'] ) ) {
			$args['category'] = absint( $args['category'] );
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$content = wp_strip_all_tags( (string) $post->post_content );
			if ( '' === trim( $content ) ) {
				continue;
			}
			$items[] = array(
				'title'      => get_the_title( $post ),
				'content'    => $content,
				'tags'       => $this->collect_post_terms( $post->ID ),
				'metadata'   => array(
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
					'permalink' => get_permalink( $post ),
				),
				'source_ref' => get_permalink( $post ),
			);
		}

		return $items;
	}

	/**
	 * Collect tags + categories for a post as a flat string array.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function collect_post_terms( $post_id ) {
		$terms = array();
		$tags  = get_the_terms( $post_id, 'post_tag' );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $term ) {
				$terms[] = sanitize_text_field( $term->slug );
			}
		}
		$cats = get_the_terms( $post_id, 'category' );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $term ) {
				$terms[] = sanitize_text_field( $term->slug );
			}
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * Collect raw items from a list of URLs.
	 *
	 * Delegates to {@see WP_MCP_AI_Tool_Store_Agent_Context} which already
	 * implements safe URL fetching, scheme validation, and HTML stripping.
	 *
	 * @param array $urls URL list.
	 * @return array Items array (may be empty).
	 */
	private function collect_from_urls( $urls ) {
		$items = array();
		if ( empty( $urls ) ) {
			return $items;
		}
		foreach ( $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				continue;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'headers'     => array(
						'User-Agent' => 'WP-MCP-AI-Memory-Miner/' . ( defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0' ) . ' (+' . esc_url( home_url( '/' ) ) . ')',
						'Accept'     => 'text/html,application/xhtml+xml,*/*;q=0.8',
					),
					'sslverify'   => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				continue;
			}
			$html = wp_remote_retrieve_body( $response );
			if ( '' === $html ) {
				continue;
			}

			$title = '';
			if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
				$title = sanitize_text_field( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
			}
			if ( '' === $title ) {
				$title = $url;
			}

			$html  = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
			$html  = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
			$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
			if ( '' === $plain ) {
				continue;
			}

			$items[] = array(
				'title'      => $title,
				'content'    => $plain,
				'tags'       => array( 'mined-url' ),
				'metadata'   => array(
					'url'         => $url,
					'http_status' => $code,
				),
				'source_ref' => $url,
			);
		}
		return $items;
	}

	/**
	 * Collect raw items from caller-supplied text records.
	 *
	 * @param array $items Caller-supplied items.
	 * @return array Normalised items.
	 */
	private function collect_from_text( $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
			$content = isset( $item['content'] ) ? wp_kses_post( $item['content'] ) : '';
			if ( '' === $title || '' === $content ) {
				continue;
			}
			$tags     = isset( $item['tags'] ) && is_array( $item['tags'] ) ? array_map( 'sanitize_text_field', $item['tags'] ) : array();
			$metadata = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
			$out[]    = array(
				'title'      => $title,
				'content'    => $content,
				'tags'       => $tags,
				'metadata'   => $metadata,
				'source_ref' => '',
			);
		}
		return $out;
	}

	/**
	 * Split a long string into chunks, breaking on whitespace boundaries
	 * where possible.
	 *
	 * @param string $text       Text to split.
	 * @param int    $chunk_size Maximum characters per chunk.
	 * @return string[]
	 */
	private function chunk_text( $text, $chunk_size ) {
		$length = mb_strlen( $text );
		if ( $length <= $chunk_size ) {
			return array( $text );
		}

		$chunks = array();
		$cursor = 0;
		while ( $cursor < $length ) {
			$slice = mb_substr( $text, $cursor, $chunk_size );

			// If we are not at the end and the next char isn't whitespace,
			// try to back up to the last whitespace inside the slice.
			if ( ( $cursor + $chunk_size ) < $length ) {
				$last_space = max(
					mb_strrpos( $slice, ' ' ),
					mb_strrpos( $slice, "\n" )
				);
				if ( false !== $last_space && $last_space > ( $chunk_size / 2 ) ) {
					$slice = mb_substr( $slice, 0, $last_space );
				}
			}

			$chunks[] = trim( $slice );
			$cursor  += mb_strlen( $slice );

			// Skip leading whitespace on the next chunk.
			while ( $cursor < $length && in_array( mb_substr( $text, $cursor, 1 ), array( ' ', "\n", "\t", "\r" ), true ) ) {
				++$cursor;
			}
		}
		return array_values( array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ai_model_management',
			'pattern_compatibility' => array( 'orchestrator', 'hierarchical' ),
			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,
			'external-api'      => true,  // May fetch URLs when source=urls.
			'read-only'         => false,
			'idempotent'        => false, // Creates new context each time.
			'cacheable'         => false,
			'requires-auth'     => true,
			'blocking'          => true,  // Bulk operations may take seconds.
			'uses-network'      => true,  // When source=urls.
			'modifies-wp'       => true,  // Stores transients.
			'expensive'         => true,  // May write many records.
			'requires-approval' => false,
		);
	}
}
