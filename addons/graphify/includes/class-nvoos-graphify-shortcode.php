<?php
/**
 * Graphify Shortcode & Block — renders the knowledge‑graph explorer.
 *
 * @package NV_oOS_Graphify
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [nvoos_graphify] shortcode and the nvoos-graphify/graph block,
 * enqueues Cytoscape.js + frontend assets, and fetches graph data from the DB.
 *
 * @since 0.4.0
 */
class NV_oOS_Graphify_Shortcode {

	/**
	 * Whether frontend assets have already been enqueued for this request.
	 *
	 * @since 0.4.0
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Bootstrap shortcode and block registrations.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'nvoos_graphify', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register the server‑side‑rendered block.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'nvoos-graphify/graph',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'mode'         => array(
						'type'    => 'string',
						'default' => 'full',
					),
					'community_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'post_id'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'height'       => array(
						'type'    => 'string',
						'default' => '500px',
					),
					'max_nodes'    => array(
						'type'    => 'integer',
						'default' => 500,
					),
				),
			)
		);
	}

	/**
	 * Shortcode callback for [nvoos_graphify].
	 *
	 * @since 0.4.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'mode'         => 'full',
				'community_id' => 0,
				'post_id'      => 0,
				'height'       => '500px',
				'max_nodes'    => 500,
			),
			$atts,
			'nvoos_graphify'
		);

		return self::render( $atts );
	}

	/**
	 * Block render callback.
	 *
	 * @since 0.4.0
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'mode'         => 'full',
				'community_id' => 0,
				'post_id'      => 0,
				'height'       => '500px',
				'max_nodes'    => 500,
			)
		);

		return self::render( $attributes );
	}

	/**
	 * Shared renderer used by both the shortcode and the block.
	 *
	 * @since 0.4.0
	 *
	 * @param array $atts {
	 *     Rendering options.
	 *
	 *     @type string $mode         Graph mode: 'full', 'community', or 'ego'. Default 'full'.
	 *     @type int    $community_id Community ID (community mode only).
	 *     @type int    $post_id      Post ID (ego mode only).
	 *     @type string $height       CSS height value. Default '500px'.
	 *     @type int    $max_nodes    Maximum nodes to render. Default 500.
	 * }
	 * @return string Rendered HTML.
	 */
	private static function render( $atts ) {
		$allowed_modes = array( 'full', 'community', 'ego' );
		$mode          = in_array( $atts['mode'], $allowed_modes, true ) ? $atts['mode'] : 'full';
		$community_id  = absint( $atts['community_id'] );
		$post_id       = absint( $atts['post_id'] );
		$height        = sanitize_text_field( $atts['height'] );
		$max_nodes     = min( max( absint( $atts['max_nodes'] ), 1 ), 10000 );

		self::enqueue_frontend_assets();

		$graph_data = self::get_graph_data(
			$mode,
			array(
				'community_id' => $community_id,
				'post_id'      => $post_id,
				'max_nodes'    => $max_nodes,
			)
		);

		$json = wp_json_encode( $graph_data );

		ob_start();
		?>
		<div class="nvoos-graphify-frontend"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-max-nodes="<?php echo esc_attr( $max_nodes ); ?>"
			style="height: <?php echo esc_attr( $height ); ?>; width: 100%;">
			<div class="nvoos-graphify-canvas"></div>
			<script type="application/json" class="nvoos-graphify-data"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded via wp_json_encode. ?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue Cytoscape.js and the Graphify frontend bundle.
	 *
	 * Assets are enqueued at most once per request.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}

		$version = defined( 'NVOOS_GRAPHIFY_VERSION' ) ? NVOOS_GRAPHIFY_VERSION : '0.4.0';
		$base    = defined( 'NVOOS_GRAPHIFY_URL' ) ? NVOOS_GRAPHIFY_URL : '';

