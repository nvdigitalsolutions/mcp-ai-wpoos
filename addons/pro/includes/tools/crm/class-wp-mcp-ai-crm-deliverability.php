<?php
/**
 * CRM Deliverability
 *
 * Owns the deliverability half of outreach: address status lifecycle,
 * pattern inference, verification, domain authentication checks, warmup
 * ramp, bounce accounting and the composite sending-readiness score.
 *
 * The guiding rule is the same one the pre-send gate uses: only an address
 * we can defend as deliverable is sent to, and "a guess is never a win".
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
 * Deliverability engine.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Deliverability {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mcp_ai_crm_deliverability_settings';

	/**
	 * Rolling stats option key.
	 *
	 * @var string
	 */
	const STATS_OPTION = 'wp_mcp_ai_crm_deliverability_stats';

	/**
	 * Address statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'unknown', 'guess', 'risky', 'verified', 'invalid' );

	/**
	 * Statuses that may be sent to.
	 *
	 * @var string[]
	 */
	const DELIVERABLE = array( 'verified', 'risky' );

	/**
	 * Meta keys owned by this class.
	 *
	 * @var string[]
	 */
	const META_KEYS = array(
		'status'     => '_crm_email_status',
		'confidence' => '_crm_email_confidence',
		'source'     => '_crm_email_source',
		'checked_at' => '_crm_email_checked_at',
		'pattern'    => '_crm_email_pattern',
	);

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Enforcement is opt-in: the status is readable before it blocks.
			'enabled'                 => false,
			'sending_domain'          => '',
			'dkim_selector'           => 'default',
			'warmup_start_date'       => '',
			'warmup_paused'           => false,
			'warmup_min_days'         => 14,
			'warmup_start_per_day'    => 10,
			'warmup_daily_increase'   => 5,
			'max_per_mailbox_per_day' => 50,
			'bounce_pause_threshold'  => 0.03,
			'complaint_threshold'     => 0.001,
			'verify_ttl_days'         => 30,
			'mailboxes'               => array(),
			'require_verified'        => false,
		);
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

		/**
		 * Filter resolved deliverability settings.
		 *
		 * @param array $settings Settings.
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_deliverability_settings', $settings );

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Flush the settings cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Whether deliverability enforcement is on.
	 *
	 * @return bool
	 */
	public static function is_enforced() {
		$settings = self::get_settings();

		/**
		 * Filter whether readiness/deliverability blocks auto-send.
		 *
		 * @param bool  $enabled  Enforced.
		 * @param array $settings Settings.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_crm_deliverability_enabled', ! empty( $settings['enabled'] ), $settings );
	}

	/*
	---------------------------------------------------------------------
	* Address status
	* ------------------------------------------------------------------
	*/

	/**
	 * Read a lead's address status.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return array{status:string,confidence:int,source:string,checked_at:string,pattern:string}
	 */
	public static function get_status( $lead_id ) {
		$lead_id = absint( $lead_id );
		$status  = $lead_id ? (string) get_post_meta( $lead_id, self::META_KEYS['status'], true ) : '';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'unknown';
		}

		return array(
			'status'     => $status,
			'confidence' => $lead_id ? absint( get_post_meta( $lead_id, self::META_KEYS['confidence'], true ) ) : 0,
			'source'     => $lead_id ? (string) get_post_meta( $lead_id, self::META_KEYS['source'], true ) : '',
			'checked_at' => $lead_id ? (string) get_post_meta( $lead_id, self::META_KEYS['checked_at'], true ) : '',
			'pattern'    => $lead_id ? (string) get_post_meta( $lead_id, self::META_KEYS['pattern'], true ) : '',
		);
	}

	/**
	 * Persist a lead's address status.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $status  One of STATUSES.
	 * @param array  $meta    Optional confidence/source/pattern.
	 * @return bool
	 */
	public static function set_status( $lead_id, $status, array $meta = array() ) {
		$lead_id = absint( $lead_id );
		$status  = sanitize_key( $status );
		if ( ! $lead_id || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		update_post_meta( $lead_id, self::META_KEYS['status'], $status );
		update_post_meta( $lead_id, self::META_KEYS['checked_at'], gmdate( 'c' ) );

		if ( isset( $meta['confidence'] ) ) {
			update_post_meta( $lead_id, self::META_KEYS['confidence'], max( 0, min( 100, absint( $meta['confidence'] ) ) ) );
		}
		if ( isset( $meta['source'] ) ) {
			update_post_meta( $lead_id, self::META_KEYS['source'], sanitize_key( $meta['source'] ) );
		}
		if ( isset( $meta['pattern'] ) ) {
			update_post_meta( $lead_id, self::META_KEYS['pattern'], sanitize_text_field( $meta['pattern'] ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'email_status_updated',
				'lead',
				$lead_id,
				array(
					'status' => $status,
					'source' => isset( $meta['source'] ) ? $meta['source'] : '',
				)
			);
		}

		return true;
	}

	/**
	 * Whether an address may be sent to.
	 *
	 * @param int  $lead_id     Lead post ID.
	 * @param bool $allow_risky Whether catch-all/risky addresses are allowed.
	 * @return bool
	 */
	public static function is_deliverable( $lead_id, $allow_risky = false ) {
		$status  = self::get_status( $lead_id );
		$state   = $status['status'];
		$allowed = $allow_risky ? self::DELIVERABLE : array( 'verified' );
		return in_array( $state, $allowed, true );
	}

	/**
	 * Whether a verification result is stale.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return bool
	 */
	public static function is_stale( $lead_id ) {
		$status = self::get_status( $lead_id );
		if ( empty( $status['checked_at'] ) ) {
			return true;
		}
		$ttl = max( 1, absint( self::get_settings()['verify_ttl_days'] ) );
		$ts  = strtotime( $status['checked_at'] );
		return ! $ts || ( time() - $ts ) > ( $ttl * DAY_IN_SECONDS );
	}

	/*
	---------------------------------------------------------------------
	* Pattern inference
	* ------------------------------------------------------------------
	*/

	/**
	 * Infer an address pattern from published addresses.
	 *
	 * Each published entry should be:
	 *   array( 'email' => 'jane.smith@acme.test', 'first' => 'Jane', 'last' => 'Smith' )
	 *
	 * A dotless local part with no named owner is ambiguous and teaches
	 * nothing. Two different patterns with equal evidence lower confidence.
	 *
	 * @param string $domain    Domain.
	 * @param array  $published Published address records.
	 * @return array{pattern:string,confidence:int,evidence:array}
	 */
	public static function infer_pattern( $domain, array $published ) {
		$domain     = strtolower( trim( (string) $domain ) );
		$candidates = array();

		foreach ( $published as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['email'] ) ) {
				continue;
			}
			$email = strtolower( trim( (string) $entry['email'] ) );
			if ( ! is_email( $email ) ) {
				continue;
			}
			list( $local, $host ) = explode( '@', $email, 2 );
			if ( $domain && $host !== $domain ) {
				continue;
			}

			$first = isset( $entry['first'] ) ? self::normalize_name( $entry['first'] ) : '';
			$last  = isset( $entry['last'] ) ? self::normalize_name( $entry['last'] ) : '';

			// No named owner: only a dotted local part can teach a pattern.
			if ( '' === $first || '' === $last ) {
				if ( false === strpos( $local, '.' ) ) {
					continue;
				}
				$parts = explode( '.', $local );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				$candidates[] = array(
					'pattern'    => '{first}.{last}',
					'confidence' => 45,
					'local'      => $local,
				);
				continue;
			}

			$f = substr( $first, 0, 1 );
			$l = $last;

			$map = array(
				$first . '.' . $l => '{first}.{last}',
				$first . $l       => '{first}{last}',
				$f . $l           => '{f}{last}',
				$f . '.' . $l     => '{f}.{last}',
				$first            => '{first}',
				$l                => '{last}',
				$l . '.' . $first => '{last}.{first}',
				$l . $first       => '{last}{first}',
				$l . '.' . $f     => '{last}.{f}',
				$l . $f           => '{last}{f}',
			);

			if ( isset( $map[ $local ] ) ) {
				$candidates[] = array(
					'pattern'    => $map[ $local ],
					'confidence' => 90,
					'local'      => $local,
				);
			}

			// A dotless local part that could belong to two named people is
			// format evidence only, never a verification candidate.
			if ( false === strpos( $local, '.' ) && strlen( $local ) <= 2 ) {
				$candidates[] = array(
					'pattern'    => '{f}{last}',
					'confidence' => 25,
					'local'      => $local,
				);
			}
		}

		if ( empty( $candidates ) ) {
			return array(
				'pattern'    => '',
				'confidence' => 0,
				'evidence'   => array(),
			);
		}

		$by_pattern = array();
		foreach ( $candidates as $candidate ) {
			$pattern = $candidate['pattern'];
			if ( ! isset( $by_pattern[ $pattern ] ) ) {
				$by_pattern[ $pattern ] = array(
					'pattern'    => $pattern,
					'confidence' => 0,
					'count'      => 0,
					'local'      => $candidate['local'],
				);
			}
			$by_pattern[ $pattern ]['confidence'] = max( $by_pattern[ $pattern ]['confidence'], $candidate['confidence'] );
			++$by_pattern[ $pattern ]['count'];
		}

		usort(
			$by_pattern,
			static function ( $a, $b ) {
				if ( $a['count'] === $b['count'] ) {
					return $b['confidence'] <=> $a['confidence'];
				}
				return $b['count'] <=> $a['count'];
			}
		);

		$best = $by_pattern[0];

		// Conflicting patterns reduce confidence: the domain is not
		// consistent enough to treat the pattern as evidence.
		if ( count( $by_pattern ) > 1 ) {
			$best['confidence'] = max( 20, $best['confidence'] - 20 );
		}

		return array(
			'pattern'    => $best['pattern'],
			'confidence' => (int) $best['confidence'],
			'evidence'   => array(
				'count' => (int) $best['count'],
				'local' => $best['local'],
			),
		);
	}

	/**
	 * Normalise a person name for matching.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function normalize_name( $name ) {
		$name = strtolower( remove_accents( (string) $name ) );
		$name = preg_replace( '/[^a-z]/', '', $name );
		return (string) $name;
	}

	/**
	 * Build an address from a pattern.
	 *
	 * @param string $pattern Pattern.
	 * @param string $first   First name.
	 * @param string $last    Last name.
	 * @param string $domain  Domain.
	 * @return string
	 */
	public static function build_address( $pattern, $first, $last, $domain ) {
		$first  = self::normalize_name( $first );
		$last   = self::normalize_name( $last );
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $first || '' === $last || '' === $domain ) {
			return '';
		}
		$f   = substr( $first, 0, 1 );
		$map = array(
			'{first}.{last}' => $first . '.' . $last,
			'{first}{last}'  => $first . $last,
			'{f}{last}'      => $f . $last,
			'{f}.{last}'     => $f . '.' . $last,
			'{first}'        => $first,
			'{last}'         => $last,
			'{last}.{first}' => $last . '.' . $first,
			'{last}{first}'  => $last . $first,
			'{last}.{f}'     => $last . '.' . $f,
			'{last}{f}'      => $last . $f,
		);
		if ( ! isset( $map[ $pattern ] ) ) {
			return '';
		}
		$candidate = $map[ $pattern ] . '@' . $domain;
		return is_email( $candidate ) ? $candidate : '';
	}

	/*
	---------------------------------------------------------------------
	* Verification
	* ------------------------------------------------------------------
	*/

	/**
	 * Verify one lead address.
	 *
	 * Never brute-forces names. It uses the address the site publishes, or
	 * a single candidate derived from a learned pattern, and records the
	 * result honestly.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $args    {
	 *     Verification options.
	 *
	 *     @type bool  $force     Re-check even when fresh.
	 *     @type array $published Published address records for pattern inference.
	 * }
	 * @return array|WP_Error
	 */
	public static function verify( $lead_id, array $args = array() ) {
		$lead_id = absint( $lead_id );
		if ( ! $lead_id ) {
			return new WP_Error( 'invalid_lead', __( 'Invalid lead.', 'mcp-ai-wpoos-pro' ) );
		}

		$email = get_post_meta( $lead_id, 'email', true );
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'no_email', __( 'Lead has no valid email address.', 'mcp-ai-wpoos-pro' ) );
		}

		$force   = ! empty( $args['force'] );
		$current = self::get_status( $lead_id );
		if ( ! $force && 'unknown' !== $current['status'] && ! self::is_stale( $lead_id ) ) {
			return array(
				'lead_id'    => $lead_id,
				'email'      => $email,
				'status'     => $current['status'],
				'confidence' => $current['confidence'],
				'source'     => $current['source'],
				'pattern'    => $current['pattern'],
				'cached'     => true,
			);
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		$first  = (string) get_post_meta( $lead_id, 'first_name', true );
		$last   = (string) get_post_meta( $lead_id, 'last_name', true );

		// 1. A published address is the strongest evidence.
		$published = isset( $args['published'] ) && is_array( $args['published'] ) ? $args['published'] : array();
		foreach ( $published as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['email'] ) && strtolower( trim( $entry['email'] ) ) === strtolower( $email ) ) {
				self::set_status(
					$lead_id,
					'verified',
					array(
						'confidence' => 100,
						'source'     => 'published',
					)
				);
				return array(
					'lead_id'    => $lead_id,
					'email'      => $email,
					'status'     => 'verified',
					'confidence' => 100,
					'source'     => 'published',
					'pattern'    => '',
					'cached'     => false,
				);
			}
		}

		// 2. Pattern + one candidate.
		$pattern   = self::infer_pattern( $domain, $published );
		$candidate = '';
		if ( ! empty( $pattern['pattern'] ) && $first && $last ) {
			$candidate = self::build_address( $pattern['pattern'], $first, $last, $domain );
		}
		if ( ! $candidate ) {
			$candidate = $email;
		}

		// 3. Provider seam.
		$provider_result = apply_filters(
			'wp_mcp_ai_crm_verify_email',
			null,
			$candidate,
			array(
				'lead_id'  => $lead_id,
				'domain'   => $domain,
				'pattern'  => $pattern,
				'provider' => 'filter',
			)
		);

		$status     = 'guess';
		$confidence = min( 50, absint( $pattern['confidence'] ) );
		$source     = $pattern['pattern'] ? 'pattern' : 'guess';

		if ( is_array( $provider_result ) && isset( $provider_result['status'] ) && in_array( $provider_result['status'], self::STATUSES, true ) ) {
			$status     = $provider_result['status'];
			$confidence = isset( $provider_result['confidence'] ) ? absint( $provider_result['confidence'] ) : $confidence;
			$source     = isset( $provider_result['provider'] ) ? sanitize_key( $provider_result['provider'] ) : 'provider';
		} elseif ( self::domain_has_mx( $domain ) ) {
			// MX only proves the domain accepts mail, not the mailbox.
			$status     = 'guess';
			$confidence = min( 60, max( 25, (int) $pattern['confidence'] ) );
		} else {
			$status     = 'invalid';
			$confidence = 0;
			$source     = 'mx';
		}

		self::set_status(
			$lead_id,
			$status,
			array(
				'confidence' => $confidence,
				'source'     => $source,
				'pattern'    => $pattern['pattern'],
			)
		);

		return array(
			'lead_id'    => $lead_id,
			'email'      => $candidate,
			'status'     => $status,
			'confidence' => $confidence,
			'source'     => $source,
			'pattern'    => $pattern['pattern'],
			'cached'     => false,
		);
	}

	/**
	 * Whether a domain has MX records.
	 *
	 * @param string $domain Domain.
	 * @return bool
	 */
	private static function domain_has_mx( $domain ) {
		$records = self::dns_records( $domain, 'MX' );
		return ! empty( $records );
	}

	/*
	---------------------------------------------------------------------
	* DNS / domain authentication
	* ------------------------------------------------------------------
	*/

	/**
	 * Fetch DNS records through a filterable seam.
	 *
	 * @param string $host Host.
	 * @param string $type Record type (TXT, MX, A).
	 * @return array
	 */
	private static function dns_records( $host, $type ) {
		/**
		 * Filter DNS records before the real resolver runs.
		 *
		 * Return an array to short-circuit (tests, private resolvers).
		 *
		 * @param array|null $records Records.
		 * @param string     $host    Host.
		 * @param string     $type    Record type.
		 */
		$records = apply_filters( 'wp_mcp_ai_crm_dns_records', null, $host, $type );
		if ( is_array( $records ) ) {
			return $records;
		}

		$map   = array(
			'TXT' => DNS_TXT,
			'MX'  => DNS_MX,
			'A'   => DNS_A,
		);
		$const = isset( $map[ $type ] ) ? $map[ $type ] : DNS_TXT;
		$found = @dns_get_record( $host, $const ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolver failures are non-fatal.
		return is_array( $found ) ? $found : array();
	}

	/**
	 * Extract TXT strings from a DNS answer.
	 *
	 * @param array $records Records.
	 * @return string[]
	 */
	private static function txt_values( array $records ) {
		$values = array();
		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) ) {
				$values[] = (string) $record['txt'];
			}
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$values[] = implode( '', $record['entries'] );
			}
		}
		return $values;
	}

	/**
	 * Check a sending domain's authentication.
	 *
	 * @param string $domain   Domain.
	 * @param string $selector DKIM selector.
	 * @return array
	 */
	public static function check_domain( $domain, $selector = '' ) {
		$domain   = strtolower( trim( (string) $domain ) );
		$settings = self::get_settings();
		$selector = $selector ? sanitize_key( $selector ) : (string) $settings['dkim_selector'];

		if ( '' === $domain || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain ) ) {
			return array(
				'domain'   => $domain,
				'ready'    => false,
				'checks'   => array(),
				'blockers' => array( __( 'No valid sending domain is configured.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$cache_key = 'wp_mcp_ai_crm_dns_' . md5( $domain . '|' . $selector );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$spf_txt = self::txt_values( self::dns_records( $domain, 'TXT' ) );
		$spf     = false;
		foreach ( $spf_txt as $txt ) {
			if ( 0 === stripos( trim( $txt ), 'v=spf1' ) ) {
				$spf = true;
				break;
			}
		}

		$selectors = array_unique( array_filter( array( $selector, 'default', 'mail', 'google', 'selector1', 'selector2', 'k1' ) ) );
		$dkim      = false;
		$found_sel = '';
		foreach ( $selectors as $try ) {
			$txts = self::txt_values( self::dns_records( $try . '._domainkey.' . $domain, 'TXT' ) );
			foreach ( $txts as $txt ) {
				if ( false !== stripos( $txt, 'v=DKIM1' ) || false !== stripos( $txt, 'p=' ) ) {
					$dkim      = true;
					$found_sel = $try;
					break 2;
				}
			}
		}

		$dmarc_txt = self::txt_values( self::dns_records( '_dmarc.' . $domain, 'TXT' ) );
		$dmarc     = false;
		$policy    = '';
		foreach ( $dmarc_txt as $txt ) {
			if ( 0 === stripos( trim( $txt ), 'v=DMARC1' ) ) {
				$dmarc = true;
				if ( preg_match( '/\bp\s*=\s*(none|quarantine|reject)/i', $txt, $m ) ) {
					$policy = strtolower( $m[1] );
				}
				break;
			}
		}

		$blockers = array();
		$warnings = array();
		if ( ! $spf ) {
			$blockers[] = __( 'SPF record is missing.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! $dkim ) {
			$blockers[] = __( 'DKIM record is missing.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! $dmarc ) {
			$blockers[] = __( 'DMARC record is missing.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'none' === $policy ) {
			$warnings[] = __( 'DMARC policy is "none"; quarantine or reject is recommended.', 'mcp-ai-wpoos-pro' );
		}

		$result = array(
			'domain'   => $domain,
			'ready'    => empty( $blockers ),
			'checks'   => array(
				'spf'           => $spf,
				'dkim'          => $dkim,
				'dkim_selector' => $found_sel,
				'dmarc'         => $dmarc,
				'dmarc_policy'  => $policy,
			),
			'blockers' => $blockers,
			'warnings' => $warnings,
		);

		set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	/*
	---------------------------------------------------------------------
	* Bounce accounting
	* ------------------------------------------------------------------
	*/

	/**
	 * Read the rolling stats.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		return wp_parse_args(
			$stats,
			array(
				'sent'        => 0,
				'hard_bounce' => 0,
				'soft_bounce' => 0,
				'complaints'  => 0,
				'updated_at'  => '',
			)
		);
	}

	/**
	 * Record a bounce.
	 *
	 * @param int    $lead_id    Lead post ID.
	 * @param string $type       hard|soft|complaint.
	 * @param string $diagnostic Optional diagnostic.
	 * @return void
	 */
	public static function record_bounce( $lead_id, $type, $diagnostic = '' ) {
		$lead_id = absint( $lead_id );
		$type    = sanitize_key( $type );
		if ( ! $lead_id ) {
			return;
		}

		$stats = self::get_stats();
		if ( 'hard' === $type ) {
			++$stats['hard_bounce'];
			self::set_status(
				$lead_id,
				'invalid',
				array(
					'source'     => 'bounce',
					'confidence' => 0,
				)
			);
			update_post_meta( $lead_id, '_sequence_paused', '1' );
		} elseif ( 'soft' === $type ) {
			++$stats['soft_bounce'];
			$dates   = get_post_meta( $lead_id, '_crm_soft_bounce_dates', true );
			$dates   = is_array( $dates ) ? $dates : array();
			$dates[] = time();
			$dates   = array_values(
				array_filter(
					$dates,
					static function ( $ts ) {
						return ( time() - (int) $ts ) <= ( 14 * DAY_IN_SECONDS );
					}
				)
			);
			update_post_meta( $lead_id, '_crm_soft_bounce_dates', $dates );
			if ( count( $dates ) >= 3 ) {
				update_post_meta( $lead_id, '_sequence_paused', '1' );
			}
		} elseif ( 'complaint' === $type ) {
			++$stats['complaints'];
			update_post_meta( $lead_id, '_sequence_paused', '1' );
		}

		$stats['updated_at'] = gmdate( 'c' );
		update_option( self::STATS_OPTION, $stats, false );

		update_post_meta( $lead_id, '_crm_last_bounce_at', gmdate( 'c' ) );
		if ( $diagnostic ) {
			update_post_meta( $lead_id, '_crm_last_bounce_diagnostic', sanitize_text_field( $diagnostic ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'email_bounce_recorded',
				'lead',
				$lead_id,
				array(
					'type'       => $type,
					'diagnostic' => $diagnostic,
				)
			);
		}
	}

	/**
	 * Increment the sent counter (called after a successful send).
	 *
	 * @return void
	 */
	public static function record_sent() {
		$stats = self::get_stats();
		++$stats['sent'];
		$stats['updated_at'] = gmdate( 'c' );
		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Current bounce rate (0–1).
	 *
	 * @return float
	 */
	public static function bounce_rate() {
		$stats = self::get_stats();
		$sent  = max( 1, (int) $stats['sent'] );
		return ( (int) $stats['hard_bounce'] + (int) $stats['soft_bounce'] ) / $sent;
	}

	/**
	 * Current complaint rate (0–1).
	 *
	 * @return float
	 */
	public static function complaint_rate() {
		$stats = self::get_stats();
		$sent  = max( 1, (int) $stats['sent'] );
		return (int) $stats['complaints'] / $sent;
	}

	/*
	---------------------------------------------------------------------
	* Warmup
	* ------------------------------------------------------------------
	*/

	/**
	 * Day index since warmup started (0-based), or null when not started.
	 *
	 * @return int|null
	 */
	public static function warmup_day() {
		$settings = self::get_settings();
		$start    = (string) $settings['warmup_start_date'];
		if ( '' === $start ) {
			return null;
		}
		$ts = strtotime( $start . ' 00:00:00 UTC' );
		if ( ! $ts ) {
			return null;
		}
		return max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Daily cold-email cap for a mailbox.
	 *
	 * @param string $mailbox Mailbox address.
	 * @param string $date    Optional Y-m-d (UTC).
	 * @return int
	 */
	public static function daily_cap_for_mailbox( $mailbox = '', $date = '' ) {
		$settings = self::get_settings();
		$max      = max( 1, absint( $settings['max_per_mailbox_per_day'] ) );
		$day      = self::warmup_day();

		if ( ! empty( $settings['warmup_paused'] ) ) {
			return 0;
		}

		if ( null === $day ) {
			return $max;
		}

		$min_days = max( 0, absint( $settings['warmup_min_days'] ) );
		if ( $day < $min_days ) {
			return 0;
		}

		$start    = max( 1, absint( $settings['warmup_start_per_day'] ) );
		$increase = max( 0, absint( $settings['warmup_daily_increase'] ) );
		$cap      = $start + ( $increase * ( $day - $min_days ) );
		$cap      = min( $max, $cap );

		// Per-mailbox override.
		if ( $mailbox && ! empty( $settings['mailboxes'] ) && is_array( $settings['mailboxes'] ) ) {
			$key = strtolower( trim( (string) $mailbox ) );
			if ( isset( $settings['mailboxes'][ $key ] ) && is_numeric( $settings['mailboxes'][ $key ] ) ) {
				$cap = min( $cap, max( 0, (int) $settings['mailboxes'][ $key ] ) );
			}
		}

		return (int) $cap;
	}

	/**
	 * Today's send counter option key for a mailbox.
	 *
	 * @param string $mailbox Mailbox.
	 * @return string
	 */
	public static function counter_key( $mailbox ) {
		return 'wp_mcp_ai_crm_send_counter_' . md5( strtolower( trim( (string) $mailbox ) ) ) . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Get today's sent count for a mailbox.
	 *
	 * @param string $mailbox Mailbox.
	 * @return int
	 */
	public static function sent_today( $mailbox ) {
		return absint( get_option( self::counter_key( $mailbox ), 0 ) );
	}

	/**
	 * Increment today's sent count for a mailbox.
	 *
	 * @param string $mailbox Mailbox.
	 * @return int New count.
	 */
	public static function increment_sent_today( $mailbox ) {
		$key   = self::counter_key( $mailbox );
		$count = absint( get_option( $key, 0 ) ) + 1;
		update_option( $key, $count, false );
		return $count;
	}

	/*
	---------------------------------------------------------------------
	* Readiness
	* ------------------------------------------------------------------
	*/

	/**
	 * Composite sending-readiness score.
	 *
	 * @return array
	 */
	public static function readiness() {
		$settings = self::get_settings();
		$blockers = array();
		$warnings = array();
		$checks   = array();

		$domain = (string) $settings['sending_domain'];
		if ( '' === $domain ) {
			$from   = isset( $settings['from_email'] ) ? (string) $settings['from_email'] : '';
			$domain = $from && is_email( $from ) ? substr( strrchr( $from, '@' ), 1 ) : '';
		}
		if ( '' === $domain ) {
			$blockers[]       = __( 'No sending domain is configured.', 'mcp-ai-wpoos-pro' );
			$checks['domain'] = array( 'ready' => false );
		} else {
			$checks['domain'] = self::check_domain( $domain );
			if ( empty( $checks['domain']['ready'] ) ) {
				$blockers = array_merge( $blockers, (array) $checks['domain']['blockers'] );
			}
			if ( ! empty( $checks['domain']['warnings'] ) ) {
				$warnings = array_merge( $warnings, (array) $checks['domain']['warnings'] );
			}
		}

		$day              = self::warmup_day();
		$checks['warmup'] = array(
			'day'      => $day,
			'paused'   => ! empty( $settings['warmup_paused'] ),
			'min_days' => (int) $settings['warmup_min_days'],
			'cap'      => self::daily_cap_for_mailbox(),
		);
		if ( ! empty( $settings['warmup_paused'] ) ) {
			$blockers[] = __( 'Warmup is paused.', 'mcp-ai-wpoos-pro' );
		} elseif ( null === $day ) {
			$blockers[] = __( 'Warmup has not been started.', 'mcp-ai-wpoos-pro' );
		} elseif ( $day < (int) $settings['warmup_min_days'] ) {
			$blockers[] = sprintf(
				/* translators: 1: current day, 2: required days */
				__( 'Warmup is incomplete (day %1$d of %2$d).', 'mcp-ai-wpoos-pro' ),
				$day,
				(int) $settings['warmup_min_days']
			);
		}

		$bounce           = self::bounce_rate();
		$checks['bounce'] = array(
			'rate'      => $bounce,
			'threshold' => (float) $settings['bounce_pause_threshold'],
		);
		if ( $bounce > (float) $settings['bounce_pause_threshold'] ) {
			$blockers[] = sprintf(
				/* translators: 1: rate, 2: threshold */
				__( 'Bounce rate is above the pause threshold (%1$s > %2$s).', 'mcp-ai-wpoos-pro' ),
				number_format_i18n( $bounce * 100, 1 ) . '%',
				number_format_i18n( (float) $settings['bounce_pause_threshold'] * 100, 1 ) . '%'
			);
		}

		$complaint           = self::complaint_rate();
		$checks['complaint'] = array(
			'rate'      => $complaint,
			'threshold' => (float) $settings['complaint_threshold'],
		);
		if ( $complaint > (float) $settings['complaint_threshold'] ) {
			$blockers[] = __( 'Complaint rate is above the configured threshold.', 'mcp-ai-wpoos-pro' );
		}

		$coverage           = self::verified_coverage();
		$checks['coverage'] = $coverage;
		if ( $coverage['total'] > 0 && $coverage['percent'] < 90 ) {
			$warnings[] = sprintf(
				/* translators: %s: coverage percent */
				__( 'Only %s%% of active leads have a verified address.', 'mcp-ai-wpoos-pro' ),
				number_format_i18n( $coverage['percent'], 0 )
			);
		}

		$score  = 100;
		$score -= min( 50, count( $blockers ) * 15 );
		$score -= min( 20, count( $warnings ) * 5 );
		$score  = max( 0, min( 100, $score ) );

		return array(
			'score'    => (int) $score,
			'ready'    => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'checks'   => $checks,
		);
	}

	/**
	 * Verified-address coverage across active leads.
	 *
	 * @return array{total:int,verified:int,percent:float}
	 */
	public static function verified_coverage() {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'email',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$total    = 0;
		$verified = 0;
		foreach ( $query->posts as $lead_id ) {
			++$total;
			$status = self::get_status( $lead_id );
			if ( in_array( $status['status'], self::DELIVERABLE, true ) ) {
				++$verified;
			}
		}

		return array(
			'total'    => $total,
			'verified' => $verified,
			'percent'  => $total > 0 ? ( $verified / $total ) * 100 : 100,
		);
	}
}
