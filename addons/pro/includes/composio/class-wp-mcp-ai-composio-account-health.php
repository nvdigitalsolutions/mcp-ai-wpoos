<?php
/**
 * Composio Connect — connected-account health ledger and live probe engine.
 *
 * Composio's `/connected_accounts` listing reports the *stored* status of an
 * account. That status is a lagging indicator: when a user revokes the app in
 * their Google/Slack/GitHub account settings, Composio keeps reporting `ACTIVE`
 * until its own background refresh fails several times (or until the
 * `composio.connected_account.expired` webhook fires). Callers that trust the
 * stored status therefore hit a 401 on first real use — the false-positive that
 * this class exists to eliminate.
 *
 * There is no `/connected_accounts/{id}/verify` route in Composio's v3.1 API,
 * so "verified" here means one of two things, and the distinction is always
 * reported back to the caller via `verification_method`:
 *
 *  - `probe`       — an uncached authoritative account read PLUS a successful
 *                    execution of a zero-argument, read-only tool from the
 *                    account's own toolkit. This is the only method that can
 *                    detect a revoked-but-still-`ACTIVE` credential.
 *  - `status_only` — an uncached authoritative account read. Used when no safe
 *                   probe tool could be resolved for the toolkit. Never
 *                   reported as `verified`.
 *
 * Probe tools are *discovered*, not hardcoded: the toolkit's catalog is scanned
 * for a read-verb tool with no required input parameters, so the engine keeps
 * working as Composio's catalog evolves and never invents a slug that does not
 * exist. A small curated fast-path map short-circuits the lookup for the most
 * common toolkits.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @see https://docs.composio.dev/reference/api-reference/connected-accounts
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connected-account health ledger and probe engine.
 */
class WP_MCP_AI_Composio_Account_Health {

	/**
	 * Option-name prefix for the per-connection health ledger.
	 *
	 * An option (not a transient) because a validation verdict must survive an
	 * object-cache flush — losing it would silently reintroduce the
	 * false-positive this class prevents.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_composio_health_';

	/**
	 * Transient prefix for resolved per-toolkit probe tool slugs.
	 */
	const PROBE_CACHE_PREFIX = 'wp_mcp_ai_composio_probe_';

	/**
	 * How long a resolved probe tool slug stays cached (24 hours).
	 */
	const PROBE_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Maximum number of account records retained per connection.
	 */
	const MAX_RECORDS = 200;

	/**
	 * Age after which a verification verdict is considered stale (15 minutes).
	 *
	 * Stale does not mean wrong — it means "re-probe before betting a
	 * destructive write on it".
	 */
	const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * Composio statuses that represent a usable credential.
	 */
	const HEALTHY_STATUSES = array( 'ACTIVE' );

	/**
	 * Composio statuses that require the user to re-authenticate.
	 */
	const REAUTH_STATUSES = array( 'EXPIRED', 'REVOKED', 'FAILED' );

	/**
	 * Curated fast-path probe tools for common toolkits.
	 *
	 * Every entry is still validated against the live catalog before use, so a
	 * stale entry degrades to catalog discovery rather than to a bogus call.
	 */
	const CURATED_PROBE_TOOLS = array(
		'gmail'      => 'GMAIL_GET_PROFILE',
		'googledocs' => 'GOOGLEDOCS_GET_DOCUMENT_BY_ID',
		'github'     => 'GITHUB_GET_THE_AUTHENTICATED_USER',
		'notion'     => 'NOTION_GET_ABOUT_ME',
		'linear'     => 'LINEAR_GET_CURRENT_USER',
	);

	/**
	 * Slug verb segments that are safe to execute as a health probe.
	 *
	 * Matched as the second segment of a `{TOOLKIT}_{VERB}_...` slug so a
	 * write-class action can never be selected as a probe.
	 */
	const SAFE_PROBE_VERBS = array( 'GET', 'LIST', 'FETCH', 'SEARCH', 'FIND', 'RETRIEVE', 'COUNT', 'CHECK', 'VALIDATE', 'DESCRIBE', 'READ' );

