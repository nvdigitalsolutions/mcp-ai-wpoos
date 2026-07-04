<?php
/**
 * Conversation RAG Bridge — retrieval-augmented generation for BME strategy.
 *
 * Bridges the BME context strategy with the existing memory ecosystem:
 * - MemPalace / Graphify for hierarchical recall (Pro addon)
 * - Chat Memory REST for session-level wake-up
 * - Paper Store for structured long-term knowledge persistence
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Conversation_RAG_Bridge' ) ) {
	/**
	 * Retrieves relevant memories from the memory ecosystem and injects them
	 * into the BME message context.
	 *
	 * Leverages existing infrastructure rather than duplicating retrieval logic:
	 * - Paper Store for long-term structured knowledge
	 * - Chat Memory REST for session-level semantic recall
	 * - MemPalace wake_up_context for hierarchical retrieval (Pro)
	 */
	class WP_MCP_AI_Conversation_RAG_Bridge {

		/**
		 * Paper Store collection name for conversation summaries.
		 */
		const PAPER_COLLECTION = 'conversation-memory';

		/**
		 * Default maximum number of memories to retrieve.
		 */
		const DEFAULT_RETRIEVAL_LIMIT = 5;

		/**
		 * Default importance threshold for memory decay.
		 */
		const DEFAULT_IMPORTANCE_THRESHOLD = 0.3;

		/**
		 * Retrieve relevant memories for a given user message and context.
		 *
		 * Queries multiple sources and returns a merged, deduplicated list.
		 *
		 * @param string $user_message The latest user message to match against.
		 * @param array  $context      Request context (assistant_id, provider, model).
		 * @param int    $limit        Max memories to return.
		 * @return array Array of memory arrays with 'content' and 'source' keys.
		 */
		public function retrieve_relevant_memories( $user_message, array $context, $limit = 0 ) {
			if ( $limit <= 0 ) {
				$limit = self::DEFAULT_RETRIEVAL_LIMIT;
			}

			$memories = array();

			// 1. Paper Store: structured long-term knowledge.
			$paper_memories = $this->retrieve_from_paper_store( $user_message, $limit );
			$memories       = array_merge( $memories, $paper_memories );

			// 2. Chat Memory: semantic recall via existing wake-up infrastructure.
			// The chat-memory service already handles vector search, importance scoring,
			// and wing/room/agent scoping through the MemPalace hierarchy.
			$chat_memories = $this->retrieve_from_chat_memory( $user_message, $context, $limit );
			$memories      = array_merge( $memories, $chat_memories );

			// Deduplicate by content hash.
			$memories = $this->deduplicate_memories( $memories );

			// Sort by importance (high → low) then recency.
			$memories = $this->sort_by_importance( $memories );

			// Apply decay to older memories.
			$memories = $this->apply_memory_decay( $memories );

			// Limit results.
			return array_slice( $memories, 0, $limit );
		}

		/**
		 * Retrieve memories from the Paper Store.
		 *
		 * Searches the conversation-memory collection for records matching
		 * the user's message context.
		 *
		 * @param string $user_message User message to match.
		 * @param int    $limit        Max results.
		 * @return array Memory records.
		 */
		protected function retrieve_from_paper_store( $user_message, $limit ) {
			if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
				return array();
			}

			try {
				$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
				$repo    = $manager->get_repository( self::PAPER_COLLECTION );

				if ( ! $repo ) {
					return array();
				}

				// Extract potential keywords from the user message for tag matching.
				$keywords = $this->extract_keywords( $user_message );

				$records = array();
				$all     = $repo->all();

				foreach ( $all as $record ) {
					if ( count( $records ) >= $limit ) {
						break;
					}

					// Score relevance: match on tags and title/content.
					$score = $this->score_paper_record( $record, $keywords, $user_message );
					if ( $score > 0 ) {
						$records[] = array(
							'content'    => isset( $record['title'] ) ? $record['title'] . ': ' . $this->extract_record_summary( $record ) : $this->extract_record_summary( $record ),
							'source'     => 'paper_store',
							'importance' => isset( $record['importance'] ) ? (float) $record['importance'] : 0.5,
							'score'      => $score,
							'stored_at'  => isset( $record['updated_at'] ) ? $record['updated_at'] : ( isset( $record['created_at'] ) ? $record['created_at'] : '' ),
						);
					}
				}

				// Sort by score descending.
				usort(
					$records,
					function ( $a, $b ) {
						return $b['score'] <=> $a['score'];
					}
				);

				return array_slice( $records, 0, $limit );
			} catch ( \Exception $e ) {
				WP_MCP_AI_Logger::log_event(
					'bme_rag_paper_retrieval_error',
					'Paper Store retrieval failed.',
					array( 'error' => $e->getMessage() )
				);
				return array();
			}
		}

		/**
		 * Retrieve memories from the chat-memory system.
		 *
		 * Uses the existing wake_up_context mechanism which handles
		 * vector search, importance scoring, and hierarchical recall.
		 *
		 * @param string $user_message User message.
		 * @param array  $context      Request context.
		 * @param int    $limit        Max results.
		 * @return array Memory records.
		 */
		protected function retrieve_from_chat_memory( $user_message, array $context, $limit ) {
			// The chat-memory wake-up system is primarily designed for session boot.
			// For turn-by-turn retrieval, we use the recall endpoint which accepts
			// a query parameter for semantic matching.
			//
			// This gracefully degrades — if the chat-memory surface is disabled
			// or unavailable, we return an empty array.

			if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
				return array();
			}

			$registry = wp_mcp_ai_get_tool_registry();
			if ( ! $registry ) {
				return array();
			}

			// Use the recall_memory tool if available (MemPalace Phase A8).
			$recall_tool = $registry->get_tool( 'recall_memory' );
			if ( ! $recall_tool ) {
				// Fallback: try retrieve_agent_memory.
				$recall_tool = $registry->get_tool( 'retrieve_agent_memory' );
			}

			if ( ! $recall_tool ) {
				return array();
			}

			try {
				$arguments = array(
					'query' => $user_message,
					'limit' => $limit,
				);

				// Scope to the assistant if available.
				if ( ! empty( $context['assistant_id'] ) ) {
					$arguments['agent_id'] = $context['assistant_id'];
				}

				$result = $recall_tool->execute( $arguments, $context );

				if ( is_wp_error( $result ) ) {
					return array();
				}

				$memories = array();

				// Normalize the result into our memory format.
				if ( isset( $result['memories'] ) && is_array( $result['memories'] ) ) {
					foreach ( $result['memories'] as $memory ) {
						$memories[] = array(
							'content'    => isset( $memory['content'] ) ? $memory['content'] : ( isset( $memory['text'] ) ? $memory['text'] : '' ),
							'source'     => 'chat_memory',
							'importance' => isset( $memory['importance'] ) ? (float) $memory['importance'] : 0.5,
							'score'      => isset( $memory['score'] ) ? (float) $memory['score'] : 0.5,
							'stored_at'  => isset( $memory['stored_at'] ) ? $memory['stored_at'] : '',
						);
					}
				}

				return $memories;
			} catch ( \Exception $e ) {
				WP_MCP_AI_Logger::log_event(
					'bme_rag_chat_memory_retrieval_error',
					'Chat memory retrieval failed.',
					array( 'error' => $e->getMessage() )
				);
				return array();
			}
		}

		/**
		 * Persist a conversation summary to the Paper Store for long-term knowledge.
		 *
		 * @param string $summary    Summary text.
		 * @param string $title      Optional title for the record.
		 * @param array  $tags       Optional tags for retrieval.
		 * @param float  $importance Importance 0.0-1.0.
		 * @return bool True on success.
		 */
		public function persist_summary_to_paper_store( $summary, $title = '', $tags = array(), $importance = 0.5 ) {
			if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
				return false;
			}

			if ( '' === $summary ) {
				return false;
			}

			try {
				$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
				$repo    = $manager->get_repository( self::PAPER_COLLECTION );

				if ( ! $repo ) {
					return false;
				}

				$record_id = 'summary-' . wp_generate_uuid4();

				$record = array(
					'id'         => sanitize_key( $record_id ),
					'type'       => 'conversation_summary',
					'title'      => $title ? sanitize_text_field( $title ) : __( 'Conversation Summary', 'mcp-ai-wpoos' ),
					'body'       => array(
						'summary'   => wp_kses_post( $summary ),
						'timestamp' => current_time( 'c', true ),
					),
					'tags'       => array_map( 'sanitize_key', (array) $tags ),
					'importance' => max( 0, min( 1, (float) $importance ) ),
					'status'     => 'published',
				);

				$result = $repo->save( $record );

				WP_MCP_AI_Logger::log_event(
					'bme_rag_summary_persisted',
					'Conversation summary persisted to Paper Store.',
					array(
						'record_id'  => $record_id,
						'title'      => $record['title'],
						'importance' => $importance,
					)
				);

				return ! is_wp_error( $result );
			} catch ( \Exception $e ) {
				WP_MCP_AI_Logger::log_event(
					'bme_rag_paper_persist_error',
					'Failed to persist summary to Paper Store.',
					array( 'error' => $e->getMessage() )
				);
				return false;
			}
		}

		/**
		 * Build a RAG context message from retrieved memories.
		 *
		 * Formats retrieved memories into a single context message that can be
		 * injected into the chat message list.
		 *
		 * @param array $memories Array of memory arrays from retrieve_relevant_memories().
		 * @return array Message array with 'role' and 'content' keys, or empty array.
		 */
		public function build_rag_context_message( array $memories ) {
			if ( empty( $memories ) ) {
				return array();
			}

			$lines = array( __( 'Relevant context from memory:', 'mcp-ai-wpoos' ) );

			foreach ( $memories as $i => $memory ) {
				if ( empty( $memory['content'] ) ) {
					continue;
				}

				$num     = $i + 1;
				$lines[] = "{$num}. " . wp_strip_all_tags( $memory['content'] );
			}

			if ( count( $lines ) <= 1 ) {
				return array();
			}

			return array(
				'role'    => 'user',
				'content' => '[Retrieved memories: ' . implode( ' | ', array_slice( $lines, 1 ) ) . ']',
			);
		}

		/**
		 * Extract keywords from a user message for tag-based matching.
		 *
		 * Simple heuristic: split on spaces, filter common words, return top N.
		 *
		 * @param string $message User message.
		 * @param int    $limit   Max keywords.
		 * @return array Keywords.
		 */
		protected function extract_keywords( $message, $limit = 5 ) {
			$message = strtolower( wp_strip_all_tags( $message ) );

			// Common stop words to filter out.
			$stop_words = array(
				'the',
				'is',
				'at',
				'which',
				'on',
				'a',
				'an',
				'and',
				'or',
				'but',
				'in',
				'with',
				'to',
				'for',
				'of',
				'it',
				'as',
				'be',
				'has',
				'was',
				'are',
				'by',
				'this',
				'that',
				'from',
				'they',
				'we',
				'you',
				'i',
				'me',
				'my',
				'your',
				'what',
				'how',
				'when',
				'where',
				'who',
				'why',
				'can',
				'will',
				'just',
				'not',
				'so',
				'if',
				'no',
				'yes',
				'do',
				'does',
			);

			$words = preg_split( '/\s+/', $message );
			$words = array_filter(
				$words,
				function ( $word ) use ( $stop_words ) {
					$word = trim( $word, '.,!?;:\'"()[]{}' );
					return strlen( $word ) > 2 && ! in_array( $word, $stop_words, true );
				}
			);

			return array_slice( array_unique( $words ), 0, $limit );
		}

		/**
		 * Score a Paper Store record against keywords and user message.
		 *
		 * @param array  $record      Paper Store record.
		 * @param array  $keywords    Extracted keywords.
		 * @param string $user_message Full user message for substring match.
		 * @return float Score 0-1.
		 */
		protected function score_paper_record( array $record, array $keywords, $user_message ) {
			$score = 0;

			// Tag matching.
			if ( ! empty( $record['tags'] ) && is_array( $record['tags'] ) ) {
				foreach ( $record['tags'] as $tag ) {
					if ( in_array( strtolower( $tag ), $keywords, true ) ) {
						$score += 0.3;
					}
				}
			}

			// Title matching.
			if ( ! empty( $record['title'] ) ) {
				$title_lower = strtolower( $record['title'] );
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $title_lower, $keyword ) ) {
						$score += 0.2;
					}
				}
			}

			// Content substring match.
			if ( ! empty( $record['body'] ) && is_array( $record['body'] ) ) {
				$body_text = wp_json_encode( $record['body'] );
				if ( $body_text ) {
					$body_lower = strtolower( $body_text );
					$msg_lower  = strtolower( $user_message );
					// Simple word overlap.
					$msg_words = preg_split( '/\s+/', $msg_lower );
					$hits      = 0;
					foreach ( $msg_words as $word ) {
						if ( strlen( $word ) > 3 && false !== strpos( $body_lower, $word ) ) {
							++$hits;
						}
					}
					if ( count( $msg_words ) > 0 ) {
						$score += min( 0.5, ( $hits / count( $msg_words ) ) * 0.5 );
					}
				}
			}

			return min( 1.0, $score );
		}

		/**
		 * Extract a short summary from a Paper Store record.
		 *
		 * @param array $record Paper Store record.
		 * @return string Short summary text.
		 */
		protected function extract_record_summary( array $record ) {
			if ( ! empty( $record['body']['summary'] ) ) {
				$summary = $record['body']['summary'];
				if ( strlen( $summary ) > 200 ) {
					$summary = substr( $summary, 0, 200 ) . '…';
				}
				return $summary;
			}

			if ( ! empty( $record['description'] ) ) {
				return $record['description'];
			}

			return '';
		}

		/**
		 * Deduplicate memories by content hash.
		 *
		 * @param array $memories Array of memory arrays.
		 * @return array Deduplicated memories.
		 */
		protected function deduplicate_memories( array $memories ) {
			$seen   = array();
			$unique = array();

			foreach ( $memories as $memory ) {
				if ( empty( $memory['content'] ) ) {
					continue;
				}

				$hash = md5( $memory['content'] );
				if ( isset( $seen[ $hash ] ) ) {
					continue;
				}

				$seen[ $hash ] = true;
				$unique[]      = $memory;
			}

			return $unique;
		}

		/**
		 * Sort memories by importance (high → low).
		 *
		 * @param array $memories Array of memory arrays.
		 * @return array Sorted memories.
		 */
		protected function sort_by_importance( array $memories ) {
			usort(
				$memories,
				function ( $a, $b ) {
					$a_importance = isset( $a['importance'] ) ? (float) $a['importance'] : 0.5;
					$b_importance = isset( $b['importance'] ) ? (float) $b['importance'] : 0.5;

					// Primary sort by importance descending.
					$cmp = $b_importance <=> $a_importance;
					if ( 0 !== $cmp ) {
						return $cmp;
					}

					// Secondary sort by recency (newer first).
					$a_time = isset( $a['stored_at'] ) ? strtotime( $a['stored_at'] ) : 0;
					$b_time = isset( $b['stored_at'] ) ? strtotime( $b['stored_at'] ) : 0;
					return $b_time <=> $a_time;
				}
			);

			return $memories;
		}

		/**
		 * Apply memory decay to older memories.
		 *
		 * Memories older than 7 days get their importance reduced by 25%.
		 * Memories older than 30 days get reduced by 50%.
		 * Memories below the importance threshold are filtered out.
		 *
		 * @param array $memories  Array of memory arrays.
		 * @param float $threshold Minimum importance to keep.
		 * @return array Decayed memories.
		 */
		public function apply_memory_decay( array $memories, $threshold = 0 ) {
			if ( $threshold <= 0 ) {
				$threshold = self::DEFAULT_IMPORTANCE_THRESHOLD;
			}

			$now       = time();
			$week_sec  = 7 * 24 * 60 * 60;
			$month_sec = 30 * 24 * 60 * 60;

			$decayed = array();

			foreach ( $memories as $memory ) {
				$importance = isset( $memory['importance'] ) ? (float) $memory['importance'] : 0.5;
				$stored_at  = isset( $memory['stored_at'] ) ? $memory['stored_at'] : '';

				if ( '' !== $stored_at ) {
					$age = $now - strtotime( $stored_at );

					if ( $age > $month_sec ) {
						$importance *= 0.5; // 50% decay after 30 days.
					} elseif ( $age > $week_sec ) {
						$importance *= 0.75; // 25% decay after 7 days.
					}
				}

				// Filter out memories below the importance threshold.
				if ( $importance < $threshold ) {
					continue;
				}

				$memory['importance'] = $importance;
				$memory['decayed']    = true;
				$decayed[]            = $memory;
			}

			return $decayed;
		}
	}
}
