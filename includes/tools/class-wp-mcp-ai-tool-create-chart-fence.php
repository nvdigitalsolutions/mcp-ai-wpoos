<?php
/**
 * Create Chart Fence tool.
 *
 * Validates chart data and returns a normalised `nvoos-chart` fenced code
 * block plus a plain-text fallback table. The fence renders on every chat
 * surface that supports the built-in chart recipe renderer (base plugin,
 * chat SPA, Pro SPA) — no iframe, no CDN, no third-party chart library.
 *
 * The validation rules mirror the client-side `nvoos-chart` v2 schema in:
 *  - assets/js/chat-chart-recipes.js (base plugin)
 *  - addons/chat-spa/src/api/chartRecipes.ts
 *  - addons/pro/assets/spa-v2/src/components/shared/chartRecipes.ts
 * Keep the type whitelist, caps and colour rules in sync across all four.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tool that produces validated, normalised `nvoos-chart` fences.
 */
class WP_MCP_AI_Tool_Create_Chart_Fence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	const MAX_SERIES       = 12;
	const MAX_POINTS       = 120;
	const MAX_LABELS       = 40;
	const MAX_HEATMAP_ROWS = 20;
	const MAX_STR          = 60;
	const MAX_TITLE        = 120;

	/**
	 * Chart type whitelist — must match the client CHART_TYPE_LABELS keys.
	 *
	 * @var array
	 */
	private $valid_types = array(
		'bar',
		'column',
		'line',
		'area',
		'dot',
		'donut',
		'heatmap',
		'histogram',
		'box',
		'strip',
		'stem',
		'violin',
		'pie',
		'waffle',
		'unit',
		'diverging',
		'lollipop',
		'dumbbell',
		'slope',
		'bullet',
		'pyramid',
		'waterfall',
		'candlestick',
		'gantt',
		'sparkline',
		'radial',
		'dial',
		'race',
		'grid',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_chart_fence';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Chart Fence', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates chart data and returns a normalised nvoos-chart fenced code block that renders as a built-in HTML/CSS chart on every chat surface, plus a plain-text table fallback.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Producing deterministic, themeable charts that render inline in chat without iframes, or validating chart JSON before it is embedded in a message.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'For interactive Chart.js charts use create_chart; for AI-generated chart images use generate_chart; for flow/diagram graphics use generate_mermaid.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'create_chart', 'generate_chart', 'generate_mermaid' ),
			'notes'           => __( 'The fence renders everywhere the built-in chart recipes are supported; the fallback table degrades gracefully in plain-text channels.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Chart type (distribution, part-to-whole, ranking, change, special or grid families).', 'mcp-ai-wpoos' ),
					'enum'        => $this->valid_types,
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Chart title (optional, max 120 characters).', 'mcp-ai-wpoos' ),
				),
				'caption'   => array(
					'type'        => 'string',
					'description' => __( 'Caption shown under the chart (optional).', 'mcp-ai-wpoos' ),
				),
				'labels'    => array(
					'type'        => 'array',
					'description' => __( 'Row/point labels (max 40; 20 for heatmaps).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'values'    => array(
					'type'        => 'array',
					'description' => __( 'Single-series shorthand: numeric values (max 120).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'number',
					),
				),
				'series'    => array(
					'type'        => 'array',
					'description' => __( 'Named series (max 12). Each series: name, values (numbers, max 120) and optional CSS colour.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type' => 'string',
							),
							'values' => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'number',
								),
							),
							'color'  => array(
								'type' => 'string',
							),
						),
					),
				),
				'data'      => array(
					'type'        => 'object',
					'description' => __( 'Alternate input: { "items": [ { "label", "value" | "values" } ] } for named rows.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'label'  => array(
										'type' => 'string',
									),
									'value'  => array(
										'type' => 'number',
									),
									'values' => array(
										'type'  => 'array',
										'items' => array(
											'type' => 'number',
										),
									),
								),
							),
						),
					),
				),
				'mode'      => array(
					'type'        => 'string',
					'description' => __( 'Series display mode for bar/column: grouped (default), stacked or 100.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'grouped', 'stacked', '100' ),
				),
				'direction' => array(
					'type'        => 'string',
					'description' => __( 'Axis direction for bar/column: horizontal or vertical.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'horizontal', 'vertical' ),
				),
				'sort'      => array(
					'type'        => 'string',
					'description' => __( 'Sort rows by value: asc, desc or none (default).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'asc', 'desc', 'none' ),
				),
				'unit'      => array(
					'type'        => 'string',
					'description' => __( 'Value unit label appended to formatted numbers (optional).', 'mcp-ai-wpoos' ),
				),
				'animate'   => array(
					'type'        => 'boolean',
					'description' => __( 'Enable entry animation (true) or race replay animation ("race").', 'mcp-ai-wpoos' ),
					'anyOf'       => array(
						array(
							'type' => 'boolean',
						),
						array(
							'type' => 'string',
							'enum' => array( 'race' ),
						),
					),
				),
				'table'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include the collapsed data table (default true). Set false to hide it.', 'mcp-ai-wpoos' ),
				),
				'target'    => array(
					'type'        => 'number',
					'description' => __( 'Target value for bullet charts (optional).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get shortcut tasks for the tool.
	 *
	 * @return array
	 */
	public function get_shortcut_tasks() {
		return array(
			'create_chart_fence_bar'          => __( 'Create a bar chart fence', 'mcp-ai-wpoos' ),
			'create_chart_fence_line'         => __( 'Create a line chart fence', 'mcp-ai-wpoos' ),
			'create_chart_fence_distribution' => __( 'Create a distribution chart fence', 'mcp-ai-wpoos' ),
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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Success array (canonical envelope) or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to create chart fences.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id && ( ! user_can( $user_id, 'read' ) || ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create chart fences.', 'mcp-ai-wpoos' )
			);
		}

		// Gate 1: sanitise every argument at entry.
		$type = isset( $arguments['type'] ) ? sanitize_text_field( wp_unslash( (string) $arguments['type'] ) ) : '';

		if ( ! in_array( $type, $this->valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_chart_type',
				sprintf(
					/* translators: %s: valid chart types */
					__( 'Invalid chart type. Must be one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $this->valid_types )
				),
				array( 'status' => 400 )
			);
		}

		$spec = $this->normalise_spec( $type, $arguments );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		// Gate 2: escape at exit — everything leaves JSON-encoded or as plain
		// text built from the sanitised spec.
		$json  = wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$fence = "```nvoos-chart\n" . $json . "\n```";
		$table = $this->build_fallback_table( $spec );

		$type_labels = array(
			'bar'         => __( 'Bar chart', 'mcp-ai-wpoos' ),
			'column'      => __( 'Column chart', 'mcp-ai-wpoos' ),
			'line'        => __( 'Line chart', 'mcp-ai-wpoos' ),
			'area'        => __( 'Area chart', 'mcp-ai-wpoos' ),
			'dot'         => __( 'Dot plot', 'mcp-ai-wpoos' ),
			'donut'       => __( 'Donut chart', 'mcp-ai-wpoos' ),
			'heatmap'     => __( 'Heatmap', 'mcp-ai-wpoos' ),
			'histogram'   => __( 'Histogram', 'mcp-ai-wpoos' ),
			'box'         => __( 'Box plot', 'mcp-ai-wpoos' ),
			'strip'       => __( 'Strip plot', 'mcp-ai-wpoos' ),
			'stem'        => __( 'Stem plot', 'mcp-ai-wpoos' ),
			'violin'      => __( 'Violin plot', 'mcp-ai-wpoos' ),
			'pie'         => __( 'Pie chart', 'mcp-ai-wpoos' ),
			'waffle'      => __( 'Waffle chart', 'mcp-ai-wpoos' ),
			'unit'        => __( 'Unit chart', 'mcp-ai-wpoos' ),
			'diverging'   => __( 'Diverging bar chart', 'mcp-ai-wpoos' ),
			'lollipop'    => __( 'Lollipop chart', 'mcp-ai-wpoos' ),
			'dumbbell'    => __( 'Dumbbell chart', 'mcp-ai-wpoos' ),
			'slope'       => __( 'Slope chart', 'mcp-ai-wpoos' ),
			'bullet'      => __( 'Bullet chart', 'mcp-ai-wpoos' ),
			'pyramid'     => __( 'Pyramid chart', 'mcp-ai-wpoos' ),
			'waterfall'   => __( 'Waterfall chart', 'mcp-ai-wpoos' ),
			'candlestick' => __( 'Candlestick chart', 'mcp-ai-wpoos' ),
			'gantt'       => __( 'Gantt chart', 'mcp-ai-wpoos' ),
			'sparkline'   => __( 'Sparkline', 'mcp-ai-wpoos' ),
			'radial'      => __( 'Radial chart', 'mcp-ai-wpoos' ),
			'dial'        => __( 'Dial gauge', 'mcp-ai-wpoos' ),
			'race'        => __( 'Race chart', 'mcp-ai-wpoos' ),
			'grid'        => __( 'Chart grid', 'mcp-ai-wpoos' ),
		);
		$chart_label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : __( 'Chart', 'mcp-ai-wpoos' );

		$message = sprintf(
			/* translators: %s: chart type label */
			__( 'Created a %s fence.', 'mcp-ai-wpoos' ),
			$chart_label
		);

		return array(
			'message'        => $message,
			'text'           => $message,
			'fence'          => $fence,
			'spec'           => $spec,
			'chart_type'     => $type,
			'fallback_table' => $table,
			'output_format'  => 'chart_fence',
		);
	}

	/**
	 * Normalise the argument payload into a client-compatible chart spec.
	 *
	 * Mirrors `normaliseSpec` in the three JS/TS renderers: same caps,
	 * same coercion rules, same colour whitelist.
	 *
	 * @param string $type      Sanitised chart type.
	 * @param array  $arguments Raw tool arguments (unsanitised).
	 * @return array|WP_Error Normalised spec or error.
	 */
	private function normalise_spec( $type, array $arguments ) {
		$spec = array( 'type' => $type );

		if ( ! empty( $arguments['title'] ) ) {
			$spec['title'] = substr( sanitize_text_field( wp_unslash( (string) $arguments['title'] ) ), 0, self::MAX_TITLE );
		}
		if ( ! empty( $arguments['caption'] ) ) {
			$spec['caption'] = substr( sanitize_text_field( wp_unslash( (string) $arguments['caption'] ) ), 0, self::MAX_TITLE );
		}

		$label_max = 'heatmap' === $type ? self::MAX_HEATMAP_ROWS : self::MAX_LABELS;
		$labels    = isset( $arguments['labels'] ) && is_array( $arguments['labels'] )
			? $this->sanitise_labels( $arguments['labels'], $label_max )
			: array();

		$usable_series = null;

		if ( isset( $arguments['series'] ) && is_array( $arguments['series'] ) ) {
			$series = array_slice( $arguments['series'], 0, self::MAX_SERIES );
			$clean  = array();
			foreach ( $series as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['values'] ) || ! is_array( $entry['values'] ) ) {
					continue;
				}
				$values = $this->to_finite_numbers( $entry['values'], self::MAX_POINTS );
				if ( null === $values ) {
					continue;
				}
				$row = array( 'values' => $values );
				if ( ! empty( $entry['name'] ) ) {
					$row['name'] = substr( sanitize_text_field( wp_unslash( (string) $entry['name'] ) ), 0, self::MAX_STR );
				}
				$color = $this->sanitize_color( isset( $entry['color'] ) ? $entry['color'] : null );
				if ( null !== $color ) {
					$row['color'] = $color;
				}
				$clean[] = $row;
			}
			if ( $clean ) {
				$usable_series = $clean;
			}
		} elseif ( isset( $arguments['data'] ) && is_array( $arguments['data'] ) && isset( $arguments['data']['items'] ) && is_array( $arguments['data']['items'] ) ) {
			// `data.items` alternate input — mirrors the client coercion:
			//   {label, value}    → one unnamed series + labels
			//   {label, values[]} → one named series per item
			$items         = array_slice( $arguments['data']['items'], 0, self::MAX_LABELS );
			$item_series   = array();
			$item_labels   = array();
			$shared_values = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$label = isset( $item['label'] ) ? substr( sanitize_text_field( wp_unslash( (string) $item['label'] ) ), 0, self::MAX_STR ) : '';
				if ( isset( $item['values'] ) && is_array( $item['values'] ) ) {
					$values = $this->to_finite_numbers( $item['values'], self::MAX_POINTS );
					if ( null === $values ) {
						continue;
					}
					$row = array( 'values' => $values );
					if ( '' !== $label ) {
						$row['name'] = $label;
					}
					$item_series[] = $row;
				} elseif ( isset( $item['value'] ) ) {
					$value = $this->to_finite_numbers( array( $item['value'] ), 1 );
					if ( null === $value ) {
						continue;
					}
					$item_labels[]   = $label;
					$shared_values[] = $value[0];
				}
			}
			if ( $shared_values ) {
				array_unshift( $item_series, array( 'values' => $shared_values ) );
			}
			if ( $item_labels ) {
				$labels = $item_labels;
			}
			if ( $item_series ) {
				$usable_series = $item_series;
			}
		} elseif ( isset( $arguments['values'] ) && is_array( $arguments['values'] ) ) {
			$values = $this->to_finite_numbers( $arguments['values'], self::MAX_POINTS );
			if ( null !== $values ) {
				$usable_series = array( array( 'values' => $values ) );
			}
		}

		if ( null === $usable_series ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_chart_data',
				__( 'Chart data is required: provide values, series or data.items with numeric entries.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$spec['series'] = $usable_series;
		if ( $labels ) {
			$spec['labels'] = $labels;
		}

		// Optional v2 keys — whitelisted values only.
		if ( isset( $arguments['mode'] ) && in_array( $arguments['mode'], array( 'grouped', 'stacked', '100' ), true ) ) {
			$spec['mode'] = $arguments['mode'];
		}
		if ( isset( $arguments['direction'] ) && in_array( $arguments['direction'], array( 'horizontal', 'vertical' ), true ) ) {
			$spec['direction'] = $arguments['direction'];
		}
		if ( isset( $arguments['sort'] ) && in_array( $arguments['sort'], array( 'asc', 'desc' ), true ) ) {
			$spec['sort'] = $arguments['sort'];
		}
		if ( isset( $arguments['unit'] ) ) {
			$unit = substr( sanitize_text_field( wp_unslash( (string) $arguments['unit'] ) ), 0, self::MAX_STR );
			if ( '' !== $unit ) {
				$spec['unit'] = $unit;
			}
		}
		if ( isset( $arguments['animate'] ) ) {
			if ( true === $arguments['animate'] || 'race' === $arguments['animate'] ) {
				$spec['animate'] = true === $arguments['animate'] ? true : 'race';
			}
		}
		if ( isset( $arguments['table'] ) ) {
			$spec['table'] = (bool) $arguments['table'];
		}
		if ( isset( $arguments['target'] ) ) {
			$target = $this->to_finite_numbers( array( $arguments['target'] ), 1 );
			if ( null !== $target ) {
				$spec['target'] = $target[0];
			}
		}

		return $spec;
	}

	/**
	 * Coerce an array to finite numbers, capped at $max entries.
	 *
	 * Numeric strings are coerced; non-finite entries are dropped. Returns
	 * null when nothing usable remains (mirrors `toFiniteNumbers`).
	 *
	 * @param array $raw Raw values.
	 * @param int   $max Maximum entries.
	 * @return array|null
	 */
	private function to_finite_numbers( $raw, $max ) {
		$out = array();
		foreach ( array_slice( $raw, 0, $max ) as $entry ) {
			if ( is_numeric( $entry ) ) {
				$value = (float) $entry;
				if ( is_finite( $value ) ) {
					$out[] = $value;
				}
			}
		}
		return $out ? $out : null;
	}

	/**
	 * Sanitise a label array (strings, capped length and count).
	 *
	 * @param array $raw Raw labels.
	 * @param int   $max Maximum entries.
	 * @return array
	 */
	private function sanitise_labels( $raw, $max ) {
		$out = array();
		foreach ( array_slice( $raw, 0, $max ) as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$out[] = substr( sanitize_text_field( wp_unslash( (string) $entry ) ), 0, self::MAX_STR );
		}
		return $out;
	}

	/**
	 * Whitelist a CSS colour (hex, rgb() or hsl()) — mirrors COLOR_RE.
	 *
	 * @param mixed $raw Raw colour value.
	 * @return string|null
	 */
	private function sanitize_color( $raw ) {
		if ( null === $raw || ! is_string( $raw ) ) {
			return null;
		}
		$color = sanitize_text_field( wp_unslash( $raw ) );
		if ( ! preg_match( '/^(#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\))$/i', $color ) ) {
			return null;
		}
		return $color;
	}

	/**
	 * Build the plain-text fallback table from the normalised spec.
	 *
	 * @param array $spec Normalised spec.
	 * @return string
	 */
	private function build_fallback_table( array $spec ) {
		$lines  = array();
		$labels = isset( $spec['labels'] ) ? $spec['labels'] : array();
		$series = $spec['series'];

		$header = array( isset( $spec['title'] ) ? (string) $spec['title'] : '' );
		foreach ( $series as $entry ) {
			$header[] = isset( $entry['name'] ) ? (string) $entry['name'] : __( 'Value', 'mcp-ai-wpoos' );
		}
		$lines[] = implode( ' | ', $header );
		$lines[] = implode( ' | ', array_fill( 0, count( $header ), '---' ) );

		$rows = 0;
		foreach ( $series as $entry ) {
			$rows = max( $rows, count( $entry['values'] ) );
		}
		for ( $i = 0; $i < $rows; $i++ ) {
			$row = array( isset( $labels[ $i ] ) ? (string) $labels[ $i ] : '' );
			foreach ( $series as $entry ) {
				$value = isset( $entry['values'][ $i ] ) ? $entry['values'][ $i ] : 0;
				$row[] = (string) $value;
			}
			$lines[] = implode( ' | ', $row );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.4.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'data_analytics',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'analyst', 'business_consultant', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Never modifies site data.
			'requires-capability',  // Requires user capabilities.
			'local-only',           // Pure validation + JSON — no network.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'parameter_constraints' => array(
				'required_fields' => array( 'type' ),
				'optional_fields' => array( 'title', 'caption', 'labels', 'values', 'series', 'data', 'mode', 'direction', 'sort', 'unit', 'animate', 'table', 'target' ),
				'max_series'      => self::MAX_SERIES,
				'max_data_points' => self::MAX_POINTS,
				'max_labels'      => self::MAX_LABELS,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 5,
				'max_execution_time'  => 15,
			),
			'response_constraints'  => array(
				'max_size'            => 262144, // 256KB max fence size.
				'supports_streaming'  => false,
				'supports_pagination' => false,
			),
			'dependencies'          => array(
				'required_extensions' => array(),
				'external_services'   => array(),
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,
				'requires_lock'    => false,
				'cache_ttl'        => 0,
				'retry_strategy'   => 'simple',
				'max_retries'      => 1,
				'idempotent'       => true,
			),
			'resource_usage'        => array(
				'memory_intensive' => false,
				'cpu_intensive'    => false,
				'io_intensive'     => false,
			),
		);
	}
}