	/**
	 * Substrings that identify an authentication/authorisation failure in an
	 * upstream error code or message.
	 */
	const AUTH_ERROR_SIGNATURES = array(
		'auth refresh required',
		'invalid_grant',
		'invalid grant',
		'invalid_credentials',
		'invalid credentials',
		'invalid authentication credentials',
		'token has been expired',
		'token expired',
		'token has expired',
		'expired or revoked',
		'access token',
		'refresh token',
		'unauthorized',
		'unauthenticated',
		'not authenticated',
		're-authenticate',
		'reauthenticate',
		'reconnect',
		'permission denied',
		'insufficient authentication',
		'insufficientpermissions',
		'connected account is not active',
		'connection is not active',
	);

	/**
	 * Matches a 401/403 proxied into an error message, e.g. Composio relaying
	 * "HTTP 401: Request had invalid authentication credentials.".
	 *
	 * Unanchored, unlike the client's failure *detector*: this classifier only
	 * ever runs on something already known to be an error, so it cannot invent a
	 * failure — at worst it labels an existing one as recoverable.
	 *
	 * @since 1.4.2
	 */
	const PROXIED_AUTH_STATUS_PATTERN = '/\bhttp[\s:\/]*(?:1\.[01]\s+)?(?:401|403)\b/';

	/**
	 * Read the health record for a single account.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $account_id    Connected account nanoid.
	 * @return array Health record, or an empty array when never validated.
	 */
	public static function get( $connection_id, $account_id ) {
		$account_id = sanitize_text_field( (string) $account_id );
		$ledger     = self::get_all( $connection_id );

		return isset( $ledger[ $account_id ] ) && is_array( $ledger[ $account_id ] ) ? $ledger[ $account_id ] : array();
	}

	/**
	 * Read the full health ledger for a connection.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @return array Map of account ID => health record.
	 */
	public static function get_all( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		if ( '' === $connection_id ) {
			return array();
		}

		$ledger = get_option( self::OPTION_PREFIX . $connection_id, array() );

		return is_array( $ledger ) ? $ledger : array();
	}

	/**
	 * Persist a health record for an account.
	 *
	 * The ledger is pruned to MAX_RECORDS by dropping the least-recently
	 * validated entries so a long-lived project cannot grow the option
	 * unbounded.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $account_id    Connected account nanoid.
	 * @param array  $record        Health record fields to store.
	 * @return array The stored record.
	 */
	public static function record( $connection_id, $account_id, array $record ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$account_id    = sanitize_text_field( (string) $account_id );

		if ( '' === $connection_id || '' === $account_id ) {
			return $record;
		}

		$record['checked_at'] = isset( $record['checked_at'] ) ? absint( $record['checked_at'] ) : time();

		$ledger                = self::get_all( $connection_id );
		$ledger[ $account_id ] = $record;

		if ( count( $ledger ) > self::MAX_RECORDS ) {
			uasort(
				$ledger,
				static function ( $a, $b ) {
					$a_time = isset( $a['checked_at'] ) ? absint( $a['checked_at'] ) : 0;
					$b_time = isset( $b['checked_at'] ) ? absint( $b['checked_at'] ) : 0;

					return $b_time <=> $a_time;
				}
			);
			$ledger = array_slice( $ledger, 0, self::MAX_RECORDS, true );
		}

		update_option( self::OPTION_PREFIX . $connection_id, $ledger, false );

		return $record;
	}

	/**
	 * Drop the health record for an account (used after deletion).
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $account_id    Connected account nanoid.
	 * @return void
	 */
	public static function forget( $connection_id, $account_id ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$account_id    = sanitize_text_field( (string) $account_id );

		if ( '' === $connection_id || '' === $account_id ) {
			return;
		}

		$ledger = self::get_all( $connection_id );

		if ( ! isset( $ledger[ $account_id ] ) ) {
			return;
		}

		unset( $ledger[ $account_id ] );
		update_option( self::OPTION_PREFIX . $connection_id, $ledger, false );
	}

	/**
	 * Delete the whole ledger for a connection.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @return void
	 */
	public static function forget_all( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		if ( '' === $connection_id ) {
			return;
		}

		delete_option( self::OPTION_PREFIX . $connection_id );
	}

