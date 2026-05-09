<?php
/**
 * End-to-end smoke tester for the NV oOS SaaS Controller.
 *
 * Runs a fixed sequence of fast, read-only health checks against the live
 * Cloudflare account and the local WordPress environment, then returns a
 * structured `{ ok, checks[], duration_ms, ts }` payload that can be
 * rendered in WP-Admin or polled by the (eventual) drift detector.
 *
 * Checks (in order, each one independent):
 *   1. `cloudflare_credentials` — credential store has both account ID + token.
 *   2. `cloudflare_workers`     — `GET /accounts/{id}/workers/scripts` succeeds.
 *   3. `plan_dry_run`           — `Plan_Generator::generate()` returns no `errors[]`.
 *   4. `base_plugin_alive`      — base NV oOS plugin singleton resolvable.
 *
 * Each check is timed independently. A failed check does **not** abort
 * subsequent checks — the operator should see the full picture, not just
 * the first failure. The aggregate `ok` flag is `false` if any check
 * failed.
 *
 * Every check writes one entry to the audit log (channel: `internal` for
 * checks 1/3/4, `cloudflare` for check 2) so the run is forensically
 * traceable. The full result is also cached in
 * `nvoos_saas_controller_last_smoke_test` for the Operations tab.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smoke tester.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Smoke_Tester {

	/**
	 * WP option that caches the last run.
	 *
	 * @var string
	 */
	const LAST_RESULT_OPTION = 'nvoos_saas_controller_last_smoke_test';

	/**
	 * Optional Cloudflare client override (test injection).
	 *
	 * @var NVOOS_SaaS_Controller_Cloudflare_Client|null
	 */
	protected $cloudflare_client = null;

	/**
	 * Optional plan-generator override (test injection).
	 *
	 * @var NVOOS_SaaS_Controller_Plan_Generator|null
	 */
	protected $plan_generator = null;

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
	 * Inject a pre-built plan generator (used by tests / DI).
	 *
	 * @since 0.1.0
	 *
	 * @param NVOOS_SaaS_Controller_Plan_Generator $generator Generator.
	 * @return void
	 */
	public function set_plan_generator( $generator ) {
		$this->plan_generator = $generator;
	}

	/**
	 * Run all checks.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok:bool,checks:array<int,array>,duration_ms:int,ts:int}
	 */
	public function run() {
		$started_us = microtime( true );
		$checks     = array();

		$checks[] = $this->check_cloudflare_credentials();
		$checks[] = $this->check_cloudflare_workers();
		$checks[] = $this->check_plan_dry_run();
		$checks[] = $this->check_base_plugin_alive();

		$ok = true;
		foreach ( $checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$ok = false;
				break;
			}
		}

		$result = array(
			'ok'          => $ok,
			'checks'      => $checks,
			'duration_ms' => (int) round( ( microtime( true ) - $started_us ) * 1000 ),
			'ts'          => time(),
		);

		update_option( self::LAST_RESULT_OPTION, $result, false );
		return $result;
	}

	/**
	 * Get the most recent run, or `null` if none has executed yet.
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
	 * Check 1: credential store has both Cloudflare credentials.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected function check_cloudflare_credentials() {
		$started_us = microtime( true );
		$store      = NVOOS_SaaS_Controller_Credential_Store::instance();
		$creds      = $store->get_all();

		$account_id = isset( $creds['cloudflare_account_id'] ) ? (string) $creds['cloudflare_account_id'] : '';
		$api_token  = isset( $creds['cloudflare_api_token'] ) ? (string) $creds['cloudflare_api_token'] : '';

		$ok      = ( '' !== $account_id && '' !== $api_token );
		$message = $ok
			? __( 'Cloudflare credentials present.', 'nvoos-saas-controller' )
			: __( 'Cloudflare account ID or API token is missing from the credential store.', 'nvoos-saas-controller' );

		return $this->finalise(
			'cloudflare_credentials',
			'internal',
			$ok,
			$message,
			$started_us
		);
	}

	/**
	 * Check 2: live `list_workers` call succeeds.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected function check_cloudflare_workers() {
		$started_us = microtime( true );

		$client = $this->cloudflare_client;
		if ( null === $client ) {
			$client = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store();
		}
		if ( is_wp_error( $client ) ) {
			return $this->finalise(
				'cloudflare_workers',
				'cloudflare',
				false,
				$client->get_error_message(),
				$started_us
			);
		}

		$workers = $client->list_workers();
		if ( is_wp_error( $workers ) ) {
			return $this->finalise(
				'cloudflare_workers',
				'cloudflare',
				false,
				$workers->get_error_message(),
				$started_us
			);
		}

		return $this->finalise(
			'cloudflare_workers',
			'cloudflare',
			true,
			sprintf(
				/* translators: %d: number of workers reported by the Cloudflare API */
				_n( '%d worker reachable.', '%d workers reachable.', count( $workers ), 'nvoos-saas-controller' ),
				count( $workers )
			),
			$started_us
		);
	}

	/**
	 * Check 3: plan-generator dry run reports no `errors[]`.
	 *
	 * A non-empty desired config is not required — an empty desired config
	 * is a valid input that should produce a clean plan made up entirely
	 * of `orphans`. What we're checking is that the *path* end-to-end
	 * works (credentials → list endpoints → diff).
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected function check_plan_dry_run() {
		$started_us = microtime( true );

		$generator = $this->plan_generator;
		if ( null === $generator ) {
			$client = $this->cloudflare_client;
			if ( null === $client ) {
				$client = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store();
			}
			if ( is_wp_error( $client ) ) {
				return $this->finalise(
					'plan_dry_run',
					'internal',
					false,
					$client->get_error_message(),
					$started_us
				);
			}
			$generator = new NVOOS_SaaS_Controller_Plan_Generator( $client );
		}

		$desired = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
		$plan    = $generator->generate( $desired );
		if ( is_wp_error( $plan ) ) {
			return $this->finalise(
				'plan_dry_run',
				'internal',
				false,
				$plan->get_error_message(),
				$started_us
			);
		}

		$errors = isset( $plan['errors'] ) && is_array( $plan['errors'] ) ? $plan['errors'] : array();
		if ( ! empty( $errors ) ) {
			$first_msg = '';
			if ( isset( $errors[0]['message'] ) ) {
				$first_msg = (string) $errors[0]['message'];
			}
			return $this->finalise(
				'plan_dry_run',
				'internal',
				false,
				sprintf(
					/* translators: 1: error count, 2: first error message */
					__( 'Plan completed with %1$d Cloudflare error(s). First: %2$s', 'nvoos-saas-controller' ),
					count( $errors ),
					$first_msg
				),
				$started_us
			);
		}

		return $this->finalise(
			'plan_dry_run',
			'internal',
			true,
			__( 'Plan dry-run completed without errors.', 'nvoos-saas-controller' ),
			$started_us
		);
	}

	/**
	 * Check 4: base NV oOS plugin singleton is resolvable.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected function check_base_plugin_alive() {
		$started_us = microtime( true );

		$ok      = class_exists( 'WP_MCP_AI_Plugin' );
		$message = $ok
			? __( 'NV oOS base plugin detected.', 'nvoos-saas-controller' )
			: __( 'NV oOS base plugin not found at runtime.', 'nvoos-saas-controller' );

		return $this->finalise(
			'base_plugin_alive',
			'internal',
			$ok,
			$message,
			$started_us
		);
	}

	/**
	 * Build the result row for a single check, write it to the audit log,
	 * and return it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name       Check name (snake_case).
	 * @param string $channel    Audit-log channel.
	 * @param bool   $ok         Pass/fail.
	 * @param string $message    Short status message (no secrets).
	 * @param float  $started_us Microsecond timestamp from {@see microtime(true)}.
	 * @return array
	 */
	protected function finalise( $name, $channel, $ok, $message, $started_us ) {
		$latency_ms = (int) round( ( microtime( true ) - $started_us ) * 1000 );
		$row        = array(
			'name'       => (string) $name,
			'ok'         => (bool) $ok,
			'latency_ms' => $latency_ms,
			'message'    => (string) $message,
		);

		NVOOS_SaaS_Controller_Audit_Log::instance()->record(
			array(
				'channel'    => $channel,
				'action'     => 'smoke_test:' . $name,
				'status'     => $ok ? 'ok' : 'error',
				'latency_ms' => $latency_ms,
				'message'    => (string) $message,
			)
		);

		return $row;
	}
}
