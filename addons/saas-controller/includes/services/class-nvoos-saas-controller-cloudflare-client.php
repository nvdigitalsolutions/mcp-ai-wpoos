<?php
/**
 * Read-only Cloudflare API client for the NV oOS SaaS Controller.
 *
 * This class wraps just enough of the Cloudflare API to let the Phase 4
 * plan generator answer "what's currently deployed?" — it intentionally
 * exposes no mutation methods. Phase 5 (Apply) will add a separate
 * mutating client class behind a HITL approval gate.
 *
 * Endpoints used:
 *   • GET /accounts/{account_id}/d1/database
 *   • GET /accounts/{account_id}/storage/kv/namespaces
 *   • GET /accounts/{account_id}/workers/scripts
 *   • GET /accounts/{account_id}/ai-gateway/gateways
 *
 * Each returns a Cloudflare envelope `{ result, success, errors, … }`.
 * On non-2xx we return a `WP_Error` whose code matches the Cloudflare
 * top-level error code where available, so callers (the plan generator)
 * can record partial failures without throwing.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare API client (read-only).
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Cloudflare_Client {

	/**
	 * Cloudflare API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.cloudflare.com/client/v4';

	/**
	 * Per-request timeout, seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

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
	 * @param string $account_id Cloudflare account ID (32-char hex typical).
	 * @param string $api_token  Cloudflare API token with at least Read scope on
	 *                           Account, Workers, D1, KV, and AI Gateway.
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
	 * @return self|WP_Error WP_Error('missing_credentials') if either credential is unset.
	 */
	public static function from_credential_store( $account_override = null ) {
		$store      = NVOOS_SaaS_Controller_Credential_Store::instance();
		$creds      = $store->get_all();
		$account_id = $account_override
			? (string) $account_override
			: ( isset( $creds['cloudflare_account_id'] ) ? (string) $creds['cloudflare_account_id'] : '' );
		$api_token  = isset( $creds['cloudflare_api_token'] ) ? (string) $creds['cloudflare_api_token'] : '';
		if ( '' === $account_id || '' === $api_token ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Cloudflare account ID and API token must be configured before running plan.', 'nvoos-saas-controller' )
			);
		}
		return new self( $account_id, $api_token );
	}

	/**
	 * GET /accounts/{account_id}/d1/database
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array{uuid:string,name:string}>|WP_Error
	 */
	public function list_d1_databases() {
		$response = $this->get( '/accounts/' . rawurlencode( $this->account_id ) . '/d1/database' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( (array) $response as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$uuid = isset( $row['uuid'] ) ? (string) $row['uuid'] : '';
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			if ( '' !== $uuid && '' !== $name ) {
				$out[] = array(
					'uuid' => $uuid,
					'name' => $name,
				);
			}
		}
		return $out;
	}

	/**
	 * GET /accounts/{account_id}/storage/kv/namespaces
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array{id:string,title:string}>|WP_Error
	 */
	public function list_kv_namespaces() {
		$response = $this->get( '/accounts/' . rawurlencode( $this->account_id ) . '/storage/kv/namespaces' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( (array) $response as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = isset( $row['id'] ) ? (string) $row['id'] : '';
			$title = isset( $row['title'] ) ? (string) $row['title'] : '';
			if ( '' !== $id && '' !== $title ) {
				$out[] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
		}
		return $out;
	}

	/**
	 * GET /accounts/{account_id}/workers/scripts
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array{id:string,modified_on:string}>|WP_Error
	 */
	public function list_workers() {
		$response = $this->get( '/accounts/' . rawurlencode( $this->account_id ) . '/workers/scripts' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( (array) $response as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id ) {
				$out[] = array(
					'id'          => $id,
					'modified_on' => isset( $row['modified_on'] ) ? (string) $row['modified_on'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * GET /accounts/{account_id}/workers/scripts/{name}
	 *
	 * Fetches the deployed Worker script body and the Cloudflare-supplied
	 * `etag` header (which Cloudflare derives from the uploaded script
	 * payload and is therefore the most reliable cross-deploy fingerprint).
	 * Used by the drift detector (Phase 5c) to compare the deployed code
	 * against the addon's pinned `worker/dist/index.js` checksum.
	 *
	 * Unlike the other read-only endpoints this one does **not** return a
	 * Cloudflare JSON envelope on success — the response body **is** the
	 * Worker script (raw JS for service-worker format, multipart/form-data
	 * for module-worker format). Errors still return JSON envelopes, so we
	 * only attempt envelope parsing on non-2xx responses.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Worker script name (slug).
	 * @return array{body:string,etag:string,modified_on:string,size:int}|WP_Error
	 */
	public function get_worker_script( $name ) {
		$slug = (string) $name;
		if ( '' === $slug ) {
			return new WP_Error(
				'missing_worker_name',
				__( 'A non-empty Worker script name is required.', 'nvoos-saas-controller' )
			);
		}

		$path       = '/accounts/' . rawurlencode( $this->account_id ) . '/workers/scripts/' . rawurlencode( $slug );
		$started_us = microtime( true );

		$response = wp_remote_get(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_token,
				),
			)
		);

		$result = $this->parse_worker_script_response( $response, $path );
		$this->maybe_record_audit( $path, $result, $started_us );
		return $result;
	}

	/**
	 * Decode the raw `wp_remote_get` response from the Worker-script
	 * endpoint. On 2xx the body is the Worker source itself (not an
	 * envelope), so we return `{body, etag, modified_on, size}`. On non-2xx
	 * we attempt the standard Cloudflare error envelope parse so the
	 * drift detector gets the same `WP_Error` shape as the other endpoints.
	 *
	 * @since 0.1.0
	 *
	 * @param array|WP_Error $response Raw response.
	 * @param string         $path     Request path (for error messages).
	 * @return array|WP_Error
	 */
	protected function parse_worker_script_response( $response, $path ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$json    = json_decode( $body, true );
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

		$etag        = (string) wp_remote_retrieve_header( $response, 'etag' );
		$modified_on = (string) wp_remote_retrieve_header( $response, 'last-modified' );

		return array(
			'body'        => $body,
			'etag'        => trim( $etag, '"' ),
			'modified_on' => $modified_on,
			'size'        => strlen( $body ),
		);
	}

	/**
	 * GET /accounts/{account_id}/ai-gateway/gateways
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array{id:string,slug:string}>|WP_Error
	 */
	public function list_ai_gateways() {
		$response = $this->get( '/accounts/' . rawurlencode( $this->account_id ) . '/ai-gateway/gateways' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( (array) $response as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = isset( $row['id'] ) ? (string) $row['id'] : '';
			$slug = isset( $row['slug'] ) ? (string) $row['slug'] : ( isset( $row['name'] ) ? (string) $row['name'] : '' );
			if ( '' !== $slug ) {
				$out[] = array(
					'id'   => $id,
					'slug' => $slug,
				);
			}
		}
		return $out;
	}

	/**
	 * Issue a single GET request and return the Cloudflare `result` array.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path API path (must start with `/`).
	 * @return array|WP_Error
	 */
	protected function get( $path ) {
		$started_us = microtime( true );
		$response   = wp_remote_get(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->maybe_record_audit( $path, $result, $started_us );
		return $result;
	}

	/**
	 * Decode the wp_remote_get response into either the Cloudflare `result`
	 * array or a `WP_Error`.
	 *
	 * Extracted from {@see get()} so the request and the bookkeeping stay
	 * separable and testable.
	 *
	 * @since 0.1.0
	 *
	 * @param array|WP_Error $response Raw `wp_remote_get` return.
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
	 * Record one audit-log entry per Cloudflare API call.
	 *
	 * Skipped when the audit-log class is not loaded (e.g. in PHPUnit
	 * tests that exercise the client in isolation). The action is derived
	 * from the URL path so the operator sees `list_d1_databases` instead
	 * of a raw URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $path       Request path.
	 * @param array|WP_Error $result     Parsed result.
	 * @param float          $started_us Microsecond timestamp from `microtime(true)` at request start.
	 * @return void
	 */
	protected function maybe_record_audit( $path, $result, $started_us ) {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			return;
		}

		$action = 'cloudflare_api';
		if ( false !== strpos( $path, '/d1/database' ) ) {
			$action = 'list_d1_databases';
		} elseif ( false !== strpos( $path, '/storage/kv/namespaces' ) ) {
			$action = 'list_kv_namespaces';
		} elseif ( false !== strpos( $path, '/workers/scripts/' ) ) {
			$action = 'get_worker_script';
		} elseif ( false !== strpos( $path, '/workers/scripts' ) ) {
			$action = 'list_workers';
		} elseif ( false !== strpos( $path, '/ai-gateway/gateways' ) ) {
			$action = 'list_ai_gateways';
		}

		$is_error = is_wp_error( $result );
		if ( $is_error ) {
			$message = (string) $result->get_error_message();
		} elseif ( 'get_worker_script' === $action && is_array( $result ) && isset( $result['size'] ) ) {
			$message = sprintf(
				/* translators: %d: script size in bytes */
				__( 'Worker script fetched (%d bytes).', 'nvoos-saas-controller' ),
				(int) $result['size']
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of items returned */
				__( '%d item(s) returned.', 'nvoos-saas-controller' ),
				is_array( $result ) ? count( $result ) : 0
			);
		}
		$latency_ms = (int) round( ( microtime( true ) - $started_us ) * 1000 );

		NVOOS_SaaS_Controller_Audit_Log::instance()->record(
			array(
				'channel'    => 'cloudflare',
				'action'     => $action,
				'target'     => $this->account_id,
				'status'     => $is_error ? 'error' : 'ok',
				'latency_ms' => $latency_ms,
				'message'    => $message,
			)
		);
	}
}