	/**
	 * Whether a stored verdict is old enough to warrant re-probing.
	 *
	 * @since 1.4.1
	 *
	 * @param array $record Health record.
	 * @return bool
	 */
	public static function is_stale( array $record ) {
		if ( empty( $record['checked_at'] ) ) {
			return true;
		}

		return ( time() - absint( $record['checked_at'] ) ) > self::STALE_AFTER;
	}

	/**
	 * Verify a connected account against Composio for real.
	 *
	 * Two stages, both reported honestly in the returned record:
	 *
	 *  1. Authoritative read — `GET /connected_accounts/{id}` with caching
	 *     bypassed, giving the true stored status, `status_reason`, disabled
	 *     flag and credential expiry. Non-`ACTIVE` accounts short-circuit here
	 *     so a known-bad account never burns an execution quota.
	 *  2. Live probe — a zero-argument read-only tool from the account's own
	 *     toolkit. Only a success here sets `verified => true`.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client bound to the connection.
	 * @param string                    $connection_id Composio connection ID.
	 * @param string                    $account_id    Connected account nanoid.
	 * @param array                     $opts          Optional. Probe options: `account`
	 *                                                 (pre-fetched account payload,
	 *                                                 skips the authoritative read),
	 *                                                 `toolkit` (slug override), `probe`
	 *                                                 (bool, run the live probe;
	 *                                                 default true).
	 * @return array|WP_Error Health record, or WP_Error when the account cannot be read.
	 */
	public static function probe( $client, $connection_id, $account_id, array $opts = array() ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$account_id    = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required to validate.', 'mcp-ai-wpoos-pro' ) );
		}

		$run_probe = ! isset( $opts['probe'] ) || (bool) $opts['probe'];

		// Stage 1 — authoritative, cache-bypassing read.
		if ( isset( $opts['account'] ) && is_array( $opts['account'] ) && ! empty( $opts['account'] ) ) {
			$account = WP_MCP_AI_Composio_Client::normalize_account( $opts['account'] );
		} else {
			$raw = $client->get_connected_account( $account_id, 0 );

			if ( is_wp_error( $raw ) ) {
				return self::record(
					$connection_id,
					$account_id,
					array(
						'account_id'          => $account_id,
						'verified'            => false,
						'verification_method' => 'unreachable',
						'status'              => 'UNKNOWN',
						'last_error'          => $raw->get_error_message(),
						'last_error_code'     => $raw->get_error_code(),
						'needs_reconnect'     => false,
						'checked_at'          => time(),
					)
				);
			}

			$account = WP_MCP_AI_Composio_Client::normalize_account( is_array( $raw ) ? $raw : array() );
		}

		$toolkit = isset( $opts['toolkit'] ) && '' !== (string) $opts['toolkit']
			? sanitize_key( (string) $opts['toolkit'] )
			: ( isset( $account['toolkit'] ) ? (string) $account['toolkit'] : '' );

		$status = isset( $account['status'] ) ? strtoupper( (string) $account['status'] ) : '';

		$record = array(
			'account_id'          => $account_id,
			'toolkit'             => $toolkit,
			'status'              => '' !== $status ? $status : 'UNKNOWN',
			'status_reason'       => isset( $account['status_reason'] ) ? (string) $account['status_reason'] : '',
			'disabled'            => ! empty( $account['disabled'] ),
			'expires_at'          => isset( $account['expires_at'] ) ? (string) $account['expires_at'] : '',
			'owner'               => isset( $account['user_id'] ) ? (string) $account['user_id'] : '',
			'verified'            => false,
			'verification_method' => 'status_only',
			'probe_tool'          => '',
			'last_error'          => '',
			'last_error_code'     => '',
			'needs_reconnect'     => false,
			'checked_at'          => time(),
		);

		// A disabled or non-ACTIVE account is authoritatively unusable — record
		// the reason without spending an execution.
		if ( $record['disabled'] || ! in_array( $status, self::HEALTHY_STATUSES, true ) ) {
			$record['needs_reconnect'] = in_array( $status, self::REAUTH_STATUSES, true );
			$record['last_error']      = '' !== $record['status_reason']
				? $record['status_reason']
				: sprintf(
					/* translators: %s: Composio connected-account status */
					__( 'Composio reports this account as %s.', 'mcp-ai-wpoos-pro' ),
					'' !== $status ? $status : __( 'unknown', 'mcp-ai-wpoos-pro' )
				);
			$record['last_error_code'] = 'wp_mcp_ai_composio_account_' . strtolower( '' !== $status ? $status : 'unknown' );

			return self::record( $connection_id, $account_id, $record );
		}