		wp_enqueue_script(
			'cytoscape',
			$base . 'assets/js/vendor/cytoscape.min.js',
			array(),
			'3.30.4',
			true
		);

		wp_enqueue_script(
			'cytoscape-fcose',
			$base . 'assets/js/vendor/cytoscape-fcose.min.js',
			array( 'cytoscape' ),
			'2.2.0',
			true
		);

		wp_enqueue_script(
			'graphify-frontend',
			$base . 'assets/js/graphify-frontend.js',
			array( 'cytoscape' ),
			$version,
			true
		);

		wp_enqueue_style(
			'graphify-frontend-css',
			$base . 'assets/css/graphify-frontend.css',
			array(),
			$version
		);

		self::$assets_enqueued = true;
	}

	/**
	 * Load graph data from the database for the requested mode.
	 *
	 * @since 0.4.0
	 *
	 * @param string $mode    One of 'full', 'community', or 'ego'.
	 * @param array  $options {
	 *     Mode‑specific options.
	 *
	 *     @type int $community_id Community to filter by (community mode).
	 *     @type int $post_id      Centre post for ego graph (ego mode).
	 *     @type int $max_nodes    Maximum number of nodes to return.
	 * }
	 * @return array {
	 *     Cytoscape‑compatible graph payload.
	 *
	 *     @type array $nodes Array of node elements.
	 *     @type array $edges Array of edge elements.
	 * }
	 */
	public static function get_graph_data( $mode, $options ) {
		global $wpdb;

		$graph_id  = class_exists( 'NV_oOS_Graphify' ) ? NV_oOS_Graphify::get_instance()->get_graph_id() : 1;
		$max_nodes = isset( $options['max_nodes'] ) ? absint( $options['max_nodes'] ) : 500;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		$nodes    = array();
		$node_ids = array();

		switch ( $mode ) {
			case 'ego':
				$nodes    = self::get_ego_nodes( $graph_id, $options, $max_nodes );
				$node_ids = wp_list_pluck( $nodes, 'node_id' );
				break;

			case 'community':
				$community_id = isset( $options['community_id'] ) ? absint( $options['community_id'] ) : 0;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$nodes = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT node_id, label, node_type, community_id, degree
						FROM {$nodes_table}
						WHERE graph_id = %d AND community_id = %d
						ORDER BY degree DESC
						LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
						$graph_id,
						$community_id,
						$max_nodes
					),
					ARRAY_A
				);

				$node_ids = wp_list_pluck( $nodes, 'node_id' );
				break;

			default: // 'full'.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$nodes = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT node_id, label, node_type, community_id, degree
						FROM {$nodes_table}
						WHERE graph_id = %d
						ORDER BY degree DESC
						LIMIT %d",
						$graph_id,
						$max_nodes
					),
					ARRAY_A
				);

				$node_ids = wp_list_pluck( $nodes, 'node_id' );
				break;
		}

		$edges = self::get_edges_for_nodes( $graph_id, $node_ids );

		return self::format_cytoscape_data( $nodes, $edges );
	}

	/**
	 * Perform a BFS of depth 2 from the given post node and return the resulting nodes.
	 *
	 * @since 0.4.0
	 *
	 * @param int   $graph_id  Graph identifier.
	 * @param array $options   Must contain 'post_id'.
	 * @param int   $max_nodes Upper bound on returned nodes.
	 * @return array Raw node rows.
	 */
	private static function get_ego_nodes( $graph_id, $options, $max_nodes ) {
		global $wpdb;

		$post_id   = isset( $options['post_id'] ) ? absint( $options['post_id'] ) : 0;
		$center_id = 'post_' . $post_id;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// Depth‑1 neighbours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$depth1_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT
					CASE WHEN source_node_id = %s THEN target_node_id ELSE source_node_id END AS neighbor_id
				FROM {$edges_table}
				WHERE graph_id = %d AND ( source_node_id = %s OR target_node_id = %s )",
				$center_id,
				$graph_id,
				$center_id,
				$center_id
			),
			ARRAY_A
		);

		$depth1_ids = wp_list_pluck( $depth1_rows, 'neighbor_id' );
		$all_ids    = array_merge( array( $center_id ), $depth1_ids );

		// Depth‑2 neighbours.
		if ( ! empty( $depth1_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $depth1_ids ), '%s' ) );
			$prepare_args = array_merge( array( $graph_id ), $depth1_ids, $depth1_ids );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$depth2_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT
						CASE WHEN source_node_id IN ({$placeholders}) THEN target_node_id ELSE source_node_id END AS neighbor_id
					FROM {$edges_table}
					WHERE graph_id = %d
						AND ( source_node_id IN ({$placeholders}) OR target_node_id IN ({$placeholders}) )",
					array_merge( $prepare_args, $depth1_ids )
				),
				ARRAY_A
			);

			$depth2_ids = wp_list_pluck( $depth2_rows, 'neighbor_id' );
			$all_ids    = array_unique( array_merge( $all_ids, $depth2_ids ) );
		}

		if ( empty( $all_ids ) ) {
			return array();
		}

		$all_ids      = array_slice( $all_ids, 0, $max_nodes );
		$placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nodes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, community_id, degree
				FROM {$nodes_table}
				WHERE graph_id = %d AND node_id IN ({$placeholders})",
				array_merge( array( $graph_id ), $all_ids )
			),
			ARRAY_A
		);

		return is_array( $nodes ) ? $nodes : array();
	}

	/**
	 * Retrieve edges whose source AND target are within the supplied node set.
	 *
	 * @since 0.4.0
	 *
	 * @param int   $graph_id Graph identifier.
	 * @param array $node_ids List of node_id strings.
	 * @return array Raw edge rows.
	 */
	private static function get_edges_for_nodes( $graph_id, $node_ids ) {
		global $wpdb;

		if ( empty( $node_ids ) ) {
			return array();
		}

		$edges_table  = $wpdb->prefix . 'nvoos_graph_edges';
		$placeholders = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_node_id, target_node_id, relation, confidence
				FROM {$edges_table}
				WHERE graph_id = %d
					AND source_node_id IN ({$placeholders})
					AND target_node_id IN ({$placeholders})",
				array_merge( array( $graph_id ), $node_ids, $node_ids )
			),
			ARRAY_A
		);

		return is_array( $edges ) ? $edges : array();
	}

	/**
	 * Transform raw DB rows into Cytoscape.js element arrays.
	 *
	 * @since 0.4.0
	 *
	 * @param array $nodes Raw node rows.
	 * @param array $edges Raw edge rows.
	 * @return array Cytoscape‑compatible payload with 'nodes' and 'edges' keys.
	 */
	private static function format_cytoscape_data( $nodes, $edges ) {
		$cy_nodes = array();
		$cy_edges = array();

		foreach ( $nodes as $node ) {
			$cy_nodes[] = array(
				'data' => array(
					'id'        => sanitize_text_field( $node['node_id'] ),
					'label'     => sanitize_text_field( $node['label'] ),
					'type'      => sanitize_text_field( $node['node_type'] ),
					'community' => absint( $node['community_id'] ),
					'degree'    => absint( $node['degree'] ),
				),
			);
		}

		foreach ( $edges as $edge ) {
			$cy_edges[] = array(
				'data' => array(
					'id'         => 'e' . absint( $edge['id'] ),
					'source'     => sanitize_text_field( $edge['source_node_id'] ),
					'target'     => sanitize_text_field( $edge['target_node_id'] ),
					'relation'   => sanitize_text_field( $edge['relation'] ),
					'confidence' => sanitize_text_field( $edge['confidence'] ),
				),
			);
		}

		return array(
			'nodes' => $cy_nodes,
			'edges' => $cy_edges,
		);
	}
}
