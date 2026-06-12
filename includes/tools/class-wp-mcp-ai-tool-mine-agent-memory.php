<?php
/**
 * Tool for mining/bulk-ingesting agent memory from various sources.
 *
 * Inspired by the MemPalace project (https://github.com/MemPalace/mempalace).
 * Phase 2 enhancement: takes a source (post type query, URL list, or raw text
 * array), chunks long content, and bulk-creates verbatim memory records scoped
 * to a chosen wing/room.
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
				'agent_id'         => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier.', 'mcp-ai-wpoos' ),
				),
				'source'           => array(
					'type'        => 'string',
					'description' => __( 'Where to mine from. "posts" runs a WP_Query against the chosen post type; "urls" fetches a list of URLs via the existing URL ingestion path; "text" stores each provided text item verbatim; "transcripts" reads stored chat transcript sessions from the JetEngine CCT and stores each session as one or more verbatim chunks with full provenance metadata.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'posts', 'urls', 'text', 'transcripts' ),
				),
				'post_query'       => array(
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
				'urls'             => array(
					'type'        => 'array',
					'description' => __( 'When source=urls, the list of URLs to fetch and store.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'items'            => array(
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
				'transcript_query' => array(
					'type'        => 'object',
					'description' => __( 'When source=transcripts, the filter applied to stored chat transcript sessions. All fields optional. Defaults: posts_per_page=10 sessions per run.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'assistant_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Restrict to one assistant (post ID).', 'mcp-ai-wpoos' ),
							'minimum'     => 0,
						),
						'user_id'          => array(
							'type'        => 'integer',
							'description' => __( 'Restrict to one user. Defaults to the current user.', 'mcp-ai-wpoos' ),
							'minimum'     => 0,
						),
						'since'            => array(
							'type'        => 'string',
							'description' => __( 'Only sessions created on or after this date (any strtotime-parsable string).', 'mcp-ai-wpoos' ),
						),
						'until'            => array(
							'type'        => 'string',
							'description' => __( 'Only sessions created on or before this date (any strtotime-parsable string).', 'mcp-ai-wpoos' ),
						),
						'session_keys'     => array(
							'type'        => 'array',
							'description' => __( 'Restrict to specific session_key values.', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'min_messages'     => array(
							'type'        => 'integer',
							'description' => __( 'Skip sessions with fewer than this many turns. Defaults to 1.', 'mcp-ai-wpoos' ),
							'minimum'     => 1,
						),
						'only_unextracted' => array(
							'type'        => 'boolean',
							'description' => __( 'When true, skip sessions that already have memory records carrying the same transcript_session_key in their metadata. Defaults to true.', 'mcp-ai-wpoos' ),
							'default'     => true,
						),
						'posts_per_page'   => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of sessions to mine in one run. Capped at 50.', 'mcp-ai-wpoos' ),
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'wing'             => array(
					'type'        => 'string',
					'description' => __( 'Optional wing (project/person scope) applied to every mined record.', 'mcp-ai-wpoos' ),
				),
				'room'             => array(
					'type'        => 'string',
					'description' => __( 'Optional room (topic cluster within a wing) applied to every mined record.', 'mcp-ai-wpoos' ),
				),
				'context_type'     => array(
					'type'        => 'string',
					'description' => __( 'Context type to use for every mined record. Defaults to "note".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'learning', 'fact', 'preference', 'pattern', 'workflow', 'decision', 'result', 'insight', 'note', 'generic' ),
					'default'     => 'note',
				),
				'tags'             => array(
					'type'        => 'array',
					'description' => __( 'Tags applied to every mined record (in addition to any per-item tags).', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'importance'       => array(
					'type'        => 'string',
					'description' => __( 'Importance level for every mined record.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
					'default'     => 'medium',
				),
				'chunk_size'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum characters per record. Items longer than this are split into multiple sequential records sharing the same title (with a chunk index suffix).', 'mcp-ai-wpoos' ),
					'minimum'     => 500,
					'maximum'     => 16000,
					'default'     => self::DEFAULT_CHUNK_SIZE,
				),
				'verbatim'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to store records verbatim (skipping the pre-store transform filter). Defaults to true — mining is intended for raw ingestion.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'ttl'              => array(
					'type'        => 'integer',
					'description' => __( 'Time-to-live in seconds for every mined record.', 'mcp-ai-wpoos' ),
					'minimum'     => 3600,
					'maximum'     => 31536000,
				),
				'dry_run'          => array(
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent ID is required.', 'mcp-ai-wpoos' )
			);
		}
		if ( empty( $arguments['source'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Source is required.', 'mcp-ai-wpoos' )
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
			case 'transcripts':
				$items = $this->collect_from_transcripts(
					isset( $arguments['transcript_query'] ) && is_array( $arguments['transcript_query'] ) ? $arguments['transcript_query'] : array(),
					$agent_id
				);
				break;
			default:
				return new WP_Error(
					'wp_mcp_ai_error',
					/* translators: %s: source value */
					sprintf( __( 'Unsupported source "%s".', 'mcp-ai-wpoos' ), $source )
				);
		}

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		if ( empty( $items ) ) {
			return array(
				'success' => true,
				'message' => __( 'No items found to mine.', 'mcp-ai-wpoos' ),
				'mined'   => array(),
				'count'   => 0,
				'skipped' => 0,
				'failed'  => 0,
				'dry_run' => $dry_run,
			);
		}

		// Pre-load the set of transcript content hashes that already exist for
		// this agent so we can skip duplicates. Cheap when the agent has no
		// transcript-derived memories yet.
		$existing_hashes = array();
		if ( ! $dry_run && 'transcripts' === $source ) {
			$existing_hashes = $this->collect_existing_transcript_hashes( $agent_id );
		}

		// Chunk long items.
		$prepared    = array();
		$dedupe_skip = 0;
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

				$item_metadata = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();

				// Idempotency: skip items whose transcript_content_hash is
				// already on a stored memory record for this agent. Mem0
				// calls this "fact deduplication"; LangMem calls it
				// "memory upsert".
				if ( ! empty( $item_metadata['transcript_content_hash'] )
					&& isset( $existing_hashes[ $item_metadata['transcript_content_hash'] ] )
				) {
					++$dedupe_skip;
					continue;
				}

				$prepared[] = array(
					'title'      => $title,
					'content'    => $chunk,
					'tags'       => $tags,
					'metadata'   => $item_metadata,
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
					'title'   => $record['title'],
					'tags'    => $record['tags'],
					'wing'    => $wing,
					'room'    => $room,
					'planned' => true,
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
			'skipped' => $dedupe_skip,
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
	 * Collect raw items from stored chat transcript sessions.
	 *
	 * Each session is rebuilt into a plain-text conversation, split into
	 * `chunk_size`-sized pieces at message boundaries, and emitted as one
	 * item per piece. Provenance metadata (`transcript_session_key`,
	 * `transcript_assistant_id`, `transcript_message_range`,
	 * `transcript_content_hash`) is attached to every item so downstream
	 * dedupe and back-tracing work without an additional database lookup.
	 *
	 * Industry-standard "two-stage extraction" — Stage A (raw chunked,
	 * verbatim, no LLM) is what this method produces. Stage B
	 * (LLM-distilled facts/decisions/preferences) is layered on top by the
	 * admin UI in a later PR.
	 *
	 * Two filter hooks are provided so this can be exercised in tests
	 * without a live JetEngine CCT, and so external code can substitute
	 * an alternate transcript source (e.g. a federated peer site).
	 *
	 * @param array      $query_args Query arguments. See `transcript_query`
	 *                                schema in {@see get_parameters_schema()}.
	 * @param int|string $agent_id   Agent identifier the items will be
	 *                                stored against (used for the
	 *                                `only_unextracted` filter).
	 * @return array<int,array{title:string,content:string,tags:array,metadata:array,source_ref:string}>
	 */
	private function collect_from_transcripts( $query_args, $agent_id ) {
		$defaults = array(
			'assistant_id'     => 0,
			'user_id'          => 0,
			'since'            => '',
			'until'            => '',
			'session_keys'     => array(),
			'min_messages'     => 1,
			'only_unextracted' => true,
			'posts_per_page'   => 10,
		);
		$args     = array_merge( $defaults, array_intersect_key( $query_args, $defaults ) );

		$args['assistant_id']     = absint( $args['assistant_id'] );
		$args['user_id']          = absint( $args['user_id'] );
		$args['since']            = is_string( $args['since'] ) ? sanitize_text_field( $args['since'] ) : '';
		$args['until']            = is_string( $args['until'] ) ? sanitize_text_field( $args['until'] ) : '';
		$args['session_keys']     = is_array( $args['session_keys'] ) ? array_map( 'sanitize_text_field', $args['session_keys'] ) : array();
		$args['min_messages']     = max( 1, (int) $args['min_messages'] );
		$args['only_unextracted'] = (bool) $args['only_unextracted'];
		$args['posts_per_page']   = max( 1, min( 50, (int) $args['posts_per_page'] ) );

		// user_id = 0 means "no user filter" (all users). This is intentional
		// for background mining jobs where get_current_user_id() returns 0
		// (cron context has no authenticated user). Callers that want to scope
		// to a specific user must pass user_id explicitly.

		// Resolve the session list. The filter lets tests inject mock
		// sessions without a live JetEngine CCT, and lets external code
		// substitute alternate transcript stores (e.g. federation).
		$sessions = $this->fetch_transcript_sessions( $args );

		/**
		 * Filters the resolved transcript-session list before it is mined.
		 *
		 * @param array $sessions Array of session summary rows. Each row must
		 *                        contain at minimum `session_key`. Optional
		 *                        keys: `assistant_id`, `assistant_model`,
		 *                        `turn_count`, `started_at`, `last_created`.
		 * @param array $args     Resolved query args.
		 */
		$sessions = apply_filters( 'wp_mcp_ai_mine_transcripts_sessions', $sessions, $args );

		if ( empty( $sessions ) || ! is_array( $sessions ) ) {
			return array();
		}

		// Apply post-filter constraints (since/until/min_messages/session_keys/only_unextracted).
		$existing_session_keys = array();
		if ( $args['only_unextracted'] ) {
			$existing_session_keys = $this->collect_existing_transcript_session_keys( $agent_id );
		}

		$since_ts = '' !== $args['since'] ? strtotime( $args['since'] ) : false;
		$until_ts = '' !== $args['until'] ? strtotime( $args['until'] ) : false;

		$items = array();
		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) || empty( $session['session_key'] ) ) {
				continue;
			}
			$session_key = sanitize_text_field( $session['session_key'] );

			// Filter: explicit session_keys list.
			if ( ! empty( $args['session_keys'] ) && ! in_array( $session_key, $args['session_keys'], true ) ) {
				continue;
			}

			// Filter: only_unextracted.
			if ( $args['only_unextracted'] && isset( $existing_session_keys[ $session_key ] ) ) {
				continue;
			}

			// Filter: min_messages.
			$turn_count = isset( $session['turn_count'] ) ? (int) $session['turn_count'] : 0;
			if ( $turn_count > 0 && $turn_count < $args['min_messages'] ) {
				continue;
			}

			// Filter: since / until against last activity.
			if ( false !== $since_ts || false !== $until_ts ) {
				$activity = isset( $session['last_created'] ) ? strtotime( (string) $session['last_created'] ) : false;
				if ( false !== $activity ) {
					if ( false !== $since_ts && $activity < $since_ts ) {
						continue;
					}
					if ( false !== $until_ts && $activity > $until_ts ) {
						continue;
					}
				}
			}

			$messages = $this->fetch_transcript_session_messages( $session_key, $args );
			/**
			 * Filters the message list extracted for a single transcript
			 * session before it is chunked into memory items.
			 *
			 * @param array  $messages    Ordered list of message rows. Each row:
			 *                            `{role: string, content: string,
			 *                            message_index: int}`.
			 * @param string $session_key Session key.
			 * @param array  $args        Resolved query args.
			 */
			$messages = apply_filters( 'wp_mcp_ai_mine_transcripts_session_messages', $messages, $session_key, $args );

			if ( empty( $messages ) || ! is_array( $messages ) ) {
				continue;
			}
			if ( count( $messages ) < $args['min_messages'] ) {
				continue;
			}

			$items = array_merge(
				$items,
				$this->build_transcript_items_from_messages( $session, $session_key, $messages )
			);
		}

		return $items;
	}

	/**
	 * Build raw items from a session's ordered message list.
	 *
	 * Each item carries provenance metadata: session_key, assistant_id,
	 * message_range, and a sha256 content hash used by the dedupe path.
	 *
	 * @param array  $session     Session summary row.
	 * @param string $session_key Session key.
	 * @param array  $messages    Ordered messages for the session.
	 * @return array<int,array{title:string,content:string,tags:array,metadata:array,source_ref:string}>
	 */
	private function build_transcript_items_from_messages( $session, $session_key, $messages ) {
		$assistant_id = isset( $session['assistant_id'] ) ? sanitize_text_field( (string) $session['assistant_id'] ) : '';
		$started_at   = isset( $session['started_at'] ) ? sanitize_text_field( (string) $session['started_at'] ) : '';
		$last_created = isset( $session['last_created'] ) ? sanitize_text_field( (string) $session['last_created'] ) : '';

		// Render conversation text with one line per message so it splits at
		// message boundaries when fed to chunk_text().
		$lines = array();
		$index = array();
		foreach ( $messages as $i => $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}
			$role    = isset( $msg['role'] ) ? sanitize_key( (string) $msg['role'] ) : 'user';
			$content = isset( $msg['content'] ) ? trim( wp_strip_all_tags( (string) $msg['content'] ) ) : '';
			if ( '' === $content ) {
				continue;
			}
			$mi      = isset( $msg['message_index'] ) ? (int) $msg['message_index'] : $i;
			$lines[] = array(
				'message_index' => $mi,
				'rendered'      => sprintf( '%s: %s', strtoupper( $role ), $content ),
			);
			$index[] = $mi;
		}

		if ( empty( $lines ) ) {
			return array();
		}

		$rendered = '';
		foreach ( $lines as $line ) {
			$rendered .= $line['rendered'] . "\n\n";
		}
		$rendered = rtrim( $rendered );

		$message_range = array(
			'start' => (int) min( $index ),
			'end'   => (int) max( $index ),
		);

		$base_title = sprintf(
			/* translators: %s: short session key */
			__( 'Chat session %s', 'mcp-ai-wpoos' ),
			substr( $session_key, 0, 12 )
		);

		// Cap input to the hash function to bound memory pressure on
		// pathological long sessions. 1 MiB is far beyond any realistic
		// rendered transcript and still produces a stable hash because it
		// always covers the full prefix.
		$hash_input = $session_key . '|' . $message_range['start'] . '-' . $message_range['end'] . '|' . $rendered;
		if ( strlen( $hash_input ) > 1048576 ) {
			$hash_input = substr( $hash_input, 0, 1048576 );
		}
		$content_hash = hash( 'sha256', $hash_input );

		return array(
			array(
				'title'      => $base_title,
				'content'    => $rendered,
				'tags'       => array( 'transcript', 'raw', 'session:' . $session_key ),
				'metadata'   => array(
					'mined_from'               => 'transcript',
					'transcript_session_key'   => $session_key,
					'transcript_assistant_id'  => $assistant_id,
					'transcript_started_at'    => $started_at,
					'transcript_last_created'  => $last_created,
					'transcript_message_range' => $message_range,
					'transcript_content_hash'  => $content_hash,
				),
				'source_ref' => $session_key,
			),
		);
	}

	/**
	 * Fetch transcript sessions using the repository.
	 *
	 * Wrapped in a method so tests can short-circuit it via the
	 * `wp_mcp_ai_mine_transcripts_sessions` filter without needing a live
	 * JetEngine CCT in the test environment.
	 *
	 * @param array $args Resolved query args.
	 * @return array
	 */
	private function fetch_transcript_sessions( $args ) {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Repository' ) ) {
			return array();
		}
		$repo = new WP_MCP_AI_Transcript_Repository();
		if ( ! $repo->table_exists() ) {
			return array();
		}
		$result = $repo->get_sessions( (int) $args['user_id'], (int) $args['posts_per_page'], 1, (int) $args['assistant_id'] );
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['items'] ) ) {
			return array();
		}
		return $result['items'];
	}

	/**
	 * Fetch the ordered message list for a single transcript session.
	 *
	 * Reconstructs each turn's user message + assistant response from the
	 * stored `request_payload` / `response_payload` JSON columns. Returns
	 * an array of `{role, content, message_index}` entries ready for
	 * chunking.
	 *
	 * @param string $session_key Session key.
	 * @param array  $args        Resolved query args.
	 * @return array
	 */
	private function fetch_transcript_session_messages( $session_key, $args ) {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Repository' ) ) {
			return array();
		}
		$repo = new WP_MCP_AI_Transcript_Repository();
		if ( ! $repo->table_exists() ) {
			return array();
		}
		$rows = $repo->get_session( (int) $args['user_id'], $session_key, (int) $args['assistant_id'] );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$messages = array();
		$idx      = 0;
		foreach ( $rows as $row ) {
			$req_raw  = isset( $row['request_payload'] ) ? (string) $row['request_payload'] : '';
			$resp_raw = isset( $row['response_payload'] ) ? (string) $row['response_payload'] : '';

			if ( '' !== $req_raw ) {
				$req = json_decode( $req_raw, true );
				if ( is_array( $req ) && isset( $req['messages'] ) && is_array( $req['messages'] ) ) {
					// Only the most recent user turn is meaningful per row;
					// earlier messages are repeated context. Take the last
					// `user`-role message to avoid duplication.
					$last_user = '';
					foreach ( $req['messages'] as $m ) {
						if ( is_array( $m ) && isset( $m['role'] ) && 'user' === $m['role'] && isset( $m['content'] ) && is_string( $m['content'] ) ) {
							$last_user = $m['content'];
						}
					}
					if ( '' !== $last_user ) {
						$messages[] = array(
							'role'          => 'user',
							'content'       => $last_user,
							'message_index' => $idx++,
						);
					}
				}
			}

			if ( '' !== $resp_raw ) {
				$resp           = json_decode( $resp_raw, true );
				$assistant_text = '';
				if ( is_array( $resp ) ) {
					if ( isset( $resp['content'] ) && is_string( $resp['content'] ) ) {
						$assistant_text = $resp['content'];
					} elseif ( isset( $resp['choices'][0]['message']['content'] ) && is_string( $resp['choices'][0]['message']['content'] ) ) {
						$assistant_text = $resp['choices'][0]['message']['content'];
					}
				}
				if ( '' !== $assistant_text ) {
					$messages[] = array(
						'role'          => 'assistant',
						'content'       => $assistant_text,
						'message_index' => $idx++,
					);
				}
			}
		}

		return $messages;
	}

	/**
	 * Collect transcript_content_hash values already present on this
	 * agent's stored memory records, for idempotent re-runs.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return array<string,bool>  Map of hash => true.
	 */
	private function collect_existing_transcript_hashes( $agent_id ) {
		return $this->collect_existing_transcript_field( $agent_id, 'transcript_content_hash' );
	}

	/**
	 * Collect transcript_session_key values already present on this
	 * agent's stored memory records.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return array<string,bool>  Map of session_key => true.
	 */
	private function collect_existing_transcript_session_keys( $agent_id ) {
		return $this->collect_existing_transcript_field( $agent_id, 'transcript_session_key' );
	}

	/**
	 * Shared helper: scan agent memory for a metadata field value set.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @param string     $field    Metadata field name to collect.
	 * @return array<string,bool>
	 */
	private function collect_existing_transcript_field( $agent_id, $field ) {
		$set      = array();
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$retrieve = $registry->get_tool( 'retrieve_agent_memory' );
		if ( ! $retrieve ) {
			return $set;
		}

		/**
		 * Filters how many of the most-recent agent memory records are
		 * scanned when building the transcript dedupe lookup. Sites that
		 * mine very high-volume transcript histories may want to raise this
		 * to keep the "skip duplicates" guarantee strong; sites with a tight
		 * memory budget may want to lower it.
		 *
		 * @param int        $limit    Default scan limit (1000).
		 * @param int|string $agent_id Agent identifier the lookup is for.
		 * @param string     $field    Metadata field being collected
		 *                             (`transcript_content_hash` or
		 *                             `transcript_session_key`).
		 */
		$limit = (int) apply_filters( 'wp_mcp_ai_mine_transcripts_dedupe_scan_limit', 1000, $agent_id, $field );
		$limit = max( 1, $limit );

		$lookup = $retrieve->execute(
			array(
				'agent_id' => $agent_id,
				'limit'    => $limit,
			),
			array()
		);
		if ( empty( $lookup['contexts'] ) || ! is_array( $lookup['contexts'] ) ) {
			return $set;
		}
		foreach ( $lookup['contexts'] as $ctx ) {
			if ( ! is_array( $ctx ) ) {
				continue;
			}
			$meta = isset( $ctx['metadata'] ) && is_array( $ctx['metadata'] ) ? $ctx['metadata'] : array();
			if ( ! empty( $meta[ $field ] ) && is_string( $meta[ $field ] ) ) {
				$set[ $meta[ $field ] ] = true;
			}
		}
		return $set;
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
			'external-api',         // May fetch URLs when source=urls.
			'network-dependent',    // When source=urls.
			'write',                // Creates new context each time.
			'state-changing',       // Stores transients.
			'long-running',         // Bulk operations may take seconds.
			'requires-capability',  // Needs user authentication.
			'performance-impact',   // May write many records.
		);
	}
}
