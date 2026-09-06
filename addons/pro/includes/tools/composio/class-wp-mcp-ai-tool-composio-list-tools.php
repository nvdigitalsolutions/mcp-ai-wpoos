<?php
/**
 * Tool: composio_list_tools — browse and search the Composio tool catalog.
 *
 * Pro tool (PHP 8.1+). Requires edit_posts capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio — List tools.
 */
class WP_MCP_AI_Tool_Composio_List_Tools implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Maximum toolkits fanned out when browsing connected apps.
	 *
	 * `GET /tools` accepts a single `toolkit_slug`, so a connected-apps view
	 * costs one request per toolkit. Responses are cached for 24h, but the fan
	 * out is still capped to keep the first call bounded.
	 */
	const MAX_TOOLKIT_FANOUT = 8;

	/**
	 * Words dropped before fuzzy matching a natural-language query.
	 */
	const STOPWORDS = array( 'a', 'an', 'the', 'my', 'me', 'i', 'to', 'for', 'in', 'of', 'on', 'and', 'or', 'with', 'from', 'is', 'are', 'can', 'how', 'do', 'does', 'what', 'that', 'this', 'it', 'be', 'want', 'need', 'please', 'new' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_list_tools';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — List Tools', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Browse or search the Composio tool catalog (Gmail, Slack, GitHub, Notion and 1,000+ more apps). Search by intent ("send an email"), scope to one toolkit, or set connected_only to see only the tools your authenticated accounts can actually run. Results are grouped by toolkit and each entry lists its required arguments, so you rarely need composio_get_tool_schema first.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Optional natural-language query (e.g. "send an email", "create issue"). Matched upstream and re-ranked locally against slug, name and description.', 'mcp-ai-wpoos-pro' ),
				),
				'toolkit'        => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit slug to scope results (e.g. "gmail").', 'mcp-ai-wpoos-pro' ),
				),
				'connected_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Only return tools for toolkits that have a connected account on this connection — the "what can I actually do right now?" view. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'list_toolkits'  => array(
					'type'        => 'boolean',
					'description' => __( 'Return the toolkit (app) directory instead of individual tools. Useful for discovering which toolkit slug to scope to. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum results to return (1-100, default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
				'cursor'         => array(
					'type'        => 'string',
					'description' => __( 'Opaque pagination cursor from a previous call (next_cursor). Ignored when connected_only fans out over several toolkits.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'  => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$search         = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$toolkit        = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$connected_only = ! empty( $arguments['connected_only'] );
		$want_toolkits  = ! empty( $arguments['list_toolkits'] );
		$limit          = isset( $arguments['limit'] ) ? min( 100, max( 1, absint( $arguments['limit'] ) ) ) : 25;
		$cursor         = isset( $arguments['cursor'] ) ? sanitize_text_field( $arguments['cursor'] ) : '';

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( '' !== $toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$client = WP_MCP_AI_Composio_Tools::build_client( $connection );

		if ( $want_toolkits ) {
			return $this->respond_with_toolkits( $client, $connection, $search, $limit, $cursor );
		}

		// Decide which toolkits to query. `GET /tools` accepts one
		// `toolkit_slug`, so a connected-apps view fans out.
		$connected_toolkits = $this->get_connected_toolkits( $client, $connection );
		$scopes             = array( '' );

		if ( '' !== $toolkit ) {
			$scopes = array( $toolkit );
		} elseif ( $connected_only ) {
			if ( empty( $connected_toolkits ) ) {
				return $this->format_success_response(
					__( 'No connected accounts exist on this Composio connection yet, so there are no runnable tools. Use composio_create_connect_link to connect an app first, or call this tool again without connected_only to browse the whole catalog.', 'mcp-ai-wpoos-pro' ),
					array(
						'tools'              => array(),
						'toolkits'           => array(),
						'count'              => 0,
						'total_items'        => 0,
						'next_cursor'        => '',
						'connected_toolkits' => array(),
						'connected_only'     => true,
					)
				);
			}

			$scopes = array_slice( $connected_toolkits, 0, self::MAX_TOOLKIT_FANOUT );
		}

		$collected   = array();
		$total_items = 0;
		$next_cursor = '';
		$single      = 1 === count( $scopes );

		foreach ( $scopes as $scope ) {
			$filters = array(
				// Over-fetch when we intend to re-rank locally so the best match
				// is not lost below the page boundary.
				'limit' => '' !== $search ? min( 100, max( $limit, 50 ) ) : $limit,
			);

			if ( '' !== $scope ) {
				$filters['toolkit_slug'] = $scope;
			}
			if ( '' !== $search ) {
				$filters['query'] = $search;
			}
			if ( '' !== $cursor && $single ) {
				$filters['cursor'] = $cursor;
			}

			$page = $client->list_tools( $filters );

			if ( is_wp_error( $page ) ) {
				// One dead toolkit must not blank the whole listing.
				if ( $single ) {
					return $page;
				}
				continue;
			}

			$total_items += isset( $page['total_items'] ) ? absint( $page['total_items'] ) : 0;

			if ( $single && isset( $page['next_cursor'] ) ) {
				$next_cursor = (string) $page['next_cursor'];
			}

			foreach ( $page['items'] as $tool ) {
				$tool_toolkit = isset( $tool['toolkit'] ) ? (string) $tool['toolkit'] : '';

				if ( '' !== $tool_toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $tool_toolkit ) ) {
					continue;
				}

				// Keyed by slug so a fan-out cannot yield duplicates.
				$collected[ $tool['slug'] ] = $tool;
			}
		}

		$tools = array_values( $collected );

		if ( '' !== $search ) {
			$tools = $this->rank_by_relevance( $tools, $search );
		}

		$returned = array_slice( $tools, 0, $limit );

		// Gate 2 — Escape at exit.
		$items     = array();
		$grouped   = array();
		$connected = array_flip( $connected_toolkits );

		foreach ( $returned as $tool ) {
			$tool_toolkit = isset( $tool['toolkit'] ) ? (string) $tool['toolkit'] : '';

			$entry = array(
				'slug'            => esc_html( $tool['slug'] ),
				'name'            => esc_html( (string) $tool['name'] ),
				'description'     => esc_html( $this->truncate( (string) $tool['description'] ) ),
				'toolkit'         => esc_html( $tool_toolkit ),
				'required_inputs' => array_map( 'esc_html', $tool['required_inputs'] ),
				'connected'       => isset( $connected[ $tool_toolkit ] ),
				'deprecated'      => ! empty( $tool['deprecated'] ),
			);

			$items[] = $entry;

			$key = '' !== $tool_toolkit ? esc_html( $tool_toolkit ) : esc_html__( 'other', 'mcp-ai-wpoos-pro' );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $entry['slug'];
		}

		return $this->format_success_response(
			$this->build_message( count( $items ), $search, $toolkit, $connected_only, count( $grouped ) ),
			array(
				'tools'              => $items,
				'toolkits'           => $grouped,
				'count'              => count( $items ),
				'total_items'        => $total_items,
				'next_cursor'        => esc_html( $next_cursor ),
				'connected_toolkits' => array_map( 'esc_html', $connected_toolkits ),
				'connected_only'     => $connected_only,
				'searched'           => esc_html( $search ),
			)
		);
	}

	/**
	 * Return the toolkit (app) directory.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client instance.
	 * @param array                     $connection Connection record.
	 * @param string                    $search     Optional search term.
	 * @param int                       $limit      Result limit.
	 * @param string                    $cursor     Pagination cursor.
	 * @return array|WP_Error
	 */
	private function respond_with_toolkits( $client, array $connection, $search, $limit, $cursor ) {
		$filters = array(
			'limit'   => $limit,
			'sort_by' => 'usage',
		);

		if ( '' !== $search ) {
			$filters['search'] = $search;
		}
		if ( '' !== $cursor ) {
			$filters['cursor'] = $cursor;
		}

		$page = $client->list_toolkits( $filters );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$connected = array_flip( $this->get_connected_toolkits( $client, $connection ) );

		// Gate 2 — Escape at exit.
		$items = array();
		foreach ( $page['items'] as $toolkit ) {
			if ( ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit['slug'] ) ) {
				continue;
			}

			$items[] = array(
				'slug'        => esc_html( $toolkit['slug'] ),
				'name'        => esc_html( $toolkit['name'] ),
				'description' => esc_html( $this->truncate( $toolkit['description'] ) ),
				'categories'  => array_map( 'esc_html', $toolkit['categories'] ),
				'tools_count' => absint( $toolkit['tools_count'] ),
				'connected'   => isset( $connected[ $toolkit['slug'] ] ),
			);
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of toolkits */
				_n( 'Found %d Composio toolkit.', 'Found %d Composio toolkits.', count( $items ), 'mcp-ai-wpoos-pro' ),
				count( $items )
			),
			array(
				'toolkits'           => $items,
				'count'              => count( $items ),
				'total_items'        => isset( $page['total_items'] ) ? absint( $page['total_items'] ) : count( $items ),
				'next_cursor'        => esc_html( isset( $page['next_cursor'] ) ? (string) $page['next_cursor'] : '' ),
				'connected_toolkits' => array_map( 'esc_html', array_keys( $connected ) ),
			)
		);
	}

	/**
	 * Collect the toolkit slugs that have at least one connected account.
	 *
	 * Failure is not fatal — the catalog is still browsable without knowing
	 * which apps are connected.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client     Client instance.
	 * @param array                     $connection Connection record.
	 * @return array List of lowercase toolkit slugs.
	 */
	private function get_connected_toolkits( $client, array $connection ) {
		$accounts = $client->list_connected_accounts();

		if ( is_wp_error( $accounts ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || empty( $account['toolkit'] ) ) {
				continue;
			}

			$slug = strtolower( (string) $account['toolkit'] );

			if ( ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $slug ) ) {
				continue;
			}

			$slugs[ $slug ] = true;
		}

		return array_keys( $slugs );
	}

	/**
	 * Re-rank catalog results against a natural-language query.
	 *
	 * Composio's `query` parameter is a soft filter, so a semantic phrase can
	 * come back with the best match buried. Scoring locally on slug, name and
	 * description puts the obvious answer first and drops rows that match
	 * nothing at all.
	 *
	 * @since 1.4.1
	 *
	 * @param array  $tools  Normalised tool records.
	 * @param string $search Search phrase.
	 * @return array Ranked tools, best first.
	 */
	private function rank_by_relevance( array $tools, $search ) {
		$needle = strtolower( trim( $search ) );
		$parts  = preg_split( '/[^a-z0-9]+/', $needle );
		$tokens = array_values(
			array_filter(
				is_array( $parts ) ? $parts : array(),
				static function ( $token ) {
					return '' !== $token && strlen( $token ) > 1 && ! in_array( $token, self::STOPWORDS, true );
				}
			)
		);

		if ( empty( $tokens ) ) {
			return $tools;
		}

		$scored = array();

		foreach ( $tools as $index => $tool ) {
			$slug        = strtolower( str_replace( '_', ' ', (string) $tool['slug'] ) );
			$name        = strtolower( (string) $tool['name'] );
			$description = strtolower( (string) $tool['description'] );

			$score   = 0;
			$matched = 0;

			foreach ( $tokens as $token ) {
				$hit = false;

				if ( false !== strpos( $slug, $token ) ) {
					$score += 20;
					$hit    = true;
				}
				if ( false !== strpos( $name, $token ) ) {
					$score += 10;
					$hit    = true;
				}
				if ( false !== strpos( $description, $token ) ) {
					$score += 3;
					$hit    = true;
				}

				if ( $hit ) {
					++$matched;
				}
			}

			if ( 0 === $matched ) {
				continue;
			}

			// Reward covering the whole phrase, and nudge deprecated tools down.
			$score += count( $tokens ) === $matched ? 25 : 0;
			$score -= ! empty( $tool['deprecated'] ) ? 30 : 0;

			$scored[] = array(
				'score' => $score,
				'order' => $index,
				'tool'  => $tool,
			);
		}

		// Nothing matched locally — trust the upstream soft filter rather than
		// returning an empty list (the failure mode this tool exists to fix).
		if ( empty( $scored ) ) {
			return $tools;
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}

				return $a['order'] <=> $b['order'];
			}
		);

		return array_column( $scored, 'tool' );
	}

	/**
	 * Compose the human-readable result summary.
	 *
	 * @since 1.4.1
	 *
	 * @param int    $count          Number of tools returned.
	 * @param string $search         Search phrase.
	 * @param string $toolkit        Toolkit scope.
	 * @param bool   $connected_only Whether the connected-apps view was used.
	 * @param int    $toolkit_count  Number of toolkits represented.
	 * @return string
	 */
	private function build_message( $count, $search, $toolkit, $connected_only, $toolkit_count ) {
		if ( 0 === $count ) {
			if ( '' !== $search ) {
				return sprintf(
					/* translators: %s: search phrase */
					__( 'No Composio tools matched "%s". Try a broader phrase, or call this tool with list_toolkits set to true to see which apps are available.', 'mcp-ai-wpoos-pro' ),
					esc_html( $search )
				);
			}

			return __( 'No Composio tools were returned. Check the toolkit slug, or call this tool with list_toolkits set to true to browse available apps.', 'mcp-ai-wpoos-pro' );
		}

		if ( '' !== $toolkit ) {
			return sprintf(
				/* translators: 1: number of tools, 2: toolkit slug */
				_n( 'Found %1$d tool in the %2$s toolkit.', 'Found %1$d tools in the %2$s toolkit.', $count, 'mcp-ai-wpoos-pro' ),
				$count,
				esc_html( $toolkit )
			);
		}

		if ( $connected_only ) {
			return sprintf(
				/* translators: 1: number of tools, 2: number of toolkits */
				__( 'Found %1$d runnable tool(s) across %2$d connected app(s).', 'mcp-ai-wpoos-pro' ),
				$count,
				$toolkit_count
			);
		}

		return sprintf(
			/* translators: 1: number of tools, 2: number of toolkits */
			__( 'Found %1$d Composio tool(s) across %2$d toolkit(s).', 'mcp-ai-wpoos-pro' ),
			$count,
			$toolkit_count
		);
	}

	/**
	 * Shorten a description for catalog output.
	 *
	 * Composio descriptions can run to several hundred words, which crowds out
	 * the rest of the listing in a model's context window.
	 *
	 * @since 1.4.1
	 *
	 * @param string $text  Description text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private function truncate( $text, $limit = 240 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $limit ), " \t\n\r\0\x0B.," ) . '…';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'read', 'pro', 'requires-capability', 'remote-api' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'composio',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'low',
		);
	}
}
