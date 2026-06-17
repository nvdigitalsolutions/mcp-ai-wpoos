<?php
/**
 * Drift detector for the NV oOS SaaS Controller (Phase 5c).
 *
 * Compares the deployed Cloudflare Worker against the addon's pinned
 * `worker/dist/index.js` fingerprint and surfaces drift to the operator
 * via WP-Admin (an admin notice on every NV oOS SaaS screen plus a
 * dedicated panel in the Operations tab). Read-only — never mutates
 * Cloudflare. The Apply step (Phase 5b) and the eventual Worker upload
 * (Phase 5d) are the only paths that ever change the deployed script.
 *
 * # Pinning model
 *
 * The build pipeline writes `worker/drift-manifest.json` with the shape
 * `{ expected_sha256, expected_etag, version, built_at, worker_dist_path }`.
 * After a successful Phase 5d Apply the engine *also* writes the actual
 * upload fingerprint to the WP option `nvoos_saas_controller_deployed_worker`,
 * which the detector falls back to whenever the on-disk manifest still
 * ships with `null` pins. The order of preference is therefore:
 *
 *   1. on-disk `worker/drift-manifest.json` (release-time pin)
 *   2. `nvoos_saas_controller_deployed_worker` option (post-Apply pin)
 *   3. neither → `status=unknown` (no false-positive banners on a fresh
 *      install that has neither built nor applied yet).
 *
 * # Comparison precedence
 *
 * 1. **etag** — Cloudflare's own content fingerprint, returned in the
 *    `etag` header from `GET /workers/scripts/{name}`. Preferred when
 *    available because it round-trips multipart module-worker uploads
 *    losslessly.
 * 2. **sha256** — sha256 of the response body. Used when the manifest
 *    has no etag pinned (e.g. first run after a fresh deploy, before the
 *    build step has been re-run with the new etag).
 *
 * # Status taxonomy
 *
 * - `synced`  — the deployed script matches the pinned fingerprint.
 * - `drift`   — the deployed script does **not** match. The admin banner
 *               appears and the Operations tab highlights the row in red.
 * - `unknown` — at least one of the inputs is missing (manifest pin
 *               absent, credentials missing, Worker not yet deployed,
 *               etc.). The admin banner is **not** shown for this state.
 * - `error`   — the Cloudflare API failed (transient outage, invalid
 *               token, etc.). The admin banner is **not** shown; the
 *               error text is surfaced in the Operations tab.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drift detector.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Drift_Detector {

	/**
	 * WP option key for the most recent drift-check result.
	 *
	 * @var string
	 */
	const LAST_RESULT_OPTION = 'nvoos_saas_controller_last_drift_check';

	/**
	 * Default Worker name (used only when the deployment config has no
	 * explicit worker name set).
	 *
	 * @var string
	 */
	const DEFAULT_WORKER_NAME = 'nvoos-cloud';

	/**
	 * Path (relative to the addon root) to the pinned manifest.
	 *
	 * @var string
	 */
	const MANIFEST_PATH = 'worker/drift-manifest.json';

	/**
	 * WP option key holding the most recent successful Worker upload's
	 * fingerprint, written by the Phase 5d Apply step. Used as a fallback
	 * source of truth when the on-disk manifest still ships with `null`
	 * pins (i.e. before the build pipeline has stamped it at release time).
	 *
	 * Lazy-defined here as a string literal rather than a `use` of the
	 * apply-engine constant so this class still loads in tests that
	 * don't pull the apply engine in.
	 *
	 * @var string
	 */
	const DEPLOYED_OPTION = 'nvoos_saas_controller_deployed_worker';

	/**
	 * Optional Cloudflare client override (test injection).
	 *
	 * @var NVOOS_SaaS_Controller_Cloudflare_Client|null
	 */
	protected $cloudflare_client = null;

	/**
	 * Optional manifest override (test injection). When set, supersedes
	 * the on-disk manifest.
	 *
	 * @var array<string,mixed>|null
	 */
	protected $manifest_override = null;

	/**
	 * Inject a pre-built Cloudflare client (used by tests / DI).
	 *
	 * @since 0.1.0
	 *
	 * @param NVOOS_SaaS_Controller_Cloudflare_Client $client Client.
	 * @return void
	 */
	public function set_cloudflare_client( $client ) {
		$this->cloudflare_client = $client;
	}

	/**
	 * Inject a manifest override (used by tests).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,mixed>|null $manifest Manifest array, or null to
	 *                                           clear the override.
	 * @return void
	 */
	public function set_manifest( $manifest ) {
		$this->manifest_override = $manifest;
	}

	/**
	 * Run a drift check.
	 *
	 * Always persists its return value to {@see self::LAST_RESULT_OPTION}
	 * (so the Operations tab and admin banner can render it without an
	 * extra round-trip). Always records exactly one audit-log entry on
	 * channel `cloudflare` (for the Worker fetch) plus one on `internal`
	 * (for the comparison verdict) so the operator can see both the API
	 * call and the conclusion.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok:bool,status:string,worker_name:string,expected_sha256:?string,expected_etag:?string,actual_sha256:?string,actual_etag:?string,manifest_version:?string,manifest_built_at:?string,message:string,duration_ms:int,ts:int}
	 */
	public function check() {
		$started_us  = microtime( true );
		$manifest    = $this->load_manifest();
		$worker_name = $this->resolve_worker_name();

		// Phase 5d hand-off: when the on-disk manifest still has null
		// pins (the pre-release default) but the operator has already run
		// Apply, prefer the Apply-time fingerprint stored in the
		// deployed-worker option. This is what flips a fresh install
		// from `unknown` → `synced` immediately after the first upload,
		// without waiting for a manifest-stamping rebuild.
		$expected_sha256 = isset( $manifest['expected_sha256'] ) ? $manifest['expected_sha256'] : null;
		$expected_etag   = isset( $manifest['expected_etag'] ) ? $manifest['expected_etag'] : null;
		$source          = 'manifest';
		if ( null === $expected_sha256 && null === $expected_etag ) {
			$deployed = get_option( self::DEPLOYED_OPTION, null );
			if ( is_array( $deployed ) ) {
				$expected_sha256 = ! empty( $deployed['sha256'] ) ? (string) $deployed['sha256'] : null;
				$expected_etag   = ! empty( $deployed['etag'] ) ? (string) $deployed['etag'] : null;
				if ( null !== $expected_sha256 || null !== $expected_etag ) {
					$source = 'deployed_option';
				}
			}
		}

		$result = array(
			'ok'                => false,
			'status'            => 'unknown',
			'worker_name'       => $worker_name,
			'expected_sha256'   => $expected_sha256,
			'expected_etag'     => $expected_etag,
			'actual_sha256'     => null,
			'actual_etag'       => null,
			'manifest_version'  => isset( $manifest['version'] ) ? $manifest['version'] : null,
			'manifest_built_at' => isset( $manifest['built_at'] ) ? $manifest['built_at'] : null,
			'source'            => $source,
			'message'           => '',
			'duration_ms'       => 0,
			'ts'                => time(),
		);

		// Short-circuit: no pinned fingerprint at all → status=unknown.
		if ( null === $result['expected_sha256'] && null === $result['expected_etag'] ) {
			$result['status']  = 'unknown';
			$result['ok']      = false;
			$result['message'] = __( 'No pinned fingerprint available — run `npm run build:worker` to stamp worker/drift-manifest.json, or run Apply once to record a deployed fingerprint.', 'nvoos-saas-controller' );
			return $this->finalize( $result, $started_us );
		}

		$client = $this->resolve_client();
		if ( is_wp_error( $client ) ) {
			$result['status']  = 'unknown';
			$result['ok']      = false;
			$result['message'] = (string) $client->get_error_message();
			return $this->finalize( $result, $started_us );
		}

		$fetched = $client->get_worker_script( $worker_name );
		if ( is_wp_error( $fetched ) ) {
			// 404 means the Worker isn't deployed yet — that is "unknown",
			// not "drift" (operator hasn't run Apply/upload yet).
			$err_data    = $fetched->get_error_data();
			$status_code = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 0;
			if ( 404 === $status_code ) {
				$result['status']  = 'unknown';
				$result['ok']      = false;
				$result['message'] = sprintf(
					/* translators: %s: worker name */
					__( 'Worker "%s" is not deployed on Cloudflare yet.', 'nvoos-saas-controller' ),
					$worker_name
				);
			} else {
				$result['status']  = 'error';
				$result['ok']      = false;
				$result['message'] = (string) $fetched->get_error_message();
			}
			return $this->finalize( $result, $started_us );
		}

		$result['actual_sha256'] = hash( 'sha256', (string) $fetched['body'] );
		$result['actual_etag']   = isset( $fetched['etag'] ) ? (string) $fetched['etag'] : '';

		// etag wins when the manifest pins one (Cloudflare's own
		// fingerprint is the most reliable comparable identifier).
		if ( ! empty( $result['expected_etag'] ) ) {
			$matches = ( $result['expected_etag'] === $result['actual_etag'] );
		} else {
			$matches = ( $result['expected_sha256'] === $result['actual_sha256'] );
		}

		if ( $matches ) {
			$result['status']  = 'synced';
			$result['ok']      = true;
			$result['message'] = sprintf(
				/* translators: %s: worker name */
				__( 'Deployed Worker "%s" matches the pinned fingerprint.', 'nvoos-saas-controller' ),
				$worker_name
			);
		} else {
			$result['status']  = 'drift';
			$result['ok']      = false;
			$result['message'] = sprintf(
				/* translators: %s: worker name */
				__( 'Deployed Worker "%s" differs from the pinned fingerprint. Re-run Apply or redeploy the Worker.', 'nvoos-saas-controller' ),
				$worker_name
			);
		}

		return $this->finalize( $result, $started_us );
	}

	/**
	 * Persist the result to the cache option, record an audit entry, and
	 * stamp the duration before returning.
	 *
	 * @since 0.1.0
	 *
	 * @param array $result     Result-in-progress.
	 * @param float $started_us microtime(true) value at the start of the run.
	 * @return array
	 */
	protected function finalize( array $result, $started_us ) {
		$result['duration_ms'] = (int) round( ( microtime( true ) - $started_us ) * 1000 );
		update_option( self::LAST_RESULT_OPTION, $result, false );

		if ( class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			NVOOS_SaaS_Controller_Audit_Log::instance()->record(
				array(
					'channel'    => 'internal',
					'action'     => 'drift_check',
					'target'     => $result['worker_name'],
					'status'     => ( 'synced' === $result['status'] ) ? 'ok' : 'error',
					'latency_ms' => $result['duration_ms'],
					'message'    => sprintf( '[%s] %s', $result['status'], $result['message'] ),
				)
			);
		}

		return $result;
	}

	/**
	 * Get the most recent drift-check result, or `null` if none has run.
	 *
	 * @since 0.1.0
	 *
	 * @return array|null
	 */
	public function get_last_result() {
		$raw = get_option( self::LAST_RESULT_OPTION, null );
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * Load the pinned manifest from disk (or from the test override).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	protected function load_manifest() {
		if ( null !== $this->manifest_override ) {
			return $this->manifest_override;
		}

		$base = defined( 'NVOOS_SAAS_CONTROLLER_PATH' ) ? NVOOS_SAAS_CONTROLLER_PATH : dirname( __DIR__, 2 ) . '/';
		$path = $base . self::MANIFEST_PATH;
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Resolve the Worker name from the deployment config, falling back to
	 * the addon default when unset.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function resolve_worker_name() {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Deployment_Config' ) ) {
			return self::DEFAULT_WORKER_NAME;
		}
		$config = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
		if ( is_array( $config ) && ! empty( $config['worker_name'] ) ) {
			return (string) $config['worker_name'];
		}
		return self::DEFAULT_WORKER_NAME;
	}

	/**
	 * Resolve the Cloudflare client to use for this run.
	 *
	 * @since 0.1.0
	 *
	 * @return NVOOS_SaaS_Controller_Cloudflare_Client|WP_Error
	 */
	protected function resolve_client() {
		if ( null !== $this->cloudflare_client ) {
			return $this->cloudflare_client;
		}

		$account_override = null;
		if ( class_exists( 'NVOOS_SaaS_Controller_Deployment_Config' ) ) {
			$config = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
			if ( is_array( $config ) && ! empty( $config['account_id'] ) ) {
				$account_override = (string) $config['account_id'];
			}
		}

		return NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store( $account_override );
	}
}
