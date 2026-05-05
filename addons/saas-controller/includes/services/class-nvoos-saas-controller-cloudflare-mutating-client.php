<?php
/**
 * Mutating Cloudflare API client for the NV oOS SaaS Controller (Phase 5b).
 *
 * Companion to the read-only {@see NVOOS_SaaS_Controller_Cloudflare_Client}.
 * This class exposes only the writes the Apply step needs:
 *
 *   • POST /accounts/{account_id}/d1/database          — create a D1 database.
 *   • POST /accounts/{account_id}/storage/kv/namespaces — create a KV namespace.
 *   • POST /accounts/{account_id}/ai-gateway/gateways  — create an AI Gateway.
 *
 * Worker script upload is intentionally out of scope: it requires the built
 * `worker/dist/index.js` artefact and metadata multipart body, which is the
 * remit of Phase 5d (Worker upload + drift detector). Until then the apply
 * engine records `skipped` for `kind=worker` rows.
 *
 * Every call records exactly one entry in
 * {@see NVOOS_SaaS_Controller_Audit_Log} on the `cloudflare` channel —
 * success or failure — so the operator gets a forensic trail of every
 * mutation the addon has ever made on their behalf.
 *
 * Like the read-only client, mutation methods return either a normalised
 * result array or a `WP_Error`; they never throw.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare API client (mutating).
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Cloudflare_Mutating_Client {

	/**
	 * Cloudflare API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.cloudflare.com/client/v4';

	/**
	 * Per-request timeout, seconds. Mutating calls get a slightly longer
	 * budget than the read-only client because D1 database creation in
	 * particular can take several seconds on Cloudflare's side.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Cloudflare account ID.
	 *
	 * @var string
	 */
	protected $account_id;

	/**
	 * Cloudflare API token.
	 *
	 * @var string
	 */
	protected $api_token;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $account_id Cloudflare account ID.
	 * @param string $api_token  Cloudflare API token with Edit scope on Account,
	 *                           Workers, D1, KV, and AI Gateway.
	 */
	public function __construct( $account_id, $api_token ) {
		$this->account_id = (string) $account_id;
		$this->api_token  = (string) $api_token;
	}

	/**
	 * Build a client from the credential store.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $account_override Optional explicit account ID.
	 * @return self|WP_Error WP_Error('missing_credentials') when either credential is unset.
	 */
	public static function from_credential_store( $account_override = null ) {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$creds = $store->get_all();
		$account_id = $account_override
			? (string) $account_override
			: ( isset( $creds['cloudflare_account_id'] ) ? (string) $creds['cloudflare_account_id'] : '' );
		$api_token  = isset( $creds['cloudflare_api_token'] ) ? (string) $creds['cloudflare_api_token'] : '';
		if ( '' === $account_id || '' === $api_token ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Cloudflare account ID and API token must be configured before running Apply.', 'nvoos-saas-controller' )
			);
		}
		return new self( $account_id, $api_token );
	}

	/**
	 * Create a D1 database.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Database name (matches Cloudflare's D1 naming rules).
	 * @return array|WP_Error Normalised `[ 'uuid' => …, 'name' => … ]` on success.
	 */
	public function create_d1_database( $name ) {
		$name = (string) $name;
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'D1 database name is required.', 'nvoos-saas-controller' ) );
		}

		$result = $this->post(
			'/accounts/' . rawurlencode( $this->account_id ) . '/d1/database',
			array( 'name' => $name ),
			'create_d1_database',
			$name
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'uuid' => isset( $result['uuid'] ) ? (string) $result['uuid'] : '',
			'name' => isset( $result['name'] ) ? (string) $result['name'] : $name,
		);
	}

	/**
	 * Create a KV namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string $title Namespace title.
	 * @return array|WP_Error Normalised `[ 'id' => …, 'title' => … ]` on success.
	 */
	public function create_kv_namespace( $title ) {
		$title = (string) $title;
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'KV namespace title is required.', 'nvoos-saas-controller' ) );
		}

		$result = $this->post(
			'/accounts/' . rawurlencode( $this->account_id ) . '/storage/kv/namespaces',
			array( 'title' => $title ),
			'create_kv_namespace',
			$title
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'    => isset( $result['id'] ) ? (string) $result['id'] : '',
			'title' => isset( $result['title'] ) ? (string) $result['title'] : $title,
		);
	}

	/**
	 * Create an AI Gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug Gateway slug.
	 * @return array|WP_Error Normalised `[ 'id' => …, 'slug' => … ]` on success.
	 */
	public function create_ai_gateway( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'AI Gateway slug is required.', 'nvoos-saas-controller' ) );
		}

		$result = $this->post(
			'/accounts/' . rawurlencode( $this->account_id ) . '/ai-gateway/gateways',
			array(
				'id'                       => $slug,
				'cache_invalidate_on_update' => false,
				'cache_ttl'                => 0,
				'collect_logs'             => true,
				'rate_limiting_interval'   => 0,
				'rate_limiting_limit'      => 0,
				'rate_limiting_technique'  => 'fixed',
			),
			'create_ai_gateway',
			$slug
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'   => isset( $result['id'] ) ? (string) $result['id'] : $slug,
			'slug' => isset( $result['slug'] ) ? (string) $result['slug'] : $slug,
		);
	}

	/**
	 * Issue a single POST request, parse the Cloudflare envelope, and record
	 * exactly one audit-log entry regardless of outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   API path (must start with `/`).
	 * @param array  $body   Request body (will be JSON-encoded).
	 * @param string $action Audit-log `action` verb (e.g. `create_d1_database`).
	 * @param string $target Audit-log `target` (e.g. resource name being created).
	 * @return array|WP_Error
	 */
	protected function post( $path, array $body, $action, $target ) {
		$started_us = microtime( true );
		$response   = wp_remote_post(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->record_audit( $action, $target, $result, $started_us );
		return $result;
	}

	/**
	 * Decode the wp_remote_post response into the Cloudflare `result` array
	 * or a `WP_Error`.
	 *
	 * @since 0.1.0
	 *
	 * @param array|WP_Error $response Raw `wp_remote_post` return.
	 * @param string         $path     Request path (for error messages).
	 * @return array|WP_Error
	 */
	protected function parse_response( $response, $path ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || ! is_array( $json ) ) {
			$message = sprintf(
				/* translators: 1: HTTP status, 2: path */
				__( 'Cloudflare API returned HTTP %1$d for %2$s.', 'nvoos-saas-controller' ),
				$status,
				$path
			);
			$code = 'cloudflare_http_' . $status;
			if ( is_array( $json ) && ! empty( $json['errors'][0]['message'] ) ) {
				$message .= ' ' . wp_strip_all_tags( (string) $json['errors'][0]['message'] );
				if ( ! empty( $json['errors'][0]['code'] ) ) {
					$code = 'cloudflare_' . sanitize_key( (string) $json['errors'][0]['code'] );
				}
			}
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		if ( empty( $json['success'] ) ) {
			$message = __( 'Cloudflare API reported an unsuccessful response.', 'nvoos-saas-controller' );
			if ( ! empty( $json['errors'][0]['message'] ) ) {
				$message .= ' ' . wp_strip_all_tags( (string) $json['errors'][0]['message'] );
			}
			return new WP_Error( 'cloudflare_unsuccessful', $message );
		}

		return isset( $json['result'] ) && is_array( $json['result'] ) ? $json['result'] : array();
	}

	/**
	 * Record exactly one audit-log entry per mutation attempt.
	 *
	 * Skipped silently when the audit-log class is unavailable (e.g. during
	 * standalone PHPUnit runs that exercise this client in isolation).
	 *
	 * @since 0.1.0
	 *
	 * @param string         $action     Audit-log action verb.
	 * @param string         $target     Resource name / slug being mutated.
	 * @param array|WP_Error $result     Parsed result.
	 * @param float          $started_us `microtime(true)` value at request start.
	 * @return void
	 */
	protected function record_audit( $action, $target, $result, $started_us ) {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			return;
		}

		$is_error   = is_wp_error( $result );
		$message    = $is_error
			? (string) $result->get_error_message()
			: __( 'Cloudflare resource created.', 'nvoos-saas-controller' );
		$latency_ms = (int) round( ( microtime( true ) - $started_us ) * 1000 );

		NVOOS_SaaS_Controller_Audit_Log::instance()->record(
			array(
				'channel'    => 'cloudflare',
				'action'     => $action,
				'target'     => $target,
				'status'     => $is_error ? 'error' : 'ok',
				'latency_ms' => $latency_ms,
				'message'    => $message,
			)
		);
	}
}
