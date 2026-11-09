<?php
/**
 * CRM Signals
 *
 * Signal catalogue, confirmation gate, observation store, own-site
 * collector and cohort query. Signals are proposed until an operator
 * confirms them; a prospect is never scored on an unconfirmed signal.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signal catalogue and cohorts.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Signals {

	/**
	 * Settings option.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mcp_ai_crm_signal_settings';

	/**
	 * Meta prefix.
	 *
	 * @var string
	 */
	const META_PREFIX = '_crm_signal_';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'   => false,
			'confirmed' => array(),
			'ttl_days'  => array(
				'running_google_ads' => 14,
				'running_meta_ads'   => 14,
				'hiring_role'        => 14,
				'open_roles'         => 14,
				'blog_stale'         => 30,
				'incumbent_agency'   => 90,
				'tech_stack'         => 90,
				'no_online_booking'  => 90,
				'no_schema_markup'   => 90,
				'no_website'         => 180,
				'contact_form_url'   => 90,
				'review_rating'      => 14,
				'review_count'       => 14,
			),
		);
	}

	/**
	 * Catalogue.
	 *
	 * @return array
	 */
	public static function catalogue() {
		$catalogue = array(
			'no_website'         => array(
				'label'       => __( 'No website', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'The business has a listing and a phone but no site.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'bool',
				'cost'        => 'free',
			),
			'no_online_booking'  => array(
				'label'       => __( 'No online booking', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Leads have to phone in, so after-hours demand is lost.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'bool',
				'cost'        => 'free',
			),
			'no_schema_markup'   => array(
				'label'       => __( 'No schema markup', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'The site does not publish structured data.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'bool',
				'cost'        => 'free',
			),
			'blog_stale'         => array(
				'label'       => __( 'Stale blog', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Content marketing started and was abandoned.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'num',
				'cost'        => 'free',
			),
			'running_google_ads' => array(
				'label'       => __( 'Running Google Ads', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'They are spending on acquisition today.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'bool',
				'cost'        => 'free',
			),
			'running_meta_ads'   => array(
				'label'       => __( 'Running Meta ads', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'A Meta pixel or conversion script is present.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'bool',
				'cost'        => 'free',
			),
			'incumbent_agency'   => array(
				'label'       => __( 'Incumbent agency', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Who currently has the account, credited in the footer.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'cost'        => 'free',
			),
			'hiring_role'        => array(
				'label'       => __( 'Hiring role', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'A role they are hiring for that indicates a buying trigger.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'cost'        => 'free',
			),
			'open_roles'         => array(
				'label'       => __( 'Open roles', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'How many roles are open.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'num',
				'cost'        => 'free',
			),
			'tech_stack'         => array(
				'label'       => __( 'Tech stack', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Platform and marketing tools detected on the site.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'cost'        => 'free',
			),
			'contact_form_url'   => array(
				'label'       => __( 'Contact form', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'The page where a contact form lives.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'url',
				'cost'        => 'free',
			),
			'review_rating'      => array(
				'label'       => __( 'Review rating', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Average review score, when a review provider is configured.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'num',
				'cost'        => 'provider',
			),
			'review_count'       => array(
				'label'       => __( 'Review count', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Number of reviews, when a review provider is configured.', 'mcp-ai-wpoos-pro' ),
				'type'        => 'num',
				'cost'        => 'provider',
			),
		);

		/**
		 * Filter the signal catalogue.
		 *
		 * @param array $catalogue Catalogue.
		 */
		return apply_filters( 'wp_mcp_ai_crm_signal_catalogue', $catalogue );
	}

	/**
	 * Resolved settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args( $stored, self::defaults() );
		if ( ! is_array( $settings['ttl_days'] ) ) {
			$settings['ttl_days'] = self::defaults()['ttl_days'];
		}

		/**
		 * Filter signal settings.
		 *
		 * @param array $settings Settings.
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_signal_settings', $settings );

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Flush cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Whether the signal subsystem is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();
		return (bool) apply_filters( 'wp_mcp_ai_crm_signals_enabled', ! empty( $settings['enabled'] ), $settings );
	}

	/**
	 * Whether a signal is confirmed.
	 *
	 * @param string $key Signal key.
	 * @return bool
	 */
	public static function is_confirmed( $key ) {
		$settings = self::get_settings();
		return in_array( sanitize_key( $key ), (array) $settings['confirmed'], true );
	}

	/**
	 * Confirm signal keys.
	 *
	 * @param string[] $keys Keys.
	 * @return array Confirmed keys.
	 */
	public static function confirm( array $keys ) {
		$catalogue = self::catalogue();
		$stored    = get_option( self::OPTION, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$current   = isset( $stored['confirmed'] ) ? (array) $stored['confirmed'] : array();

		foreach ( $keys as $key ) {
			$key = sanitize_key( $key );
			if ( isset( $catalogue[ $key ] ) && ! in_array( $key, $current, true ) ) {
				$current[] = $key;
			}
		}

		$stored['confirmed'] = array_values( array_unique( $current ) );
		update_option( self::OPTION, $stored, false );
		self::flush_cache();

		/**
		 * Fires after signal confirmation changes.
		 *
		 * @param array $confirmed Confirmed keys.
		 */
		do_action( 'wp_mcp_ai_crm_signals_confirmed', $stored['confirmed'] );

		return $stored['confirmed'];
	}

	/**
	 * Proposed (unconfirmed) signals.
	 *
	 * @return array
	 */
	public static function proposed() {
		$out = array();
		foreach ( self::catalogue() as $key => $meta ) {
			if ( ! self::is_confirmed( $key ) ) {
				$out[ $key ] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Record an observation.
	 *
	 * @param int    $lead_id  Lead post ID.
	 * @param string $key      Signal key.
	 * @param array  $args     { value_num, value_text, evidence, source, confidence }.
	 * @return bool|WP_Error
	 */
	public static function observe( $lead_id, $key, array $args = array() ) {
		$lead_id   = absint( $lead_id );
		$key       = sanitize_key( $key );
		$catalogue = self::catalogue();
		if ( ! $lead_id ) {
			return new WP_Error( 'invalid_lead', __( 'Invalid lead.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! isset( $catalogue[ $key ] ) ) {
			return new WP_Error( 'unknown_signal', __( 'Unknown signal.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_confirmed( $key ) ) {
			return new WP_Error( 'unconfirmed_signal', __( 'Signal is not confirmed; collect nothing.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings   = self::get_settings();
		$ttl        = isset( $settings['ttl_days'][ $key ] ) ? absint( $settings['ttl_days'][ $key ] ) : 30;
		$observed   = isset( $args['observed_at'] ) ? (string) $args['observed_at'] : gmdate( 'c' );
		$expires    = gmdate( 'c', strtotime( $observed ) + $ttl * DAY_IN_SECONDS );
		$value_num  = isset( $args['value_num'] ) && is_numeric( $args['value_num'] ) ? (float) $args['value_num'] : null;
		$value_text = isset( $args['value_text'] ) ? (string) $args['value_text'] : '';
		$evidence   = isset( $args['evidence'] ) ? $args['evidence'] : array();
		$source     = isset( $args['source'] ) ? esc_url_raw( (string) $args['source'] ) : '';
		$confidence = isset( $args['confidence'] ) ? absint( $args['confidence'] ) : 100;

		// A boolean false is the absence of a signal, not an observation of
		// it. Clearing keeps cohort semantics (presence = true).
		if ( 'bool' === $catalogue[ $key ]['type'] && null !== $value_num && 0.0 === (float) $value_num ) {
			if ( WP_MCP_AI_CRM_Signals_DB::use_table() ) {
				WP_MCP_AI_CRM_Signals_DB::delete_observation( $lead_id, $key );
			}
			delete_post_meta( $lead_id, self::META_PREFIX . $key );
			return true;
		}

		if ( WP_MCP_AI_CRM_Signals_DB::use_table() ) {
			WP_MCP_AI_CRM_Signals_DB::insert(
				array(
					'lead_id'     => $lead_id,
					'signal_key'  => $key,
					'value_num'   => $value_num,
					'value_text'  => $value_text,
					'evidence'    => $evidence,
					'source'      => $source,
					'confidence'  => $confidence,
					'observed_at' => $observed,
					'expires_at'  => $expires,
				)
			);
		}

		// Summary meta powers the fallback path and fast single-lead reads.
		update_post_meta(
			$lead_id,
			self::META_PREFIX . $key,
			array(
				'value_num'   => $value_num,
				'value_text'  => $value_text,
				'source'      => $source,
				'confidence'  => $confidence,
				'observed_at' => $observed,
				'expires_at'  => $expires,
			)
		);

		/**
		 * Fires after a signal observation is stored.
		 *
		 * @param int    $lead_id Lead post ID.
		 * @param string $key     Signal key.
		 * @param array  $args    Observation args.
		 */
		do_action( 'wp_mcp_ai_crm_signal_observed', $lead_id, $key, $args );

		return true;
	}

	/**
	 * Latest observation per signal for a lead.
	 *
	 * @param int        $lead_id Lead post ID.
	 * @param array|null $keys    Optional keys.
	 * @return array
	 */
	public static function get_observations( $lead_id, $keys = null ) {
		$lead_id = absint( $lead_id );
		$keys    = is_array( $keys ) ? array_map( 'sanitize_key', $keys ) : array_keys( self::catalogue() );
		$out     = array();

		if ( WP_MCP_AI_CRM_Signals_DB::use_table() ) {
			$rows = WP_MCP_AI_CRM_Signals_DB::query(
				array(
					'lead_id'     => $lead_id,
					'signal_keys' => $keys,
					'limit'       => 500,
				)
			);
			foreach ( $rows as $row ) {
				if ( ! isset( $out[ $row['signal_key'] ] ) ) {
					$out[ $row['signal_key'] ] = array(
						'signal_key'  => $row['signal_key'],
						'value_num'   => $row['value_num'],
						'value_text'  => $row['value_text'],
						'evidence'    => $row['evidence'],
						'source'      => $row['source'],
						'confidence'  => (int) $row['confidence'],
						'observed_at' => $row['observed_at'],
						'expires_at'  => $row['expires_at'],
					);
				}
			}
			return $out;
		}

		foreach ( $keys as $key ) {
			$meta = get_post_meta( $lead_id, self::META_PREFIX . $key, true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$expires = isset( $meta['expires_at'] ) ? strtotime( $meta['expires_at'] ) : 0;
			if ( $expires && $expires < time() ) {
				continue;
			}
			$out[ $key ] = array_merge(
				array(
					'signal_key' => $key,
					'evidence'   => array(),
				),
				$meta
			);
		}
		return $out;
	}

	/**
	 * Coverage by signal.
	 *
	 * @return array
	 */
	public static function coverage() {
		if ( WP_MCP_AI_CRM_Signals_DB::use_table() ) {
			return WP_MCP_AI_CRM_Signals_DB::coverage();
		}

		$out = array();
		foreach ( array_keys( self::catalogue() ) as $key ) {
			$count       = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_lead',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => array(
						array(
							'key'     => self::META_PREFIX . $key,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			$out[ $key ] = array(
				'observations' => (int) $count->found_posts,
				'leads'        => (int) $count->found_posts,
			);
		}
		return $out;
	}

	/**
	 * Evidence card for one lead.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return array
	 */
	public static function evidence_card( $lead_id ) {
		$observations = self::get_observations( $lead_id );
		$catalogue    = self::catalogue();
		$signals      = array();
		$lines        = array();

		foreach ( $observations as $key => $row ) {
			if ( ! isset( $catalogue[ $key ] ) ) {
				continue;
			}
			$signals[] = array(
				'key'         => $key,
				'label'       => $catalogue[ $key ]['label'],
				'value_num'   => $row['value_num'],
				'value_text'  => $row['value_text'],
				'evidence'    => $row['evidence'],
				'source'      => $row['source'],
				'confidence'  => $row['confidence'],
				'observed_at' => $row['observed_at'],
			);

			$value   = '' !== (string) $row['value_text'] ? $row['value_text'] : (string) $row['value_num'];
			$lines[] = $catalogue[ $key ]['label'] . ( '' !== $value ? ': ' . $value : '' );
		}

		return array(
			'lead_id' => absint( $lead_id ),
			'signals' => $signals,
			'summary' => implode( '; ', $lines ),
		);
	}

	/**
	 * Query a cohort.
	 *
	 * @param array $args {
	 *     Cohort criteria.
	 *
	 *     @type string[] $must      Signal keys that must be present.
	 *     @type string[] $must_not  Signal keys that must be absent.
	 *     @type array    $ranges    key => [min, max].
	 *     @type int      $limit     Max leads (default 50).
	 *     @type bool     $count_only Return count only.
	 * }
	 * @return array
	 */
	public static function cohort( array $args = array() ) {
		$must       = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['must'] ?? array() ) ) ) );
		$must_not   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['must_not'] ?? array() ) ) ) );
		$ranges     = is_array( $args['ranges'] ?? null ) ? $args['ranges'] : array();
		$limit      = max( 1, min( 500, absint( $args['limit'] ?? 50 ) ) );
		$count_only = ! empty( $args['count_only'] );

		if ( WP_MCP_AI_CRM_Signals_DB::use_table() ) {
			$result = self::cohort_table( $must, $must_not, $ranges, $limit );
		} else {
			$result = self::cohort_meta( $must, $must_not, $ranges, $limit );
		}

		if ( $count_only ) {
			return array( 'count' => (int) $result['count'] );
		}

		$sample = array();
		foreach ( array_slice( $result['ids'], 0, 10 ) as $lead_id ) {
			$card     = self::evidence_card( $lead_id );
			$sample[] = array(
				'lead_id' => $lead_id,
				'title'   => get_the_title( $lead_id ),
				'email'   => get_post_meta( $lead_id, 'email', true ),
				'signals' => wp_list_pluck( $card['signals'], 'key' ),
				'summary' => $card['summary'],
			);
		}

		return array(
			'count'  => (int) $result['count'],
			'ids'    => $result['ids'],
			'sample' => $sample,
			'query'  => array(
				'must'     => $must,
				'must_not' => $must_not,
				'ranges'   => $ranges,
			),
		);
	}

	/**
	 * Table-backed cohort query.
	 *
	 * @param array $must     Must keys.
	 * @param array $must_not Must-not keys.
	 * @param array $ranges   Ranges.
	 * @param int   $limit    Limit.
	 * @return array{ids:int[],count:int}
	 */
	private static function cohort_table( array $must, array $must_not, array $ranges, $limit ) {
		global $wpdb;
		$table  = WP_MCP_AI_CRM_Signals_DB::table_name();
		$where  = array( "p.post_type = 'mcp_ai_lead'", "p.post_status = 'publish'" );
		$params = array();

		$fresh   = '(s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP())';
		$present = "(COALESCE(s.value_num, 0) <> 0 OR (s.value_text IS NOT NULL AND s.value_text <> ''))";

		foreach ( $must as $key ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$table} s WHERE s.lead_id = p.ID AND s.signal_key = %s AND {$fresh} AND {$present})";
			$params[] = $key;
		}
		foreach ( $must_not as $key ) {
			$where[]  = "NOT EXISTS (SELECT 1 FROM {$table} s WHERE s.lead_id = p.ID AND s.signal_key = %s AND {$fresh})";
			$params[] = $key;
		}
		foreach ( $ranges as $key => $range ) {
			$key = sanitize_key( $key );
			$min = isset( $range['min'] ) ? (float) $range['min'] : null;
			$max = isset( $range['max'] ) ? (float) $range['max'] : null;
			if ( null !== $min && null !== $max ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$table} s WHERE s.lead_id = p.ID AND s.signal_key = %s AND {$fresh} AND s.value_num BETWEEN %f AND %f)";
				$params[] = $key;
				$params[] = $min;
				$params[] = $max;
			} elseif ( null !== $min ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$table} s WHERE s.lead_id = p.ID AND s.signal_key = %s AND {$fresh} AND s.value_num >= %f)";
				$params[] = $key;
				$params[] = $min;
			} elseif ( null !== $max ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$table} s WHERE s.lead_id = p.ID AND s.signal_key = %s AND {$fresh} AND s.value_num <= %f)";
				$params[] = $key;
				$params[] = $max;
			}
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}";
		$count     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		$ids_sql   = "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where_sql} ORDER BY p.ID DESC LIMIT %d";
		$id_params = array_merge( $params, array( $limit ) );
		$ids       = $wpdb->get_col( $wpdb->prepare( $ids_sql, $id_params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return array(
			'count' => $count,
			'ids'   => array_map( 'absint', (array) $ids ),
		);
	}

	/**
	 * Meta-backed cohort query.
	 *
	 * @param array $must     Must keys.
	 * @param array $must_not Must-not keys.
	 * @param array $ranges   Ranges.
	 * @param int   $limit    Limit.
	 * @return array{ids:int[],count:int}
	 */
	private static function cohort_meta( array $must, array $must_not, array $ranges, $limit ) {
		$meta_query = array( 'relation' => 'AND' );
		foreach ( $must as $key ) {
			$meta_query[] = array(
				'key'     => self::META_PREFIX . $key,
				'compare' => 'EXISTS',
			);
		}
		foreach ( $must_not as $key ) {
			$meta_query[] = array(
				'key'     => self::META_PREFIX . $key,
				'compare' => 'NOT EXISTS',
			);
		}
		foreach ( $ranges as $key => $range ) {
			$key = sanitize_key( $key );
			$min = isset( $range['min'] ) ? (float) $range['min'] : null;
			$max = isset( $range['max'] ) ? (float) $range['max'] : null;

			// A three-argument BETWEEN with PHP_INT_MAX stringifies as
			// 9.22E+18, which MySQL casts to 9 and silently matches nothing.
			// Use a half-open comparison instead of an artificial ceiling.
			if ( null !== $min && null !== $max ) {
				$meta_query[] = array(
					'key'     => self::META_PREFIX . $key,
					'type'    => 'NUMERIC',
					'compare' => 'BETWEEN',
					'value'   => array( $min, $max ),
				);
			} elseif ( null !== $min ) {
				$meta_query[] = array(
					'key'     => self::META_PREFIX . $key,
					'type'    => 'NUMERIC',
					'compare' => '>=',
					'value'   => $min,
				);
			} elseif ( null !== $max ) {
				$meta_query[] = array(
					'key'     => self::META_PREFIX . $key,
					'type'    => 'NUMERIC',
					'compare' => '<=',
					'value'   => $max,
				);
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $meta_query,
			)
		);

		return array(
			'count' => (int) $query->found_posts,
			'ids'   => array_map( 'absint', $query->posts ),
		);
	}

	/**
	 * Collect signals from a lead's own website (free, no model call).
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $args    { url: string, force: bool }.
	 * @return array Collected keys.
	 */
	public static function collect_own_site( $lead_id, array $args = array() ) {
		$lead_id = absint( $lead_id );
		if ( ! self::is_enabled() || ! $lead_id ) {
			return array();
		}

		$url = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
		if ( ! $url ) {
			foreach ( array( 'website', 'company_website', '_crm_website' ) as $key ) {
				$candidate = get_post_meta( $lead_id, $key, true );
				if ( $candidate && preg_match( '#^https?://#i', $candidate ) ) {
					$url = $candidate;
					break;
				}
			}
		}

		if ( ! $url ) {
			if ( self::is_confirmed( 'no_website' ) ) {
				self::observe(
					$lead_id,
					'no_website',
					array(
						'value_num' => 1,
						'source'    => 'crm',
						'evidence'  => array( 'reason' => 'no website meta' ),
					)
				);
				return array( 'no_website' );
			}
			return array();
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return array();
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 12,
				'redirection'        => 3,
				'user-agent'         => 'Mozilla/5.0 (compatible; NV-oOS-CRM-Signals/1.0)',
				'reject_unsafe_urls' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}
		$body      = substr( $body, 0, 500000 );
		$collected = array();

		$add = function ( $key, $num, $text, $evidence, $confidence = 90 ) use ( $lead_id, $url, &$collected ) {
			if ( ! self::is_confirmed( $key ) ) {
				return;
			}
			self::observe(
				$lead_id,
				$key,
				array(
					'value_num'  => $num,
					'value_text' => $text,
					'source'     => $url,
					'confidence' => $confidence,
					'evidence'   => $evidence,
				)
			);
			$collected[] = $key;
		};

		// Booking.
		$booking = preg_match( '#(calendly\.com|cal\.com|/book(ing)?\b|/schedule\b|acuityscheduling|housecallpro|servicetitan)#i', $body );
		$add( 'no_online_booking', $booking ? 0 : 1, '', array( 'matched' => $booking ? 'booking link' : 'no booking link' ) );

		// Schema.
		$schema = preg_match( '#application/ld\+json|itemtype\s*=\s*["\']https?://schema\.org#i', $body );
		$add( 'no_schema_markup', $schema ? 0 : 1, '', array( 'matched' => $schema ? 'schema' : 'none' ) );

		// Ads.
		$gads = preg_match( '#googleadservices|googletagmanager\.com/gtag/js\?id=AW-|AW-\d{6,}#i', $body );
		$add( 'running_google_ads', $gads ? 1 : 0, '', array( 'matched' => $gads ? 'AW tag' : 'none' ) );
		$mads = preg_match( '#connect\.facebook\.net|fbq\s*\(|facebook\.com/tr#i', $body );
		$add( 'running_meta_ads', $mads ? 1 : 0, '', array( 'matched' => $mads ? 'meta pixel' : 'none' ) );

		// Tech stack.
		$stack      = array();
		$signatures = array(
			'WordPress'   => '#wp-content|wp-json#i',
			'Shopify'     => '#cdn\.shopify\.com|shopify\.theme#i',
			'Wix'         => '#wix\.com|wixstatic#i',
			'Squarespace' => '#squarespace\.com|static1\.squarespace#i',
			'Webflow'     => '#webflow\.com|assets\.website-files#i',
			'HubSpot'     => '#hs-scripts\.com|hubspot#i',
			'Drupal'      => '#drupal\.js|sites/default/files#i',
			'Joomla'      => '#/media/jui/|joomla#i',
		);
		foreach ( $signatures as $name => $pattern ) {
			if ( preg_match( $pattern, $body ) ) {
				$stack[] = $name;
			}
		}
		if ( $stack ) {
			$add( 'tech_stack', null, implode( ', ', $stack ), array( 'matched' => $stack ) );
		}

		// Contact form.
		if ( preg_match( '#<form\b[^>]*(?:action|id|class)\s*=\s*["\'][^"\']*(?:contact|quote|enquir|inquir)[^"\']*["\']#i', $body, $m ) ) {
			$add( 'contact_form_url', null, $url, array( 'matched' => 'contact form' ) );
		} elseif ( preg_match( '#href\s*=\s*["\']([^"\']*(?:contact|quote|enquir|inquir)[^"\']*)["\']#i', $body, $m ) ) {
			$add( 'contact_form_url', null, $m[1], array( 'matched' => 'contact link' ) );
		}

		// Incumbent agency credit.
		if ( preg_match( '#(powered by|website by|site by|built by)\s*<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $body, $m ) ) {
			$agency = trim( wp_strip_all_tags( $m[3] ) );
			if ( '' !== $agency ) {
				$add(
					'incumbent_agency',
					null,
					$agency,
					array(
						'credit' => $m[1],
						'href'   => $m[2],
					),
					75
				);
			}
		}

		// Careers / hiring.
		if ( preg_match( '#href\s*=\s*["\']([^"\']*(?:careers|jobs|join-us|work-with-us)[^"\']*)["\']#i', $body, $m ) ) {
			$careers_url = $m[1];
			if ( 0 === strpos( $careers_url, '/' ) ) {
				$careers_url = rtrim( $url, '/' ) . $careers_url;
			}
			$roles = self::count_roles( $careers_url );
			if ( $roles > 0 ) {
				$add( 'open_roles', $roles, '', array( 'careers_url' => $careers_url ) );
			}
		}

		// Blog staleness.
		if ( preg_match( '#href\s*=\s*["\']([^"\']*(?:/blog|/news|/insights)[^"\']*)["\']#i', $body, $m ) ) {
			$blog_url = $m[1];
			if ( 0 === strpos( $blog_url, '/' ) ) {
				$blog_url = rtrim( $url, '/' ) . $blog_url;
			}
			$days = self::blog_staleness_days( $blog_url );
			if ( null !== $days && $days >= 90 ) {
				$add(
					'blog_stale',
					$days,
					'',
					array(
						'blog_url' => $blog_url,
						'days'     => $days,
					)
				);
			}
		}

		return array_values( array_unique( $collected ) );
	}

	/**
	 * Count role-like headings on a careers page.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function count_roles( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return 0;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 10,
				'reject_unsafe_urls' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		$body  = (string) wp_remote_retrieve_body( $response );
		$roles = preg_match_all( '#\b(engineer|developer|designer|manager|director|sales|marketing|dispatcher|receptionist|technician|supervisor|coordinator|accountant|specialist)\b#i', wp_strip_all_tags( $body ) );
		return min( 50, (int) $roles );
	}

	/**
	 * Days since the latest dated post on a blog index.
	 *
	 * @param string $url URL.
	 * @return int|null
	 */
	private static function blog_staleness_days( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return null;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 10,
				'reject_unsafe_urls' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( ! preg_match_all( '#datetime\s*=\s*["\']([0-9T:\-+Z\.]+)["\']#i', $body, $m ) ) {
			return null;
		}
		$latest = 0;
		foreach ( $m[1] as $date ) {
			$ts = strtotime( $date );
			if ( $ts && $ts > $latest ) {
				$latest = $ts;
			}
		}
		if ( ! $latest ) {
			return null;
		}
		return (int) floor( ( time() - $latest ) / DAY_IN_SECONDS );
	}
}
