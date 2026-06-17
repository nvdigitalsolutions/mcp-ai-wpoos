<?php
/**
 * Apply Engine for the NV oOS SaaS Controller (Phases 5b + 5d).
 *
 * Consumes a Plan produced by {@see NVOOS_SaaS_Controller_Plan_Generator}
 * and executes its `creates[]` and Worker `updates[]` against Cloudflare,
 * gated on an explicit Human-in-the-Loop (HITL) approval token.
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
 * - `plan.orphans[]` — never deleted automatically. Cloudflare resources
 *   are persistent state with sometimes-irrecoverable data (D1 contents in
 *   particular); orphan deletion will arrive behind a separate explicit
 *   "Prune Orphans" surface in Phase 5e, never as a side effect of Apply.
 *
 * # Phase 5d — Worker upload
 *
 * `kind=worker` rows in `creates[]` and the Worker re-upload row in
 * `updates[]` invoke {@see NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::upload_worker_script()}
 * with the built `worker/dist/index.js` body and a multipart metadata
 * descriptor derived from the deployment config (D1 + KV bindings, plus
 * an AI-Gateway env var when configured). On success we persist the
 * actual sha256 + Cloudflare-assigned etag to the WP option
 * {@see self::DEPLOYED_OPTION} so the Phase 5c drift detector flips from
 * `unknown` → `synced` without a rebuild. If the dist artefact is missing
 * (e.g. the operator hasn't run `npm run build:worker` yet) the row is
 * recorded with `status=error` and a clear remediation message — Apply
 * never half-deploys.
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
	 * Transient key prefix for orphan-cleanup HITL tokens (Phase 10).
	 *
	 * Intentionally distinct from {@see self::TRANSIENT_PREFIX} so a token
	 * issued for `POST /apply/preview` cannot be accidentally (or
	 * maliciously) replayed against `POST /apply/orphans/run` — and vice
	 * versa. Each surface keeps its own single-use namespace.
	 *
	 * @var string
	 */
	const ORPHAN_TRANSIENT_PREFIX = 'nvoos_saas_orphan_';

	/**
	 * Default token TTL, seconds. Filterable via
	 * `nvoos_saas_controller_apply_token_ttl`.
	 *
	 * @var int
	 */
	const DEFAULT_TOKEN_TTL = 900; // 15 minutes.

	/**
	 * Option key used to persist the most recent successful Worker upload
	 * fingerprint (Phase 5d). Read by
	 * {@see NVOOS_SaaS_Controller_Drift_Detector} as a fallback when the
	 * on-disk `worker/drift-manifest.json` still ships with `null` pins
	 * (i.e. before the build pipeline has stamped the manifest at release
	 * time).
	 *
	 * Shape: `[ 'sha256' => …, 'etag' => …, 'worker_name' => …, 'uploaded_at' => UNIX-ts ]`.
	 *
	 * @var string
	 */
	const DEPLOYED_OPTION = 'nvoos_saas_controller_deployed_worker';

	/**
	 * Default Worker module-worker compatibility date used when the
	 * deployment config does not pin one. Matches `wrangler`'s recommended
	 * default for new Workers and can be overridden via the
	 * `nvoos_saas_controller_worker_compatibility_date` filter.
	 *
	 * @var string
	 */
	const DEFAULT_COMPATIBILITY_DATE = '2024-12-30';

	/**
	 * Default relative path inside the addon to the built ESM Worker
	 * bundle. Filterable via `nvoos_saas_controller_worker_dist_path`.
	 *
	 * @var string
	 */
	const DEFAULT_WORKER_DIST = 'worker/dist/index.js';

	/**
	 * Mutating Cloudflare client.
	 *
	 * @var NVOOS_SaaS_Controller_Cloudflare_Mutating_Client
	 */
	protected $client;

	/**
	 * Optional Stripe client (Phase 6). When null, `kind=stripe_*` rows
	 * are recorded as `skipped`.
	 *
	 * @var NVOOS_SaaS_Controller_Stripe_Client|null
	 */
	protected $stripe;

	/**
	 * Optional OpenRouter client (Phase 6). When null, `kind=openrouter_key`
	 * rows are recorded as `skipped`.
	 *
	 * @var NVOOS_SaaS_Controller_OpenRouter_Client|null
	 */
	protected $openrouter;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param NVOOS_SaaS_Controller_Cloudflare_Mutating_Client $client     Mutating Cloudflare client.
	 * @param NVOOS_SaaS_Controller_Stripe_Client|null         $stripe     Optional Stripe client.
	 * @param NVOOS_SaaS_Controller_OpenRouter_Client|null     $openrouter Optional OpenRouter client.
	 */
	public function __construct(
		NVOOS_SaaS_Controller_Cloudflare_Mutating_Client $client,
		$stripe = null,
		$openrouter = null
	) {
		$this->client     = $client;
		$this->stripe     = ( $stripe instanceof NVOOS_SaaS_Controller_Stripe_Client ) ? $stripe : null;
		$this->openrouter = ( $openrouter instanceof NVOOS_SaaS_Controller_OpenRouter_Client ) ? $openrouter : null;
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

		$hash   = hash( 'sha256', $token );
		$key    = self::TRANSIENT_PREFIX . $hash;
		$stored = get_transient( $key );
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
			array_merge(
				$stored,
				array(
					'used'    => true,
					'used_at' => time(),
				)
			),
			$ttl_remaining
		);

		return $stored['plan'];
	}

	/**
	 * Issue a single-use HITL token for orphan cleanup (Phase 10).
	 *
	 * Orphan deletes are deliberately gated on a *separate* token namespace
	 * from {@see self::issue_token()} / {@see self::consume_token()}: a
	 * token good for `POST /apply/run` cannot be replayed against
	 * `POST /apply/orphans/run`, and vice versa. This makes the
	 * destructive surface impossible to confuse with the create surface
	 * even if a careless operator presses the wrong button.
	 *
	 * The cached payload includes the *full* set of orphans the operator
	 * reviewed — the run call says which subset to delete, and the engine
	 * verifies every selected row matches one in the cached set, so the
	 * browser cannot extend the delete set after issuance.
	 *
	 * @since 0.1.0
	 *
	 * @param array $orphans `plan.orphans[]` rows from the freshly-run plan.
	 * @return array `[ 'token' => string, 'expires_in' => int ]`.
	 */
	public static function issue_orphan_token( array $orphans ) {
		$token = self::generate_token();
		$hash  = hash( 'sha256', $token );
		$key   = self::ORPHAN_TRANSIENT_PREFIX . $hash;
		$ttl   = (int) apply_filters( 'nvoos_saas_controller_apply_token_ttl', self::DEFAULT_TOKEN_TTL );
		if ( $ttl <= 60 ) {
			$ttl = self::DEFAULT_TOKEN_TTL;
		}

		set_transient(
			$key,
			array(
				'orphans'   => array_values( array_filter( $orphans, 'is_array' ) ),
				'issued_at' => time(),
				'used'      => false,
			),
			$ttl
		);

		if ( class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			NVOOS_SaaS_Controller_Audit_Log::instance()->record(
				array(
					'channel' => 'internal',
					'action'  => 'orphan_token_issued',
					'target'  => substr( $hash, 0, 12 ),
					'status'  => 'ok',
					'message' => sprintf(
						/* translators: 1: TTL seconds, 2: orphan count */
						__( 'Orphan-cleanup token issued; TTL %1$d seconds, %2$d candidate rows.', 'nvoos-saas-controller' ),
						$ttl,
						count( $orphans )
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
	 * Validate, single-use-mark, and return the cached orphan list for a
	 * Phase 10 orphan token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token Plaintext orphan token.
	 * @return array|WP_Error Cached `orphans[]` on success.
	 */
	public static function consume_orphan_token( $token ) {
		$token = is_scalar( $token ) ? (string) $token : '';
		if ( '' === $token || strlen( $token ) < 32 ) {
			return new WP_Error(
				'invalid_orphan_token',
				__( 'Orphan-cleanup token is missing or malformed.', 'nvoos-saas-controller' ),
				array( 'status' => 400 )
			);
		}

		$hash   = hash( 'sha256', $token );
		$key    = self::ORPHAN_TRANSIENT_PREFIX . $hash;
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ! isset( $stored['orphans'] ) || ! is_array( $stored['orphans'] ) ) {
			return new WP_Error(
				'expired_orphan_token',
				__( 'Orphan-cleanup token is unknown or has expired. Re-run the Review Orphans flow.', 'nvoos-saas-controller' ),
				array( 'status' => 410 )
			);
		}

		if ( ! empty( $stored['used'] ) ) {
			return new WP_Error(
				'consumed_orphan_token',
				__( 'Orphan-cleanup token has already been used. Tokens are single-use.', 'nvoos-saas-controller' ),
				array( 'status' => 409 )
			);
		}

		$ttl_remaining = self::transient_remaining_ttl( $key );
		if ( $ttl_remaining < 60 ) {
			$ttl_remaining = 60;
		}
		set_transient(
			$key,
			array_merge(
				$stored,
				array(
					'used'    => true,
					'used_at' => time(),
				)
			),
			$ttl_remaining
		);

		return $stored['orphans'];
	}

	/**
	 * Apply a vetted set of orphan-row deletes (Phase 10).
	 *
	 * Each `$selected` row is matched against the cached `$cached_orphans`
	 * list by an exact identity-key tuple (kind + the kind-specific
	 * mutating identifier — e.g. d1.uuid, kv.id, ai_gateway.slug,
	 * stripe_product.id, stripe_price.id, openrouter_key.hash). Rows that
	 * don't match the cached set are recorded as `'rejected'` (never sent
	 * upstream), so even a tampered run payload cannot delete a resource
	 * the operator never reviewed.
	 *
	 * @since 0.1.0
	 *
	 * @param array $selected       Per-row delete selections submitted by the operator.
	 * @param array $cached_orphans Orphans cached against the consumed token.
	 * @return array Same shape as {@see self::apply()}.
	 */
	public function apply_orphans( array $selected, array $cached_orphans ) {
		$started = microtime( true );
		$results = array();

		$index = array();
		foreach ( $cached_orphans as $orphan ) {
			if ( ! is_array( $orphan ) ) {
				continue;
			}
			$key = $this->orphan_identity_key( $orphan );
			if ( '' !== $key ) {
				$index[ $key ] = $orphan;
			}
		}

		foreach ( $selected as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = $this->orphan_identity_key( $row );
			if ( '' === $key || ! isset( $index[ $key ] ) ) {
				$results[] = array(
					'kind'    => isset( $row['kind'] ) ? (string) $row['kind'] : 'unknown',
					'target'  => $this->orphan_target_label( $row ),
					'status'  => 'rejected',
					'message' => __( 'Selected row does not match any reviewed orphan; refusing to delete.', 'nvoos-saas-controller' ),
				);
				continue;
			}
			$results[] = $this->apply_row( $index[ $key ], 'delete' );
		}

		$summary = array(
			'ok'       => 0,
			'error'    => 0,
			'skipped'  => 0,
			'rejected' => 0,
		);
		foreach ( $results as $r ) {
			$status = isset( $r['status'] ) ? (string) $r['status'] : 'error';
			if ( ! isset( $summary[ $status ] ) ) {
				$summary[ $status ] = 0;
			}
			++$summary[ $status ];
		}

		return array(
			'results'     => $results,
			'summary'     => $summary,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'ts'          => time(),
		);
	}

	/**
	 * Best-effort human-readable label for an orphan row, used in
	 * `'rejected'` result rows where we don't have the cached entry to
	 * fall back on. Walks the kind-agnostic fields in priority order:
	 * `name` → `title` → `slug` → `label` → `id` → `hash`.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row.
	 * @return string
	 */
	protected function orphan_target_label( array $row ) {
		foreach ( array( 'name', 'title', 'slug', 'label', 'id', 'hash' ) as $field ) {
			if ( isset( $row[ $field ] ) && '' !== (string) $row[ $field ] ) {
				return (string) $row[ $field ];
			}
		}
		return '';
	}

	/**
	 * Build the deterministic identity-key tuple used to match a selected
	 * orphan row against the cached preview list.
	 *
	 * Per-kind identifier:
	 *   • d1              — uuid
	 *   • kv              — id
	 *   • ai_gateway      — slug
	 *   • stripe_product  — id
	 *   • stripe_price    — id
	 *   • openrouter_key  — hash
	 *
	 * Returns an empty string when the row is missing the required field;
	 * the caller then records `status=rejected` for that row.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row.
	 * @return string
	 */
	protected function orphan_identity_key( array $row ) {
		$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		switch ( $kind ) {
			case 'd1':
				$id = isset( $row['uuid'] ) ? (string) $row['uuid'] : '';
				break;
			case 'kv':
			case 'stripe_product':
			case 'stripe_price':
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';
				break;
			case 'ai_gateway':
				$id = isset( $row['slug'] ) ? (string) $row['slug'] : '';
				break;
			case 'openrouter_key':
				$id = isset( $row['hash'] ) ? (string) $row['hash'] : '';
				break;
			default:
				return '';
		}
		return '' === $id ? '' : $kind . ':' . $id;
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
			$results[] = $this->apply_row( $row, 'create' );
		}

		$updates = isset( $plan['updates'] ) && is_array( $plan['updates'] ) ? $plan['updates'] : array();
		foreach ( $updates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$results[] = $this->apply_row( $row, 'update' );
		}

		$summary = array(
			'ok'      => 0,
			'error'   => 0,
			'skipped' => 0,
		);
		foreach ( $results as $r ) {
			$status = isset( $r['status'] ) ? (string) $r['status'] : 'error';
			if ( ! isset( $summary[ $status ] ) ) {
				$summary[ $status ] = 0;
			}
			++$summary[ $status ];
		}

		return array(
			'results'     => $results,
			'summary'     => $summary,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'ts'          => time(),
		);
	}

	/**
	 * Apply a single plan row.
	 *
	 * Public per-row entry point used by both the synchronous
	 * {@see self::apply()} loop and the background-tick worker
	 * {@see NVOOS_SaaS_Controller_Apply_Job} (Phase 8). Always returns a
	 * structured result row of the shape
	 * `{ kind, target, status: ok|error|skipped, message, detail? }`.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $row     One plan row from `creates[]` or `updates[]`.
	 * @param string $section `'create'` for `creates[]` rows, `'update'`
	 *                        for `updates[]` rows. Determines whether
	 *                        non-worker `update` rows are recorded as
	 *                        `skipped` (creates dispatch every supported
	 *                        kind; updates only re-upload the Worker).
	 * @return array Result row.
	 */
	public function apply_row( array $row, $section = 'create' ) {
		if ( 'delete' === $section ) {
			return $this->apply_delete( $row );
		}
		$section = ( 'update' === $section ) ? 'update' : 'create';

		if ( 'update' === $section ) {
			$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
			if ( 'worker' === $kind ) {
				return $this->apply_worker_upload( $row, 'updated' );
			}
			return array(
				'kind'    => $kind ? $kind : 'unknown',
				'target'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'status'  => 'skipped',
				'message' => sprintf(
					/* translators: %s: plan-row kind. */
					__( 'Updates for "%s" are not applied automatically.', 'nvoos-saas-controller' ),
					$kind
				),
			);
		}

		return $this->apply_create( $row );
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
				return $this->apply_worker_upload( $row, 'created' );
			case 'stripe_product':
				return $this->apply_create_stripe_product( $row );
			case 'stripe_price':
				return $this->apply_create_stripe_price( $row );
			case 'openrouter_key':
				return $this->apply_create_openrouter_key( $row );
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
	 * Dispatch a single orphan row to the appropriate delete call (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row One entry from the cached `plan.orphans` list,
	 *                   already matched to the operator's selection.
	 * @return array Result row.
	 */
	protected function apply_delete( array $row ) {
		$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		switch ( $kind ) {
			case 'd1':
				return $this->apply_delete_d1( $row );
			case 'kv':
				return $this->apply_delete_kv( $row );
			case 'ai_gateway':
				return $this->apply_delete_ai_gateway( $row );
			case 'stripe_product':
				return $this->apply_delete_stripe_product( $row );
			case 'stripe_price':
				return $this->apply_delete_stripe_price( $row );
			case 'openrouter_key':
				return $this->apply_delete_openrouter_key( $row );
			default:
				return array(
					'kind'    => $kind,
					'target'  => '',
					'status'  => 'skipped',
					'message' => sprintf(
						/* translators: %s: orphan-row kind. */
						__( 'Unknown orphan kind "%s"; skipped.', 'nvoos-saas-controller' ),
						$kind
					),
				);
		}
	}

	/**
	 * Delete a single orphan D1 database (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row (`{ kind:'d1', name, uuid }`).
	 * @return array
	 */
	protected function apply_delete_d1( array $row ) {
		$name   = isset( $row['name'] ) ? (string) $row['name'] : '';
		$uuid   = isset( $row['uuid'] ) ? (string) $row['uuid'] : '';
		$result = $this->client->delete_d1_database( $uuid, $name );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'd1',
				'target'  => '' !== $name ? $name : $uuid,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'd1',
			'target'  => '' !== $name ? $name : $uuid,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: D1 database name. */
				__( 'Deleted orphan D1 database "%s".', 'nvoos-saas-controller' ),
				'' !== $name ? $name : $uuid
			),
			'detail'  => $result,
		);
	}

	/**
	 * Delete a single orphan KV namespace (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row (`{ kind:'kv', title, id }`).
	 * @return array
	 */
	protected function apply_delete_kv( array $row ) {
		$title  = isset( $row['title'] ) ? (string) $row['title'] : '';
		$id     = isset( $row['id'] ) ? (string) $row['id'] : '';
		$result = $this->client->delete_kv_namespace( $id, $title );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'kv',
				'target'  => '' !== $title ? $title : $id,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'kv',
			'target'  => '' !== $title ? $title : $id,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: KV namespace title. */
				__( 'Deleted orphan KV namespace "%s".', 'nvoos-saas-controller' ),
				'' !== $title ? $title : $id
			),
			'detail'  => $result,
		);
	}

	/**
	 * Delete a single orphan AI Gateway (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row.
	 * @return array
	 */
	protected function apply_delete_ai_gateway( array $row ) {
		$slug   = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		$result = $this->client->delete_ai_gateway( $slug );
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
			'message' => sprintf(
				/* translators: %s: AI Gateway slug. */
				__( 'Deleted orphan AI Gateway "%s".', 'nvoos-saas-controller' ),
				$slug
			),
			'detail'  => $result,
		);
	}

	/**
	 * Archive a single orphan Stripe product (Phase 10).
	 *
	 * Stripe forbids permanent deletion of products with attached prices
	 * or transactions; the {@see NVOOS_SaaS_Controller_Stripe_Client::archive_product()}
	 * call sets `active=false`, which removes the product from active
	 * listings (and from `list_products()` reconcile output) without
	 * breaking historical invoices.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row.
	 * @return array
	 */
	protected function apply_delete_stripe_product( array $row ) {
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( null === $this->stripe ) {
			return array(
				'kind'    => 'stripe_product',
				'target'  => $id,
				'status'  => 'skipped',
				'message' => __( 'Stripe credential is not configured; cannot archive Stripe product orphan.', 'nvoos-saas-controller' ),
			);
		}
		$result = $this->stripe->archive_product( $id );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'stripe_product',
				'target'  => $id,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'stripe_product',
			'target'  => $id,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: Stripe product id. */
				__( 'Archived orphan Stripe product %s (active=false).', 'nvoos-saas-controller' ),
				$id
			),
			'detail'  => $result,
		);
	}

	/**
	 * Archive a single orphan Stripe price (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row.
	 * @return array
	 */
	protected function apply_delete_stripe_price( array $row ) {
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( null === $this->stripe ) {
			return array(
				'kind'    => 'stripe_price',
				'target'  => $id,
				'status'  => 'skipped',
				'message' => __( 'Stripe credential is not configured; cannot archive Stripe price orphan.', 'nvoos-saas-controller' ),
			);
		}
		$result = $this->stripe->archive_price( $id );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'stripe_price',
				'target'  => $id,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'stripe_price',
			'target'  => $id,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: Stripe price id. */
				__( 'Archived orphan Stripe price %s (active=false).', 'nvoos-saas-controller' ),
				$id
			),
			'detail'  => $result,
		);
	}

	/**
	 * Delete a single orphan OpenRouter runtime key (Phase 10).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Orphan row (`{ kind:'openrouter_key', label, hash }`).
	 * @return array
	 */
	protected function apply_delete_openrouter_key( array $row ) {
		$label = isset( $row['label'] ) ? (string) $row['label'] : '';
		$hash  = isset( $row['hash'] ) ? (string) $row['hash'] : '';
		if ( null === $this->openrouter ) {
			return array(
				'kind'    => 'openrouter_key',
				'target'  => '' !== $label ? $label : $hash,
				'status'  => 'skipped',
				'message' => __( 'OpenRouter provisioning credential is not configured; cannot delete key orphan.', 'nvoos-saas-controller' ),
			);
		}
		$result = $this->openrouter->delete_key( $hash, $label );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'openrouter_key',
				'target'  => '' !== $label ? $label : $hash,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'openrouter_key',
			'target'  => '' !== $label ? $label : $hash,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: OpenRouter key label. */
				__( 'Deleted orphan OpenRouter key "%s".', 'nvoos-saas-controller' ),
				'' !== $label ? $label : $hash
			),
			'detail'  => $result,
		);
	}

	/**
	 * Apply a Worker create or re-upload row (Phase 5d).
	 *
	 * Reads the built ESM bundle from `worker/dist/index.js`, builds the
	 * module-worker metadata (entrypoint + compatibility date + bindings
	 * derived from the deployment config), uploads, and on success
	 * persists the actual sha256 + Cloudflare etag to
	 * {@see self::DEPLOYED_OPTION} so the drift detector can flip to
	 * `synced` immediately.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $row  Plan row.
	 * @param string $verb Past-tense verb to use in the success message
	 *                     (`created` for new uploads, `updated` for
	 *                     re-uploads of an existing Worker).
	 * @return array Result row.
	 */
	protected function apply_worker_upload( array $row, $verb ) {
		$name = isset( $row['name'] ) ? (string) $row['name'] : '';
		if ( '' === $name ) {
			return array(
				'kind'    => 'worker',
				'target'  => '',
				'status'  => 'error',
				'message' => __( 'Worker plan row has no name; cannot upload.', 'nvoos-saas-controller' ),
			);
		}

		$dist = $this->load_worker_dist();
		if ( is_wp_error( $dist ) ) {
			return array(
				'kind'    => 'worker',
				'target'  => $name,
				'status'  => 'error',
				'message' => $dist->get_error_message(),
			);
		}

		$metadata = $this->build_worker_metadata();
		$result   = $this->client->upload_worker_script( $name, $dist, $metadata );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'worker',
				'target'  => $name,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}

		$sha256 = hash( 'sha256', $dist );
		update_option(
			self::DEPLOYED_OPTION,
			array(
				'worker_name' => $name,
				'sha256'      => $sha256,
				'etag'        => isset( $result['etag'] ) ? (string) $result['etag'] : '',
				'size'        => isset( $result['size'] ) ? (int) $result['size'] : strlen( $dist ),
				'uploaded_at' => time(),
			),
			false
		);

		return array(
			'kind'    => 'worker',
			'target'  => $name,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: 1: created|updated, 2: worker name, 3: short sha256. */
				__( 'Worker %1$s "%2$s" (sha256 %3$s).', 'nvoos-saas-controller' ),
				(string) $verb,
				$name,
				substr( $sha256, 0, 12 )
			),
			'detail'  => $result,
		);
	}

	/**
	 * Read `worker/dist/index.js` from disk.
	 *
	 * @since 0.1.0
	 *
	 * @return string|WP_Error Raw bytes on success.
	 */
	protected function load_worker_dist() {
		$relative = (string) apply_filters( 'nvoos_saas_controller_worker_dist_path', self::DEFAULT_WORKER_DIST );
		$base     = defined( 'NVOOS_SAAS_CONTROLLER_PATH' ) ? NVOOS_SAAS_CONTROLLER_PATH : dirname( __DIR__, 2 ) . '/';
		$path     = $base . ltrim( $relative, '/' );

		if ( ! is_readable( $path ) ) {
			return new WP_Error(
				'worker_dist_missing',
				sprintf(
					/* translators: %s: relative path to the missing artefact. */
					__( 'Built Worker bundle not found at %s. Run `npm run build:worker` inside addons/saas-controller before applying.', 'nvoos-saas-controller' ),
					$relative
				)
			);
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error(
				'worker_dist_unreadable',
				sprintf(
					/* translators: %s: relative path. */
					__( 'Worker bundle at %s is unreadable or empty.', 'nvoos-saas-controller' ),
					$relative
				)
			);
		}

		return $bytes;
	}

	/**
	 * Build the module-worker metadata payload from the deployment config.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected function build_worker_metadata() {
		$config = array();
		if ( class_exists( 'NVOOS_SaaS_Controller_Deployment_Config' ) ) {
			$stored = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
			if ( is_array( $stored ) ) {
				$config = $stored;
			}
		}

		$bindings = array();
		if ( ! empty( $config['d1_databases'] ) && is_array( $config['d1_databases'] ) ) {
			foreach ( $config['d1_databases'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['binding'] ) || empty( $row['name'] ) ) {
					continue;
				}
				$bindings[] = array(
					'type'          => 'd1',
					'name'          => (string) $row['binding'],
					'database_name' => (string) $row['name'],
				);
			}
		}
		if ( ! empty( $config['kv_namespaces'] ) && is_array( $config['kv_namespaces'] ) ) {
			foreach ( $config['kv_namespaces'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['binding'] ) || empty( $row['title'] ) ) {
					continue;
				}
				$bindings[] = array(
					'type'         => 'kv_namespace',
					'name'         => (string) $row['binding'],
					'namespace_id' => (string) $row['title'],
				);
			}
		}
		if ( ! empty( $config['ai_gateway_slug'] ) ) {
			$bindings[] = array(
				'type' => 'plain_text',
				'name' => 'AI_GATEWAY_SLUG',
				'text' => (string) $config['ai_gateway_slug'],
			);
		}

		$compat_date = (string) apply_filters(
			'nvoos_saas_controller_worker_compatibility_date',
			self::DEFAULT_COMPATIBILITY_DATE
		);

		$metadata = array(
			'main_module'        => 'index.js',
			'compatibility_date' => $compat_date,
			'bindings'           => $bindings,
		);

		/**
		 * Filter the module-worker metadata payload before upload.
		 *
		 * Use this to add custom bindings (R2, Queues, secrets) without
		 * forking the engine.
		 *
		 * @since 0.1.0
		 *
		 * @param array $metadata Metadata payload.
		 * @param array $config   Deployment config.
		 */
		return (array) apply_filters( 'nvoos_saas_controller_worker_upload_metadata', $metadata, $config );
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
	 * Apply a single Stripe product create row (Phase 6).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_stripe_product( array $row ) {
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( null === $this->stripe ) {
			return array(
				'kind'    => 'stripe_product',
				'target'  => $id,
				'status'  => 'skipped',
				'message' => __( 'Stripe credential is not configured; cannot apply Stripe product row.', 'nvoos-saas-controller' ),
			);
		}
		$result = $this->stripe->create_product( $row );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'stripe_product',
				'target'  => $id,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'stripe_product',
			'target'  => isset( $result['id'] ) ? (string) $result['id'] : $id,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: Stripe product id. */
				__( 'Provisioned Stripe product %s.', 'nvoos-saas-controller' ),
				isset( $result['id'] ) ? $result['id'] : $id
			),
			'detail'  => $result,
		);
	}

	/**
	 * Apply a single Stripe price create row (Phase 6).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_stripe_price( array $row ) {
		$lookup_key = isset( $row['lookup_key'] ) ? (string) $row['lookup_key'] : '';
		if ( null === $this->stripe ) {
			return array(
				'kind'    => 'stripe_price',
				'target'  => $lookup_key,
				'status'  => 'skipped',
				'message' => __( 'Stripe credential is not configured; cannot apply Stripe price row.', 'nvoos-saas-controller' ),
			);
		}
		$result = $this->stripe->create_price( $row );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'stripe_price',
				'target'  => $lookup_key,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'stripe_price',
			'target'  => $lookup_key,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: 1: lookup key, 2: Stripe price id. */
				__( 'Provisioned Stripe price "%1$s" (id %2$s).', 'nvoos-saas-controller' ),
				$lookup_key,
				isset( $result['id'] ) ? $result['id'] : ''
			),
			'detail'  => $result,
		);
	}

	/**
	 * Apply a single OpenRouter runtime-key create row (Phase 6).
	 *
	 * The plaintext key value is surfaced exactly once in the result row
	 * and is never persisted by the addon — the operator is expected to
	 * copy it into their downstream secret store (Cloudflare Worker
	 * secrets, Vault, etc.).
	 *
	 * @since 0.1.0
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	protected function apply_create_openrouter_key( array $row ) {
		$label = isset( $row['label'] ) ? (string) $row['label'] : '';
		if ( null === $this->openrouter ) {
			return array(
				'kind'    => 'openrouter_key',
				'target'  => $label,
				'status'  => 'skipped',
				'message' => __( 'OpenRouter provisioning credential is not configured; cannot apply OpenRouter key row.', 'nvoos-saas-controller' ),
			);
		}
		$limit  = isset( $row['limit_usd'] ) ? (float) $row['limit_usd'] : null;
		$result = $this->openrouter->create_key( $label, $limit );
		if ( is_wp_error( $result ) ) {
			return array(
				'kind'    => 'openrouter_key',
				'target'  => $label,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'kind'    => 'openrouter_key',
			'target'  => $label,
			'status'  => 'ok',
			'message' => sprintf(
				/* translators: %s: OpenRouter key label. */
				__( 'Created OpenRouter runtime key "%s". The plaintext value is in detail.key — copy it now; it will not be returned again.', 'nvoos-saas-controller' ),
				$label
			),
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
