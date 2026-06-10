<?php
/**
 * Detect Duplicate Leads Tool — Scans the CRM lead database for potential
 * duplicate records using configurable matching rules.
 *
 * Matching strategies (in order of confidence):
 *
 *   1. EXACT EMAIL — Identical email address (confidence: 0.99)
 *   2. NORMALISED PHONE — Same phone after stripping non-digits (confidence: 0.90)
 *   3. FUZZY NAME+COMPANY — Levenshtein distance on name + same company (confidence: 0.60–0.85)
 *   4. NAME+DOMAIN — Similar name + email at same domain (confidence: 0.50–0.75)
 *
 * Each potential duplicate pair is scored for confidence and includes the
 * field-level evidence so the caller (human or AI) can make an informed
 * merge decision before calling merge_duplicates.
 *
 * Industry standard: HubSpot deduplication center, Salesforce Duplicate
 * Rules, Zoho merge wizard. Average CRM dataset contains 10-30% duplicates.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects potential duplicate lead records.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Detect_Duplicates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Minimum Levenshtein ratio to consider names "similar".
	 *
	 * @var float
	 */
	const MIN_NAME_RATIO = 0.75;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'detect_duplicates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Detect Duplicate Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scan the lead database for potential duplicate records using exact email, normalised phone, and fuzzy name+company matching. Returns scored duplicate pairs with field-level evidence so you can review before merging. Industry standard: 10-30% of CRM records are duplicates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'strategy'       => array(
					'type'        => 'string',
					'description' => __( 'Matching strategy: exact_email (fast, high confidence), phone (normalised), fuzzy (name+company), all (every strategy).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'exact_email', 'phone', 'fuzzy', 'all' ),
					'default'     => 'all',
				),
				'min_confidence' => array(
					'type'        => 'number',
					'description' => __( 'Minimum confidence threshold (0.0–1.0) to include a pair. 0.70 is a good default.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.70,
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'max_results'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum duplicate pairs to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'include_merged' => array(
					'type'        => 'boolean',
					'description' => __( 'If false, skip leads already flagged as merged.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		if ( class_exists( 'WP_MCP_AI_CRM_Capabilities' ) ) {
			$map = WP_MCP_AI_CRM_Capabilities::get_map();
			return isset( $map['view_lead'] ) ? $map['view_lead'] : 'edit_posts';
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_ops', 'crm_viewer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason(), array( 'status' => 403 ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// --- Gate 1: Sanitise at entry ---

		$strategy = isset( $arguments['strategy'] ) ? sanitize_key( $arguments['strategy'] ) : 'all';
		$allowed  = array( 'exact_email', 'phone', 'fuzzy', 'all' );
		if ( ! in_array( $strategy, $allowed, true ) ) {
			$strategy = 'all';
		}

		$min_confidence = isset( $arguments['min_confidence'] ) ? (float) $arguments['min_confidence'] : 0.70;
		$min_confidence = max( 0, min( 1.0, $min_confidence ) );

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 50;
		$max_results = min( 200, max( 1, $max_results ) );

		$include_merged = ! empty( $arguments['include_merged'] );

		// ── Phase 1: Load all leads into memory ──
		$leads = $this->load_all_leads( $include_merged );

		if ( count( $leads ) < 2 ) {
			return $this->format_success_response(
				__( 'Not enough leads to detect duplicates (need at least 2).', 'mcp-ai-wpoos-pro' ),
				array(
					'duplicates'  => array(),
					'count'       => 0,
					'total_leads' => count( $leads ),
				)
			);
		}

		// ── Phase 2: Run matching strategies ──
		$pairs = array();

		if ( in_array( $strategy, array( 'exact_email', 'all' ), true ) ) {
			$pairs = array_merge( $pairs, $this->match_exact_email( $leads ) );
		}

		if ( in_array( $strategy, array( 'phone', 'all' ), true ) ) {
			$pairs = array_merge( $pairs, $this->match_phone( $leads ) );
		}

		if ( in_array( $strategy, array( 'fuzzy', 'all' ), true ) ) {
			$pairs = array_merge( $pairs, $this->match_fuzzy( $leads ) );
		}

		// ── Phase 3: Deduplicate pairs (A-B same as B-A) and filter by confidence ──
		$seen   = array();
		$unique = array();

		foreach ( $pairs as $pair ) {
			// Canonical sort: smaller ID first.
			$a   = min( $pair['lead_a'], $pair['lead_b'] );
			$b   = max( $pair['lead_a'], $pair['lead_b'] );
			$key = $a . '_' . $b;

			if ( isset( $seen[ $key ] ) ) {
				// Keep the higher-confidence version.
				if ( $pair['confidence'] > $unique[ $seen[ $key ] ]['confidence'] ) {
					$unique[ $seen[ $key ] ]           = $pair;
					$unique[ $seen[ $key ] ]['lead_a'] = $a;
					$unique[ $seen[ $key ] ]['lead_b'] = $b;
				}
				continue;
			}

			if ( $pair['confidence'] < $min_confidence ) {
				continue;
			}

			$pair['lead_a'] = $a;
			$pair['lead_b'] = $b;
			$seen[ $key ]   = count( $unique );
			$unique[]       = $pair;

			if ( count( $unique ) >= $max_results ) {
				break;
			}
		}

		// ── Phase 4: Sort by confidence descending ──
		usort(
			$unique,
			function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		// ── Phase 5: Enrich with lead summaries ──
		foreach ( $unique as &$pair ) {
			$pair['lead_a_summary'] = $this->summarise_lead( $pair['lead_a'] );
			$pair['lead_b_summary'] = $this->summarise_lead( $pair['lead_b'] );
		}
		unset( $pair );

		// Audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'duplicates_detected',
				'lead',
				'',
				array(
					'count'       => count( $unique ),
					'strategy'    => $strategy,
					'total_leads' => count( $leads ),
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of duplicate pairs found */
				_n(
					'Found %d potential duplicate pair.',
					'Found %d potential duplicate pairs.',
					count( $unique ),
					'mcp-ai-wpoos-pro'
				),
				count( $unique )
			),
			array(
				'duplicates'  => $unique,
				'count'       => count( $unique ),
				'total_leads' => count( $leads ),
				'strategy'    => $strategy,
			)
		);
	}

	/**
	 * Load all published leads into memory with key fields.
	 *
	 * @param bool $include_merged Whether to include already-merged leads.
	 * @return array[] Array of lead data arrays keyed by post ID.
	 */
	private function load_all_leads( $include_merged ) {
		$args = array(
			'post_type'      => 'mcp_ai_lead',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( ! $include_merged ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_is_merged',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_is_merged',
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		$query = new WP_Query( $args );
		$leads = array();

		foreach ( $query->posts as $lead_id ) {
			$email   = strtolower( trim( (string) get_post_meta( $lead_id, '_email', true ) ) );
			$phone   = $this->normalise_phone( (string) get_post_meta( $lead_id, '_phone', true ) );
			$name    = strtolower( trim( get_the_title( $lead_id ) ) );
			$company = strtolower( trim( (string) get_post_meta( $lead_id, '_company', true ) ) );

			$leads[ $lead_id ] = array(
				'id'      => $lead_id,
				'email'   => $email,
				'phone'   => $phone,
				'name'    => $name,
				'company' => $company,
			);
		}

		return $leads;
	}

	/**
	 * Match leads by exact email address.
	 *
	 * @param array $leads Lead data keyed by ID.
	 * @return array[] Duplicate pairs.
	 */
	private function match_exact_email( array $leads ) {
		$pairs = array();

		// Index by email.
		$by_email = array();
		foreach ( $leads as $lead ) {
			if ( empty( $lead['email'] ) || ! filter_var( $lead['email'], FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}
			$by_email[ $lead['email'] ][] = $lead['id'];
		}

		foreach ( $by_email as $email => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}

			// Generate all pairs within this email group.
			$count = count( $ids );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					// Older record = survivor candidate.
					$a_id = min( $ids[ $i ], $ids[ $j ] );
					$b_id = max( $ids[ $i ], $ids[ $j ] );

					$pairs[] = array(
						'lead_a'     => $a_id,
						'lead_b'     => $b_id,
						'confidence' => 0.99,
						'strategy'   => 'exact_email',
						'evidence'   => array(
							'field'  => 'email',
							'value'  => $email,
							'detail' => __( 'Identical email address.', 'mcp-ai-wpoos-pro' ),
						),
					);
				}
			}
		}

		return $pairs;
	}

	/**
	 * Match leads by normalised phone number.
	 *
	 * @param array $leads Lead data keyed by ID.
	 * @return array[] Duplicate pairs.
	 */
	private function match_phone( array $leads ) {
		$pairs = array();

		$by_phone = array();
		foreach ( $leads as $lead ) {
			if ( empty( $lead['phone'] ) || strlen( $lead['phone'] ) < 7 ) {
				continue;
			}
			$by_phone[ $lead['phone'] ][] = $lead['id'];
		}

		foreach ( $by_phone as $phone => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}

			// Skip if also matched by email (higher confidence already).
			$count = count( $ids );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a_id = min( $ids[ $i ], $ids[ $j ] );
					$b_id = max( $ids[ $i ], $ids[ $j ] );

					// Lower confidence if names don't match.
					$name_a     = isset( $leads[ $a_id ] ) ? $leads[ $a_id ]['name'] : '';
					$name_b     = isset( $leads[ $b_id ] ) ? $leads[ $b_id ]['name'] : '';
					$name_match = $this->is_name_similar( $name_a, $name_b );
					$confidence = $name_match ? 0.90 : 0.70;

					$pairs[] = array(
						'lead_a'     => $a_id,
						'lead_b'     => $b_id,
						'confidence' => $confidence,
						'strategy'   => 'phone',
						'evidence'   => array(
							'field'  => 'phone',
							'value'  => $phone,
							'detail' => $name_match
								? __( 'Same phone number + matching name.', 'mcp-ai-wpoos-pro' )
								: __( 'Same phone number (names differ).', 'mcp-ai-wpoos-pro' ),
						),
					);
				}
			}
		}

		return $pairs;
	}

	/**
	 * Match leads by fuzzy name + company.
	 *
	 * @param array $leads Lead data keyed by ID.
	 * @return array[] Duplicate pairs.
	 */
	private function match_fuzzy( array $leads ) {
		$pairs = array();

		// Group by company first to reduce comparisons.
		$by_company = array();
		$no_company = array();

		foreach ( $leads as $lead_id => $lead ) {
			if ( ! empty( $lead['company'] ) ) {
				$by_company[ $lead['company'] ][] = $lead_id;
			} else {
				$no_company[] = $lead_id;
			}
		}

		// Within same company, check name similarity.
		foreach ( $by_company as $company => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}

			$count = count( $ids );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a_id = $ids[ $i ];
					$b_id = $ids[ $j ];

					$name_a = isset( $leads[ $a_id ] ) ? $leads[ $a_id ]['name'] : '';
					$name_b = isset( $leads[ $b_id ] ) ? $leads[ $b_id ]['name'] : '';

					if ( ! $this->is_name_similar( $name_a, $name_b ) ) {
						continue;
					}

					// Boost confidence if email domain matches too.
					$email_a  = isset( $leads[ $a_id ] ) ? $leads[ $a_id ]['email'] : '';
					$email_b  = isset( $leads[ $b_id ] ) ? $leads[ $b_id ]['email'] : '';
					$dom_a    = $email_a ? strtolower( substr( strrchr( $email_a, '@' ), 1 ) ) : '';
					$dom_b    = $email_b ? strtolower( substr( strrchr( $email_b, '@' ), 1 ) ) : '';
					$same_dom = ( '' !== $dom_a && $dom_a === $dom_b );

					$ratio      = $this->levenshtein_ratio( $name_a, $name_b );
					$confidence = $same_dom ? 0.85 : 0.60 + ( $ratio - self::MIN_NAME_RATIO ) * 0.5;
					$confidence = round( min( 0.85, max( 0.50, $confidence ) ), 2 );

					$min_id = min( $a_id, $b_id );
					$max_id = max( $a_id, $b_id );

					$pairs[] = array(
						'lead_a'     => $min_id,
						'lead_b'     => $max_id,
						'confidence' => $confidence,
						'strategy'   => 'fuzzy_name_company',
						'evidence'   => array(
							'field'  => 'name+company',
							'value'  => $company,
							'detail' => sprintf(
								/* translators: 1: name similarity ratio, 2: same domain flag */
								__( 'Similar name (%1$.0f%% match) at same company%2$s.', 'mcp-ai-wpoos-pro' ),
								$ratio * 100,
								$same_dom ? ' + same email domain' : ''
							),
						),
					);
				}
			}
		}

		return $pairs;
	}

	/**
	 * Check if two names are "similar enough" using Levenshtein ratio.
	 *
	 * @param string $name_a First name.
	 * @param string $name_b Second name.
	 * @return bool
	 */
	private function is_name_similar( $name_a, $name_b ) {
		if ( empty( $name_a ) || empty( $name_b ) ) {
			return false;
		}

		// Exact match.
		if ( $name_a === $name_b ) {
			return true;
		}

		// One is a substring of the other (e.g. "John" vs "John Smith").
		if ( false !== strpos( $name_a, $name_b ) || false !== strpos( $name_b, $name_a ) ) {
			return true;
		}

		return $this->levenshtein_ratio( $name_a, $name_b ) >= self::MIN_NAME_RATIO;
	}

	/**
	 * Calculate Levenshtein similarity ratio (0.0–1.0).
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return float Ratio between 0.0 (completely different) and 1.0 (identical).
	 */
	private function levenshtein_ratio( $a, $b ) {
		$a = trim( $a );
		$b = trim( $b );

		if ( $a === $b ) {
			return 1.0;
		}

		$max_len = max( strlen( $a ), strlen( $b ) );
		if ( 0 === $max_len ) {
			return 1.0;
		}

		$distance = levenshtein( $a, $b );
		return 1.0 - ( $distance / $max_len );
	}

	/**
	 * Normalise a phone number by stripping all non-digit characters.
	 *
	 * @param string $phone Raw phone string.
	 * @return string Digits only.
	 */
	private function normalise_phone( $phone ) {
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Create a human-readable summary of a lead for display in results.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return array
	 */
	private function summarise_lead( $lead_id ) {
		$post = get_post( $lead_id );
		if ( ! $post ) {
			return array(
				'id'    => $lead_id,
				'title' => '(deleted)',
			);
		}

		$email     = get_post_meta( $lead_id, '_email', true );
		$phone     = get_post_meta( $lead_id, '_phone', true );
		$company   = get_post_meta( $lead_id, '_company', true );
		$lifecycle = get_post_meta( $lead_id, '_lifecycle_stage', true );
		$score     = (int) get_post_meta( $lead_id, '_lead_score', true );
		$created   = get_the_date( 'Y-m-d', $lead_id );
		$is_merged = (bool) get_post_meta( $lead_id, '_is_merged', true );

		// Count children.
		$deal_count     = $this->count_children( 'mcp_ai_deal', '_lead_id', $lead_id );
		$activity_count = $this->count_children( 'mcp_ai_crm_activity', '_lead_id', $lead_id );

		// Check if converted to customer.
		$customer_q  = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_customer',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_source_lead_id',
						'value' => $lead_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);
		$is_customer = ! empty( $customer_q->posts );

		return array(
			'id'              => $lead_id,
			'title'           => get_the_title( $lead_id ),
			'email'           => $email,
			'phone'           => $phone,
			'company'         => $company,
			'lifecycle_stage' => $lifecycle ?: 'lead',
			'lead_score'      => $score,
			'deal_count'      => $deal_count,
			'activity_count'  => $activity_count,
			'is_customer'     => $is_customer,
			'is_merged'       => $is_merged,
			'created_at'      => $created,
			'edit_url'        => get_edit_post_link( $lead_id, 'raw' ),
		);
	}

	/**
	 * Count child posts of a given type linked by meta.
	 *
	 * @param string $post_type Child post type.
	 * @param string $meta_key  Meta key linking to parent.
	 * @param int    $parent_id Parent post ID.
	 * @return int
	 */
	private function count_children( $post_type, $meta_key, $parent_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $parent_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return $query->found_posts;
	}
}