		if ( ! $run_probe ) {
			return self::record( $connection_id, $account_id, $record );
		}

		// Stage 2 — live probe against the real provider.
		$probe_tool = self::resolve_probe_tool( $client, $connection_id, $toolkit );

		if ( '' === $probe_tool ) {
			// Honest degradation: no safe probe exists, so we refuse to claim
			// the credential is verified.
			$record['verification_method'] = 'status_only';
			$record['last_error']          = __( 'No zero-argument read-only tool is available for this toolkit, so the credential could not be probed. Status reflects what Composio has stored, which may lag a revoked token.', 'mcp-ai-wpoos-pro' );
			$record['last_error_code']     = 'wp_mcp_ai_composio_probe_unavailable';

			return self::record( $connection_id, $account_id, $record );
		}

		$record['probe_tool'] = $probe_tool;

		$probe = $client->execute_tool( $probe_tool, $account_id, array(), $record['owner'] );

		if ( is_wp_error( $probe ) ) {
			$code    = $probe->get_error_code();
			$message = $probe->get_error_message();

			$record['last_error']      = $message;
			$record['last_error_code'] = $code;
			$record['needs_reconnect'] = self::is_auth_error( $code, $message );

			// A non-auth probe failure says nothing about the credential (the
			// probe tool itself may need a mailbox, a repo, a workspace...).
			// Report it as inconclusive rather than as a dead token.
			$record['verification_method'] = $record['needs_reconnect'] ? 'probe' : 'probe_inconclusive';

			return self::record( $connection_id, $account_id, $record );
		}

		$record['verified']            = true;
		$record['verification_method'] = 'probe';
		$record['validated_at']        = time();

