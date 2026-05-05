<?php
/**
 * Apply Engine for the NV oOS SaaS Controller (Phase 5b).
 *
 * Consumes a Plan produced by {@see NVOOS_SaaS_Controller_Plan_Generator}
 * and executes its `creates[]` against Cloudflare, gated on an explicit
 * Human-in-the-Loop (HITL) approval token.
 *
 * # Token flow
 *
 * 1. Operator hits **Run Plan** in the Operations tab → POST /apply/preview
 *    on the server side calls `issue_token()` which:
 *      • re-runs the plan generator against the live state,
 *      • allocates a 32-byte (cryptographically random) `apply_token`,
 *      • persists `{ token_hash, plan, issued_at, used }` as a transient
 *        keyed by the SHA-256 hash of the token (TTL: 15 min, filterable),
 *      • returns the plan **plus** the plaintext token to the operator.
 * 2. Operator reviews the plan in WP-Admin and clicks **Apply**.
 * 3. Front-end sends POST /apply/run with `{ apply_token: "…" }`.
 * 4. `consume_token()` looks up the transient, marks `used=true` (single-use
 *    enforcement, prevents replay), and hands the cached plan to `apply()`.
 * 5. `apply()` walks `plan.creates[]` and dispatches each row to the
 *    mutating Cloudflare client, accumulating result rows of the shape
 *    `{ kind, name|title|slug, status: ok|error|skipped, message, … }`.
 *
 * # Why the plan is cached on the server
 *
 * If we re-derived the plan at apply time the live state could have drifted
 * between preview and apply (a teammate creating a KV namespace by hand,
 * for example), and we'd silently skip the row in apply that the operator
 * had explicitly reviewed. Caching the **exact** plan that was previewed
 * means apply mutations always match what the operator approved, even if
 * the live state changed in between. The trade-off is that an extremely
 * stale token (>TTL) is rejected; the operator must run preview again.
 *
 * # What is *not* applied yet
 *
 * - `plan.updates[]` — the only `updates` row the plan emits today is for
 *   Workers (the script needs re-uploading), and Worker upload requires the
 *   built `worker/dist/index.js` artefact + multipart metadata, which is
 *   the remit of Phase 5d. We mark these `skipped` with a clear reason.
 * - `plan.orphans[]` — never deleted automatically. Cloudflare resources
 *   are persistent state with sometimes-irrecoverable data (D1 contents in
 *   particular); orphan deletion will arrive behind a separate explicit
 *   "Prune Orphans" surface in Phase 5e, never as a side effect of Apply.
 * - `kind=worker` creates — same Phase 5d carve-out as updates above.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply engine.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Apply_Engine {

	/**
	 * Transient key prefix for cached HITL tokens.
	 *
	 * The full key is `nvoos_saas_apply_<sha256-of-token>` so the plaintext
	 * token never appears in the database — only its hash does. Transient
	 * lookup is `O(1)` because the caller supplies the plaintext token and
	 * we recompute the hash.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'nvoos_saas_apply_';

	/**
	 * Default token TTL, seconds. Filterable via
	 * `nvoos_saas_controller_apply_token_ttl`.
	 *
	 * @var int
	 */
	const DEFAULT_TOKEN_TTL = 900; // 15 minutes.

	/**
	 * Mutating Cloudflare client.
	 *
	 * @var NVOOS_SaaS_Controller_Cloudflare_Mutating_Client
	 */
	protected $client;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param NVOOS_SaaS_Controller_Cloudflare_Mutating_Client $client Mutating client.
	 */
	public function __construct( NVOOS_SaaS_Controller_Cloudflare_Mutating_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Issue a fresh HITL token bound to the supplied plan.
	 *
	 * The plan is stored alongside the token hash so apply-time can replay
	 * the *exact* set of resources the operator reviewed.
	 *
	 * @since 0.1.0
	 *
	 * @param array $plan Plan from {@see NVOOS_SaaS_Controller_Plan_Generator::generate()}.
	 * @return array `[ 'token' => <plaintext>, 'expires_in' => <seconds> ]`.
	 */
	public static function issue_token( array $plan ) {
		$ttl = (int) apply_filters( 'nvoos_saas_controller_apply_token_ttl', self::DEFAULT_TOKEN_TTL );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		$token = self::generate_token();
		$hash  = hash( 'sha256', $token );

		set_transient(
			self::TRANSIENT_PREFIX . $hash,
			array(
				'plan'      => $plan,
				'issued_at' => time(),
				'used'      => false,
			),
			$ttl
		);

		// Audit issuance — the operator should be able to see when an apply
		// window was opened, even if it is never used.
		if ( class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			NVOOS_SaaS_Controller_Audit_Log::instance()->record(
				array(
					'channel' => 'internal',
					'action'  => 'apply_token_issued',
					'target'  => substr( $hash, 0, 12 ),
					'status'  => 'ok',
					'message' => sprintf(
						/* translators: %d: TTL in seconds */
						__( 'Apply token issued; TTL %d seconds.', 'nvoos-saas-controller' ),
						$ttl
					),
				)
			);
		}

		return array(
			'token'      => $token,
			'expires_in' => $ttl,
		);
	}

	/**
	 * Validate, single-use-mark, and return the plan associated with a token.
	 *
	 * Returns `WP_Error` on missing/expired/already-used tokens. On success
	 * the transient is rewritten with `used=true` *before* the plan is
	 * returned, so a concurrent second call sees the token as consumed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token Plaintext apply token supplied by the operator.
	 * @return array|WP_Error Cached plan on success.
	 */
	public static function consume_token( $token ) {
		$token = is_scalar( $token ) ? (string) $token : '';
		if ( '' === $token || strlen( $token ) < 32 ) {
			return new WP_Error(
				'invalid_apply_token',
				__( 'Apply token is missing or malformed.', 'nvoos-saas-controller' ),
				array( 'status' => 400 )
			);
		}

		$hash    = hash( 'sha256', $token );
		$key     = self::TRANSIENT_PREFIX . $hash;
		$stored  = get_transient( $key );
		if ( ! is_array( $stored ) || empty( $stored['plan'] ) || ! is_array( $stored['plan'] ) ) {
			return new WP_Error(
				'expired_apply_token',
				__( 'Apply token is unknown or has expired. Run Plan again to issue a fresh token.', 'nvoos-saas-controller' ),
				array( 'status' => 410 )
			);
		}

		if ( ! empty( $stored['used'] ) ) {
			return new WP_Error(
				'consumed_apply_token',
				__( 'Apply token has already been used. Tokens are single-use; run Plan again.', 'nvoos-saas-controller' ),
				array( 'status' => 409 )
			);
		}

		// Single-use enforcement: rewrite *before* returning the plan, with
		// the same TTL so audit-side observers can still inspect that the
		// token was consumed but not re-spend it.
		$ttl_remaining = self::transient_remaining_ttl( $key );
		if ( $ttl_remaining < 60 ) {
			$ttl_remaining = 60;
		}
		set_transient(
			$key,
			array_merge( $stored, array( 'used' => true, 'used_at' => time() ) ),
			$ttl_remaining
		);

		return $stored['plan'];
	}

	/**
	 * Apply the supplied plan against Cloudflare.
	 *
	 * Iterates `plan.creates[]`, dispatches each row to the mutating client
	 * (or marks it `skipped` for kinds outside Phase 5b's scope), and
	 * returns a structured result.
	 *
	 * @since 0.1.0
	 *
	 * @param array $plan Plan to apply.
	 * @return array {
	 *     @type array $results Per-resource result rows.
	 *     @type array $summary `[ 'ok' => N, 'error' => N, 'skipped' => N ]`.
	 *     @type int   $duration_ms Wall time across the whole apply call.
	 *     @type int   $ts UNIX timestamp at completion.
	 * }
	 */
	public function apply( array $plan ) {
		$started = microtime( true );
		$results = array();

		$creates = isset( $plan['creates'] ) && is_array( $plan['creates'] ) ? $plan['creates'] : array();
		foreach ( $creates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$results[] = $this->apply_create( $row );
		}

		$updates = isset( $plan['updates'] ) && is_array( $plan['updates'] ) ? $plan['updates'] : array();
		foreach ( $updates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$results[] = array(
				'kind'    => isset( $row['kind'] ) ? (string) $row['kind'] : 'unknown',
				'target'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'status'  => 'skipped',
				'message' => __( 'Updates are not applied in Phase 5b — Worker re-upload arrives in Phase 5d.', 'nvoos-saas-controller' ),
			);
		}

		$summary = array( 'ok' => 0, 'error' => 0, 'skipped' => 0 );
		foreach ( $results as $r ) {
			$status = isset( $r['status'] ) ? (string) $r['status'] : 'error';
			if ( ! isset( $summary[ $status ] ) ) {
				$summary[ $status ] = 0;
			}
			$summary[ $status ]++;
		}

		return array(
			'results'     => $results,
			'summary'     => $summary,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'ts'          => time(),
		);
	}

	/**
	 * Dispatch a single `creates[]` row to the appropriate mutating call.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row One entry from `plan.creates`.
	 * @return array Result row.
	 */
	protected function apply_create( array $row ) {
		$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		switch ( $kind ) {
			case 'd1':
				return $this->apply_create_d1( $row );
			case 'kv':
				return $this->apply_create_kv( $row );
			case 'ai_gateway':
				return $this->apply_create_ai_gateway( $row );
			case 'worker':
				return array(
					'kind'    => 'worker',
					'target'  => isset( $row['name'] ) ? (string) $row['name'] : '',
					'status'  => 'skipped',
					'message' => __( 'Worker upload is deferred to Phase 5d (requires built dist/index.js).', 'nvoos-saas-controller' ),
				);
			default:
				return array(
					'kind'    => $kind,
					'target'  => '',
					'status'  => 'skipped',
					'message' => sprintf(
						/* translators: %s: plan-row kind. */
						__( 'Unknown plan kind "%s"; skipped.', 'nvoos-saas-controller' ),
						$kind
					),
				);
		}
	}

	/**
	 * Apply a single D1 create row.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_d1( array $row ) {
		$name   = isset( $row['name'] ) ? (string) $row['name'] : '';
		$result = $this->client->create_d1_database( $name );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'd1',
				'target'  => $name,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'd1',
			'target'  => $name,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: D1 database UUID. */
				__( 'Created D1 database (uuid %s).', 'nvoos-saas-controller' ),
				isset( $result['uuid'] ) ? $result['uuid'] : ''
			),
			'detail'  => $result,
		);
	}

	/**
	 * Apply a single KV create row.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_kv( array $row ) {
		$title  = isset( $row['title'] ) ? (string) $row['title'] : '';
		$result = $this->client->create_kv_namespace( $title );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'kv',
				'target'  => $title,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'kv',
			'target'  => $title,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: KV namespace ID. */
				__( 'Created KV namespace (id %s).', 'nvoos-saas-controller' ),
				isset( $result['id'] ) ? $result['id'] : ''
			),
			'detail'  => $result,
		);
	}

	/**
	 * Apply a single AI Gateway create row.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_ai_gateway( array $row ) {
		$slug   = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		$result = $this->client->create_ai_gateway( $slug );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'ai_gateway',
				'target'  => $slug,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'ai_gateway',
			'target'  => $slug,
			'status'  => 'ok',
			'message' => __( 'Created AI Gateway.', 'nvoos-saas-controller' ),
			'detail'  => $result,
		);
	}

	/**
	 * Generate a 32-byte URL-safe random token (43 base64url characters).
	 *
	 * Uses `random_bytes()` which is cryptographically secure on PHP 7.4+.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function generate_token() {
		$bytes  = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 );
		$base64 = rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
		return $base64;
	}

	/**
	 * Best-effort lookup of a transient's remaining TTL via the `_transient_timeout_*` option.
	 *
	 * Returns the configured default TTL when the option store does not
	 * expose timeouts (e.g. an external object-cache backend).
	 *
	 * @since 0.1.0
	 *
	 * @param string $transient_key Transient key including the prefix.
	 * @return int
	 */
	protected static function transient_remaining_ttl( $transient_key ) {
		$timeout_option = '_transient_timeout_' . $transient_key;
		$timeout        = get_option( $timeout_option, 0 );
		if ( $timeout > 0 ) {
			$remaining = (int) $timeout - time();
			if ( $remaining > 0 ) {
				return $remaining;
			}
		}
		$ttl = (int) apply_filters( 'nvoos_saas_controller_apply_token_ttl', self::DEFAULT_TOKEN_TTL );
		return $ttl > 60 ? $ttl : self::DEFAULT_TOKEN_TTL;
	}
}
