<?php
/**
 * OpenRouter API client for the NV oOS SaaS Controller (Phase 6).
 *
 * Wraps just enough of the OpenRouter Provisioning API to let the plan
 * generator and apply engine reconcile a desired set of runtime API keys:
 *
 *   • GET  /api/v1/keys       — list runtime keys (matches by `label`).
 *   • POST /api/v1/keys       — create a runtime key bound to a label
 *                                with an optional per-key dollar limit.
 *
 * # Why a separate provisioning credential
 *
 * OpenRouter uses two distinct credential tiers: regular runtime API
 * keys (which authenticate inference calls) and a *provisioning key*
 * (which authenticates `/api/v1/keys`). Only the provisioning key has
 * scope over key management; presenting a regular runtime key to
 * `/api/v1/keys` returns 401. The credential store therefore exposes
 * `openrouter_provisioning_key` as a separate, optional credential —
 * if it is unset, this client returns null from
 * `from_credential_store()` and the plan generator silently skips the
 * OpenRouter section (no spurious error rows for operators who only
 * care about the Cloudflare side of the addon).
 *
 * Every mutation records exactly one entry on the `openrouter` channel
 * of {@see NVOOS_SaaS_Controller_Audit_Log}.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenRouter Provisioning API client.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_OpenRouter_Client {

	/**
	 * OpenRouter API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://openrouter.ai/api/v1';

	/**
	 * Per-request timeout, seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * OpenRouter provisioning key.
	 *
	 * @var string
	 */
	protected $provisioning_key;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provisioning_key Provisioning API key.
	 */
	public function __construct( $provisioning_key ) {
		$this->provisioning_key = (string) $provisioning_key;
	}

	/**
	 * Build a client from the credential store.
	 *
	 * Returns null when the provisioning key is unset — the plan
	 * generator treats that as "operator hasn't opted in to the
	 * OpenRouter surface" and silently skips the section.
	 *
	 * @since 0.1.0
	 *
	 * @return self|null
	 */
	public static function from_credential_store() {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Credential_Store' ) ) {
			return null;
		}
		$creds = NVOOS_SaaS_Controller_Credential_Store::instance()->get_all();
		$key   = isset( $creds['openrouter_provisioning_key'] ) ? (string) $creds['openrouter_provisioning_key'] : '';
		if ( '' === $key ) {
			return null;
		}
		return new self( $key );
	}

	/**
	 * List runtime keys, returning an associative array keyed by label.
	 *
	 * Only the `name` (label), `hash`, `disabled`, and `limit` fields are
	 * surfaced — the actual key value is never returned by this endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,array>|WP_Error
	 */
	public function list_keys() {
		$result = $this->get( '/keys' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out  = array();
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$label         = (string) $row['name'];
			$out[ $label ] = array(
				'label'    => $label,
				'hash'     => isset( $row['hash'] ) ? (string) $row['hash'] : '',
				'disabled' => ! empty( $row['disabled'] ),
				'limit'    => isset( $row['limit'] ) ? (float) $row['limit'] : null,
			);
		}
		return $out;
	}

	/**
	 * Create a new runtime API key bound to a label.
	 *
	 * The plaintext key value is returned only in this response — the
	 * apply engine surfaces it exactly once in its result row so the
	 * operator can copy it out. The addon never persists it.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $label     Human-readable label.
	 * @param float|null $limit_usd Optional per-key dollar budget cap.
	 * @return array|WP_Error `[ 'label' => …, 'hash' => …, 'key' => <plaintext> ]`.
	 */
	public function create_key( $label, $limit_usd = null ) {
		$label = (string) $label;
		if ( '' === $label ) {
			return new WP_Error(
				'invalid_label',
				__( 'OpenRouter key label is required.', 'nvoos-saas-controller' )
			);
		}

		$body = array( 'name' => $label );
		if ( null !== $limit_usd ) {
			$limit = (float) $limit_usd;
			if ( $limit > 0 ) {
				$body['limit'] = $limit;
			}
		}

		$result = $this->post( '/keys', $body, 'create_openrouter_key', $label );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// OpenRouter wraps the created resource under either `data` or
		// directly at the top level depending on response version. Both
		// shapes contain `key` (plaintext) and `data.hash` / `hash`.
		$payload = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		$key     = '';
		if ( ! empty( $result['key'] ) ) {
			$key = (string) $result['key'];
		} elseif ( ! empty( $payload['key'] ) ) {
			$key = (string) $payload['key'];
		}

		return array(
			'label' => isset( $payload['name'] ) ? (string) $payload['name'] : $label,
			'hash'  => isset( $payload['hash'] ) ? (string) $payload['hash'] : '',
			'key'   => $key,
		);
	}

	/**
	 * Delete a runtime API key by its hash (Phase 10 — orphan cleanup).
	 *
	 * OpenRouter addresses keys by their server-side hash on the
	 * `/api/v1/keys/{hash}` URL. The `list_keys()` response only exposes
	 * `hash` (never the plaintext key value), which is exactly what the
	 * orphan-row caller has in hand.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hash  Key hash returned by `list_keys()`.
	 * @param string $label Friendly label (used as audit-log target only).
	 * @return array|WP_Error `[ 'hash' => …, 'label' => … ]` on success.
	 */
	public function delete_key( $hash, $label = '' ) {
		$hash = (string) $hash;
		if ( '' === $hash ) {
			return new WP_Error(
				'invalid_hash',
				__( 'OpenRouter key hash is required to delete.', 'nvoos-saas-controller' )
			);
		}

		$target = '' !== (string) $label ? (string) $label : $hash;
		$result = $this->delete_request( '/keys/' . rawurlencode( $hash ), 'delete_openrouter_key', $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'hash'  => $hash,
			'label' => '' !== (string) $label ? (string) $label : '',
		);
	}

	/**
	 * Issue a single GET request and parse the OpenRouter envelope.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path API path (must start with `/`).
	 * @return array|WP_Error
	 */
	protected function get( $path ) {
		$response = wp_remote_get(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => $this->auth_headers(),
			)
		);
		return $this->parse_response( $response, $path );
	}

	/**
	 * Issue a single JSON POST and record exactly one audit-log entry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   API path.
	 * @param array  $body   JSON body.
	 * @param string $action Audit-log action verb.
	 * @param string $target Audit-log target.
	 * @return array|WP_Error
	 */
	protected function post( $path, array $body, $action, $target ) {
		$started_us = microtime( true );

		$headers                 = $this->auth_headers();
		$headers['Content-Type'] = 'application/json';

		$response = wp_remote_post(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => $headers,
				'body'      => wp_json_encode( $body ),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->record_audit( $action, $target, $result, $started_us );
		return $result;
	}

	/**
	 * Issue a single DELETE request and record exactly one audit-log entry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   API path.
	 * @param string $action Audit-log action verb.
	 * @param string $target Audit-log target.
	 * @return array|WP_Error
	 */
	protected function delete_request( $path, $action, $target ) {
		$started_us = microtime( true );
		$response   = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'    => 'DELETE',
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => $this->auth_headers(),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->record_audit( $action, $target, $result, $started_us );
		return $result;
	}

	/**
	 * Auth headers shared by every request.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string>
	 */
	protected function auth_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->provisioning_key,
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Decode a `wp_remote_*` return into the OpenRouter response or a
	 * `WP_Error`.
	 *
	 * @since 0.1.0
	 *
	 * @param array|WP_Error $response Raw `wp_remote_*` return.
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

		if ( $status < 200 || $status >= 300 ) {
			$message = sprintf(
				/* translators: 1: HTTP status, 2: path */
				__( 'OpenRouter API returned HTTP %1$d for %2$s.', 'nvoos-saas-controller' ),
				$status,
				$path
			);
			$code = 'openrouter_http_' . $status;
			if ( is_array( $json ) && ! empty( $json['error']['message'] ) ) {
				$message .= ' ' . wp_strip_all_tags( (string) $json['error']['message'] );
				if ( ! empty( $json['error']['code'] ) ) {
					$code = 'openrouter_' . sanitize_key( (string) $json['error']['code'] );
				}
			}
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Record one audit-log entry per mutation attempt.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $action     Audit-log action verb.
	 * @param string         $target     Key label.
	 * @param array|WP_Error $result     Parsed result.
	 * @param float          $started_us `microtime(true)` value at start.
	 * @return void
	 */
	protected function record_audit( $action, $target, $result, $started_us ) {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			return;
		}

		$is_error = is_wp_error( $result );
		if ( $is_error ) {
			$message = (string) $result->get_error_message();
		} elseif ( 'delete_openrouter_key' === $action ) {
			$message = __( 'OpenRouter runtime key deleted.', 'nvoos-saas-controller' );
		} else {
			$message = __( 'OpenRouter runtime key created.', 'nvoos-saas-controller' );
		}
		$latency_ms = (int) round( ( microtime( true ) - $started_us ) * 1000 );

		NVOOS_SaaS_Controller_Audit_Log::instance()->record(
			array(
				'channel'    => 'openrouter',
				'action'     => $action,
				'target'     => $target,
				'status'     => $is_error ? 'error' : 'ok',
				'latency_ms' => $latency_ms,
				'message'    => $message,
			)
		);
	}
}