		return self::record( $connection_id, $account_id, $record );
	}

	/**
	 * Resolve a zero-argument, read-only tool usable as a health probe.
	 *
	 * Resolution order: curated fast path (validated against the catalog),
	 * then catalog discovery. The verdict — including "none available" — is
	 * cached per connection + toolkit for 24 hours.
	 *
	 * @since 1.4.1
	 *
	 * @param WP_MCP_AI_Composio_Client $client        Client instance.
	 * @param string                    $connection_id Composio connection ID.
	 * @param string                    $toolkit       Toolkit slug.
	 * @return string Probe tool slug, or an empty string when none is available.
	 */
	public static function resolve_probe_tool( $client, $connection_id, $toolkit ) {
		$toolkit = sanitize_key( (string) $toolkit );

		if ( '' === $toolkit ) {
			return '';
		}

		/**
		 * Filter the probe tool used to verify a toolkit's credentials.
		 *
		 * Return a SCREAMING_SNAKE tool slug to pin the probe, or an empty
		 * string to disable probing for the toolkit (verification then
		 * degrades to `status_only`).
		 *
		 * @since 1.4.1
		 *
		 * @param string|null $probe_tool Pinned probe tool slug, or null to auto-resolve.
		 * @param string      $toolkit    Toolkit slug.
		 */
		$pinned = apply_filters( 'wp_mcp_ai_composio_probe_tool', null, $toolkit );

		if ( is_string( $pinned ) ) {
			return self::is_safe_probe_slug( $pinned, $toolkit ) ? strtoupper( $pinned ) : '';
		}

		$cache_key = self::PROBE_CACHE_PREFIX . md5( sanitize_key( (string) $connection_id ) . '|' . $toolkit );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$resolved = '';

		// Fast path — a curated slug, confirmed to still exist upstream.
		if ( isset( self::CURATED_PROBE_TOOLS[ $toolkit ] ) ) {
			$candidate = self::CURATED_PROBE_TOOLS[ $toolkit ];
			$schema    = $client->get_tool_schema( $candidate );

			if ( ! is_wp_error( $schema ) && is_array( $schema ) && ! empty( $schema ) && self::has_no_required_inputs( $schema ) ) {
				$resolved = $candidate;
			}
		}

		// Discovery path — scan the toolkit's catalog for a safe candidate.
		if ( '' === $resolved ) {
			$catalog = $client->list_tools(
				array(
					'toolkit_slug' => $toolkit,
					'limit'        => 100,
				)
			);

			if ( ! is_wp_error( $catalog ) && isset( $catalog['items'] ) && is_array( $catalog['items'] ) ) {
				foreach ( $catalog['items'] as $tool ) {
					if ( ! is_array( $tool ) ) {
						continue;
					}

					$slug = isset( $tool['slug'] ) ? (string) $tool['slug'] : '';

					if ( ! self::is_safe_probe_slug( $slug, $toolkit ) ) {
						continue;
					}

					if ( ! empty( $tool['deprecated'] ) || ! empty( $tool['is_deprecated'] ) ) {
						continue;
					}

					if ( ! self::has_no_required_inputs( $tool ) ) {
						continue;
					}

					$resolved = strtoupper( $slug );
					break;
				}
			}
		}

		// Cache the verdict either way — a missing probe is just as expensive to
		// rediscover as a present one.
		set_transient( $cache_key, $resolved, self::PROBE_CACHE_TTL );

		return $resolved;
	}

	/**
	 * Whether a slug is a read-only action belonging to a toolkit.
	 *
	 * @since 1.4.1
	 *
	 * @param string $slug    Candidate tool slug.
	 * @param string $toolkit Toolkit slug.
	 * @return bool
	 */
	private static function is_safe_probe_slug( $slug, $toolkit ) {
		$slug = strtoupper( trim( (string) $slug ) );

		if ( '' === $slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $slug ) ) {
			return false;
		}

		$prefix = strtoupper( str_replace( '-', '_', (string) $toolkit ) ) . '_';

		if ( 0 !== strpos( $slug, $prefix ) ) {
			return false;
		}

		$verb = substr( $slug, strlen( $prefix ) );
		$verb = explode( '_', $verb );
		$verb = isset( $verb[0] ) ? $verb[0] : '';

		return in_array( $verb, self::SAFE_PROBE_VERBS, true );
	}

	/**
	 * Whether a tool definition declares no required input parameters.
	 *
	 * A probe must be callable with `{}` — anything else would fail for reasons
	 * unrelated to credential health.
	 *
	 * @since 1.4.1
	 *
	 * @param array $tool Tool definition or schema payload.
	 * @return bool
	 */
	private static function has_no_required_inputs( array $tool ) {
		$candidates = array();

		if ( isset( $tool['input_parameters'] ) && is_array( $tool['input_parameters'] ) ) {
			$candidates[] = $tool['input_parameters'];
		}
		if ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
			$candidates[] = $tool['parameters'];
		}
		if ( isset( $tool['schema']['input_parameters'] ) && is_array( $tool['schema']['input_parameters'] ) ) {
			$candidates[] = $tool['schema']['input_parameters'];
		}

		if ( empty( $candidates ) ) {
			// No schema information at all — assume it needs arguments rather
			// than risk a misleading probe failure.
			return false;
		}

		foreach ( $candidates as $schema ) {
			if ( ! empty( $schema['required'] ) && is_array( $schema['required'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Classify an error as an authentication/authorisation failure.
	 *
	 * @since 1.4.1
	 * @since 1.4.2 Recognises a 401/403 proxied into the message text.
	 *
	 * @param string $code    Error code (WP_Error code or upstream code).
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_auth_error( $code, $message ) {
		$code = strtolower( (string) $code );

		if ( false !== strpos( $code, 'http_401' ) || false !== strpos( $code, 'http_403' ) ) {
			return true;
		}

		if ( false !== strpos( $code, 'auth_required' ) || false !== strpos( $code, 'account_expired' ) || false !== strpos( $code, 'account_revoked' ) ) {
			return true;
		}

		$haystack = strtolower( (string) $message );

		if ( '' === $haystack ) {
			return false;
		}

		if ( preg_match( self::PROXIED_AUTH_STATUS_PATTERN, $haystack ) ) {
			return true;
		}

		foreach ( self::AUTH_ERROR_SIGNATURES as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the admin URL that re-runs the Connect Link flow for a toolkit.
	 *
	 * Returns an empty string outside an admin context with a logged-in user
	 * who can act, because the URL carries a nonce that would be useless.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $toolkit       Toolkit slug.
	 * @return string
	 */
	public static function build_reconnect_url( $connection_id, $toolkit ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$toolkit       = sanitize_key( (string) $toolkit );

		if ( '' === $connection_id || '' === $toolkit || ! function_exists( 'wp_create_nonce' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'composio_connect_link',
				'connection_id' => $connection_id,
				'toolkit'       => $toolkit,
				'_wpnonce'      => wp_create_nonce( 'composio_connect_link_' . $connection_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Compose the operator-facing guidance for a dead credential.
	 *
	 * @since 1.4.1
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $toolkit       Toolkit slug.
	 * @param string $account_id    Connected account nanoid.
	 * @param string $upstream      Optional. Upstream error message.
	 * @return array{message:string,reconnect_url:string,remedy:string}
	 */
	public static function build_reconnect_hint( $connection_id, $toolkit, $account_id, $upstream = '' ) {
		$url = self::build_reconnect_url( $connection_id, $toolkit );

		$message = sprintf(
			/* translators: 1: toolkit slug, 2: connected account ID */
			__( 'The %1$s credential for connected account %2$s is no longer accepted by the provider — it was most likely revoked or expired.', 'mcp-ai-wpoos-pro' ),
			'' !== $toolkit ? $toolkit : __( 'connected app', 'mcp-ai-wpoos-pro' ),
			'' !== $account_id ? $account_id : __( '(unknown)', 'mcp-ai-wpoos-pro' )
		);

		if ( '' !== (string) $upstream ) {
			$message .= ' ' . sprintf(
				/* translators: %s: upstream provider error message */
				__( 'Provider said: %s', 'mcp-ai-wpoos-pro' ),
				(string) $upstream
			);
		}

		$remedy = '' !== $url
			? sprintf(
				/* translators: %s: reconnect URL */
				__( 'Reconnect it here: %s — or call composio_manage_accounts with action "reconnect" to re-authorise this same account in place.', 'mcp-ai-wpoos-pro' ),
				$url
			)
			: __( 'Call composio_manage_accounts with action "reconnect" to re-authorise this same account in place, then retry.', 'mcp-ai-wpoos-pro' );

		return array(
			'message'       => $message . ' ' . $remedy,
			'reconnect_url' => $url,
			'remedy'        => $remedy,
		);
	}

	/**
	 * Summarise a health record for tool output.
	 *
	 * Escaping happens here so every tool that surfaces health data satisfies
	 * the two-gate rule identically.
	 *
	 * @since 1.4.1
	 *
	 * @param array $record Health record.
	 * @return array Escaped, presentation-ready health block.
	 */
	public static function present( array $record ) {
		if ( empty( $record ) ) {
			return array(
				'verified'            => false,
				'verification_method' => 'never_checked',
				'last_validated_at'   => '',
				'last_error'          => '',
				'needs_reconnect'     => false,
				'stale'               => true,
			);
		}

		$validated_at = isset( $record['validated_at'] ) ? absint( $record['validated_at'] ) : 0;

		return array(
			'verified'            => ! empty( $record['verified'] ),
			'verification_method' => esc_html( isset( $record['verification_method'] ) ? (string) $record['verification_method'] : '' ),
			'probe_tool'          => esc_html( isset( $record['probe_tool'] ) ? (string) $record['probe_tool'] : '' ),
			'last_validated_at'   => $validated_at > 0 ? esc_html( gmdate( 'c', $validated_at ) ) : '',
			'last_checked_at'     => ! empty( $record['checked_at'] ) ? esc_html( gmdate( 'c', absint( $record['checked_at'] ) ) ) : '',
			'last_error'          => esc_html( isset( $record['last_error'] ) ? (string) $record['last_error'] : '' ),
			'last_error_code'     => esc_html( isset( $record['last_error_code'] ) ? (string) $record['last_error_code'] : '' ),
			'needs_reconnect'     => ! empty( $record['needs_reconnect'] ),
			'stale'               => self::is_stale( $record ),
		);
	}
}
