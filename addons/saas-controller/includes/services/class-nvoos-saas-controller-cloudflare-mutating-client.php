<?php
/**
 * Mutating Cloudflare API client for the NV oOS SaaS Controller
 * (Phases 5b + 5d).
 *
 * Companion to the read-only {@see NVOOS_SaaS_Controller_Cloudflare_Client}.
 * This class exposes only the writes the Apply step needs:
 *
 *   • POST /accounts/{account_id}/d1/database           — create a D1 database.
 *   • POST /accounts/{account_id}/storage/kv/namespaces — create a KV namespace.
 *   • POST /accounts/{account_id}/ai-gateway/gateways   — create an AI Gateway.
 *   • PUT  /accounts/{account_id}/workers/scripts/{name} — upload a Worker
 *     script (module-worker, multipart/form-data; Phase 5d).
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
		$store      = NVOOS_SaaS_Controller_Credential_Store::instance();
		$creds      = $store->get_all();
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
				'id'                         => $slug,
				'cache_invalidate_on_update' => false,
				'cache_ttl'                  => 0,
				'collect_logs'               => true,
				'rate_limiting_interval'     => 0,
				'rate_limiting_limit'        => 0,
				'rate_limiting_technique'    => 'fixed',
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
	 * Upload a Worker script (Phase 5d).
	 *
	 * Performs a multipart/form-data PUT against
	 * `/accounts/{id}/workers/scripts/{name}` with two parts:
	 *
	 *  • `metadata` — application/json describing the module entrypoint,
	 *    compatibility date, and the bindings (D1, KV, AI Gateway, …) the
	 *    operator's deployment config requires.
	 *  • `index.js` — application/javascript+module body — the built ESM
	 *    Worker bundle (`worker/dist/index.js`).
	 *
	 * Cloudflare returns the saved script descriptor including an `etag`
	 * which we surface so the caller can persist it for the drift detector
	 * (see {@see NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION}).
	 *
	 * Like every other write on this client, exactly one audit-log entry
	 * is recorded — success or failure.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name        Worker script slug.
	 * @param string $script_body Raw bytes of `worker/dist/index.js`.
	 * @param array  $metadata    Module-worker metadata (must include
	 *                            `main_module`; may include `compatibility_date`
	 *                            and `bindings[]`).
	 * @return array|WP_Error `[ 'id' => …, 'etag' => …, 'modified_on' => …, 'size' => N ]`.
	 */
	public function upload_worker_script( $name, $script_body, array $metadata ) {
		$slug = (string) $name;
		if ( '' === $slug ) {
			return new WP_Error(
				'invalid_name',
				__( 'Worker script name is required.', 'nvoos-saas-controller' )
			);
		}
		if ( ! is_string( $script_body ) || '' === $script_body ) {
			return new WP_Error(
				'empty_script',
				__( 'Worker script body is empty — has the build pipeline produced worker/dist/index.js?', 'nvoos-saas-controller' )
			);
		}
		if ( empty( $metadata['main_module'] ) ) {
			$metadata['main_module'] = 'index.js';
		}
		$module_filename = (string) $metadata['main_module'];

		$path     = '/accounts/' . rawurlencode( $this->account_id ) . '/workers/scripts/' . rawurlencode( $slug );
		$boundary = '----nvoos-saas-' . wp_generate_password( 24, false, false );

		$body = $this->build_multipart_body(
			$boundary,
			array(
				array(
					'name'         => 'metadata',
					'filename'     => 'metadata.json',
					'content_type' => 'application/json',
					'body'         => wp_json_encode( $metadata ),
				),
				array(
					'name'         => $module_filename,
					'filename'     => $module_filename,
					'content_type' => 'application/javascript+module',
					'body'         => $script_body,
				),
			)
		);

		$started_us = microtime( true );
		$response   = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'    => 'PUT',
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'multipart/form-data; boundary="' . $boundary . '"',
				),
				'body'      => $body,
			)
		);

		$result = $this->parse_response( $response, $path );

		// Cloudflare returns the saved script descriptor; on success we
		// also surface the response `etag` header (the drift detector's
		// preferred fingerprint).
		if ( ! is_wp_error( $result ) ) {
			$etag   = is_array( $response ) ? (string) wp_remote_retrieve_header( $response, 'etag' ) : '';
			$result = array(
				'id'          => isset( $result['id'] ) ? (string) $result['id'] : $slug,
				'etag'        => trim( $etag, '"' ),
				'modified_on' => isset( $result['modified_on'] ) ? (string) $result['modified_on'] : '',
				'size'        => strlen( $script_body ),
			);
		}

		$this->record_audit( 'upload_worker_script', $slug, $result, $started_us );
		return $result;
	}

	/**
	 * Delete a D1 database by uuid (Phase 10 — orphan cleanup).
	 *
	 * Cloudflare's D1 API exposes destructive deletion at
	 * `DELETE /accounts/{id}/d1/database/{uuid}`. The uuid is mandatory
	 * (the friendly name alone is not sufficient — Cloudflare addresses
	 * the row by uuid) and is provided by the plan generator's orphan row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid Cloudflare D1 database uuid.
	 * @param string $name Friendly database name (for audit-log target only).
	 * @return array|WP_Error `[ 'uuid' => …, 'name' => … ]` on success.
	 */
	public function delete_d1_database( $uuid, $name = '' ) {
		$uuid = (string) $uuid;
		if ( '' === $uuid ) {
			return new WP_Error( 'invalid_uuid', __( 'D1 database uuid is required to delete.', 'nvoos-saas-controller' ) );
		}
		$path   = '/accounts/' . rawurlencode( $this->account_id ) . '/d1/database/' . rawurlencode( $uuid );
		$target = '' !== (string) $name ? (string) $name : $uuid;
		$result = $this->delete( $path, 'delete_d1_database', $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'uuid' => $uuid,
			'name' => '' !== (string) $name ? (string) $name : '',
		);
	}

	/**
	 * Delete a KV namespace by id (Phase 10 — orphan cleanup).
	 *
	 * @since 0.1.0
	 *
	 * @param string $namespace_id Cloudflare KV namespace id.
	 * @param string $title        Friendly namespace title (audit target only).
	 * @return array|WP_Error
	 */
	public function delete_kv_namespace( $namespace_id, $title = '' ) {
		$namespace_id = (string) $namespace_id;
		if ( '' === $namespace_id ) {
			return new WP_Error( 'invalid_namespace_id', __( 'KV namespace id is required to delete.', 'nvoos-saas-controller' ) );
		}
		$path   = '/accounts/' . rawurlencode( $this->account_id ) . '/storage/kv/namespaces/' . rawurlencode( $namespace_id );
		$target = '' !== (string) $title ? (string) $title : $namespace_id;
		$result = $this->delete( $path, 'delete_kv_namespace', $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'    => $namespace_id,
			'title' => '' !== (string) $title ? (string) $title : '',
		);
	}

	/**
	 * Delete an AI Gateway by slug (Phase 10 — orphan cleanup).
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug AI Gateway slug.
	 * @return array|WP_Error
	 */
	public function delete_ai_gateway( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'AI Gateway slug is required to delete.', 'nvoos-saas-controller' ) );
		}
		$path   = '/accounts/' . rawurlencode( $this->account_id ) . '/ai-gateway/gateways/' . rawurlencode( $slug );
		$result = $this->delete( $path, 'delete_ai_gateway', $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'slug' => $slug );
	}

	/**
	 * Issue a single DELETE request, parse the Cloudflare envelope, and
	 * record exactly one audit-log entry regardless of outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   API path (must start with `/`).
	 * @param string $action Audit-log `action` verb.
	 * @param string $target Audit-log `target`.
	 * @return array|WP_Error
	 */
	protected function delete( $path, $action, $target ) {
		$started_us = microtime( true );
		$response   = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'    => 'DELETE',
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->record_audit( $action, $target, $result, $started_us );
		return $result;
	}

	/**
	 * Assemble an RFC 7578 multipart/form-data body from a flat list of
	 * parts. Each part is `[ name, filename?, content_type, body ]`.
	 *
	 * Kept tiny and dependency-free on purpose: we only ever use it for
	 * the two-part Worker upload payload, so the more elaborate options
	 * (nested boundaries, transfer-encoding) aren't needed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $boundary Boundary string (without the `--` prefix).
	 * @param array  $parts    List of part descriptors.
	 * @return string
	 */
	protected function build_multipart_body( $boundary, array $parts ) {
		$crlf = "\r\n";
		$out  = '';
		foreach ( $parts as $part ) {
			$name         = isset( $part['name'] ) ? (string) $part['name'] : '';
			$filename     = isset( $part['filename'] ) ? (string) $part['filename'] : '';
			$content_type = isset( $part['content_type'] ) ? (string) $part['content_type'] : 'application/octet-stream';
			$body         = isset( $part['body'] ) ? (string) $part['body'] : '';

			$disposition = 'form-data; name="' . $name . '"';
			if ( '' !== $filename ) {
				$disposition .= '; filename="' . $filename . '"';
			}

			$out .= '--' . $boundary . $crlf;
			$out .= 'Content-Disposition: ' . $disposition . $crlf;
			$out .= 'Content-Type: ' . $content_type . $crlf . $crlf;
			$out .= $body . $crlf;
		}
		$out .= '--' . $boundary . '--' . $crlf;
		return $out;
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

		$is_error = is_wp_error( $result );
		if ( $is_error ) {
			$message = (string) $result->get_error_message();
		} elseif ( 0 === strpos( $action, 'delete_' ) ) {
			$message = __( 'Cloudflare resource deleted.', 'nvoos-saas-controller' );
		} else {
			$message = __( 'Cloudflare resource created.', 'nvoos-saas-controller' );
		}
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
