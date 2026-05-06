<?php
/**
 * Stripe API client for the NV oOS SaaS Controller (Phase 6).
 *
 * Implements just enough of the Stripe API to let the plan generator and
 * apply engine reconcile a desired catalogue of products and prices:
 *
 *   • GET  /v1/products?ids[]=…   — match desired products by id.
 *   • POST /v1/products           — idempotent create (operator-supplied id
 *                                    means re-runs return 200 with the
 *                                    existing product instead of 4xx).
 *   • GET  /v1/prices?lookup_keys[]=…
 *                                  — match desired prices by lookup_key.
 *   • POST /v1/prices             — idempotent create via `Idempotency-Key`
 *                                    header (Stripe's documented
 *                                    deterministic-replay mechanism).
 *
 * Auth: HTTP Basic with the secret key as the username (Stripe's
 * documented scheme). Bodies are `application/x-www-form-urlencoded` —
 * Stripe does not accept JSON for create/update calls.
 *
 * Every mutation records exactly one entry on the `stripe` channel of
 * {@see NVOOS_SaaS_Controller_Audit_Log}; list calls are not audited
 * (they are read-only and run on every plan).
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe API client (read + write).
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Stripe_Client {

	/**
	 * Stripe API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.stripe.com/v1';

	/**
	 * Per-request timeout, seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Stripe secret key (sk_live_… / sk_test_…).
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $secret_key Stripe secret key.
	 */
	public function __construct( $secret_key ) {
		$this->secret_key = (string) $secret_key;
	}

	/**
	 * Build a client from the credential store.
	 *
	 * Returns null (not WP_Error) when the secret key is unset — the plan
	 * generator treats a missing Stripe credential as "operator hasn't
	 * opted in to the Stripe surface" and silently skips the section.
	 *
	 * @since 0.1.0
	 *
	 * @return self|null
	 */
	public static function from_credential_store() {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Credential_Store' ) ) {
			return null;
		}
		$creds      = NVOOS_SaaS_Controller_Credential_Store::instance()->get_all();
		$secret_key = isset( $creds['stripe_secret_key'] ) ? (string) $creds['stripe_secret_key'] : '';
		if ( '' === $secret_key ) {
			return null;
		}
		return new self( $secret_key );
	}

	/**
	 * List products by id. Returns the live products that exist in Stripe,
	 * keyed by id.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $ids List of operator-supplied ids to look up.
	 * @return array<string,array>|WP_Error
	 */
	public function list_products( array $ids ) {
		$ids = array_values(
			array_filter(
				array_map( 'strval', $ids ),
				static function ( $id ) {
					return '' !== $id;
				}
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}

		$query = array();
		foreach ( $ids as $id ) {
			$query[] = 'ids[]=' . rawurlencode( $id );
		}
		$path = '/products?' . implode( '&', $query ) . '&limit=100';

		$result = $this->get( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out  = array();
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$id         = (string) $row['id'];
			$out[ $id ] = array(
				'id'          => $id,
				'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
				'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
				'active'      => ! empty( $row['active'] ),
			);
		}
		return $out;
	}

	/**
	 * List prices by lookup_key.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $lookup_keys Operator-supplied lookup keys.
	 * @return array<string,array>|WP_Error Keyed by lookup_key.
	 */
	public function list_prices_by_lookup_keys( array $lookup_keys ) {
		$lookup_keys = array_values(
			array_filter(
				array_map( 'strval', $lookup_keys ),
				static function ( $k ) {
					return '' !== $k;
				}
			)
		);
		if ( empty( $lookup_keys ) ) {
			return array();
		}

		$query = array();
		foreach ( $lookup_keys as $k ) {
			$query[] = 'lookup_keys[]=' . rawurlencode( $k );
		}
		$path = '/prices?' . implode( '&', $query ) . '&limit=100';

		$result = $this->get( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out  = array();
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['lookup_key'] ) ) {
				continue;
			}
			$key         = (string) $row['lookup_key'];
			$out[ $key ] = array(
				'id'          => isset( $row['id'] ) ? (string) $row['id'] : '',
				'lookup_key'  => $key,
				'product'     => isset( $row['product'] ) ? (string) $row['product'] : '',
				'currency'    => isset( $row['currency'] ) ? (string) $row['currency'] : '',
				'unit_amount' => isset( $row['unit_amount'] ) ? (int) $row['unit_amount'] : 0,
				'recurring'   => isset( $row['recurring'] ) && is_array( $row['recurring'] ) ? $row['recurring'] : null,
				'active'      => ! empty( $row['active'] ),
			);
		}
		return $out;
	}

	/**
	 * Idempotently create a product.
	 *
	 * Stripe accepts a client-supplied `id` on `POST /v1/products`, so a
	 * second call with the same id is a no-op (returns the existing
	 * product). Cheaper and more deterministic than a list-then-create
	 * race window, so we still attempt the create even when the plan
	 * already saw the product.
	 *
	 * @since 0.1.0
	 *
	 * @param array $product Product fields (`id`, `name`, optional `description`).
	 * @return array|WP_Error Normalised product row.
	 */
	public function create_product( array $product ) {
		$id   = isset( $product['id'] ) ? (string) $product['id'] : '';
		$name = isset( $product['name'] ) ? (string) $product['name'] : '';
		if ( '' === $id || '' === $name ) {
			return new WP_Error(
				'invalid_product',
				__( 'Stripe product requires both id and name.', 'nvoos-saas-controller' )
			);
		}

		$body = array(
			'id'   => $id,
			'name' => $name,
		);
		if ( ! empty( $product['description'] ) ) {
			$body['description'] = (string) $product['description'];
		}

		$idempotency_key = 'nvoos-product-' . $id;
		$result          = $this->post( '/products', $body, 'create_stripe_product', $id, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'   => isset( $result['id'] ) ? (string) $result['id'] : $id,
			'name' => isset( $result['name'] ) ? (string) $result['name'] : $name,
		);
	}

	/**
	 * Idempotently create a price.
	 *
	 * Unlike products, Stripe does not accept a client-supplied id on
	 * prices; instead we use the documented `Idempotency-Key` header so a
	 * second call with the same key returns the original price row
	 * instead of creating a duplicate. The idempotency key is derived
	 * from the desired-config tuple so an operator-side change to any
	 * field (currency, amount, …) becomes a *new* idempotency key, and
	 * therefore a new price.
	 *
	 * @since 0.1.0
	 *
	 * @param array $price Price fields.
	 * @return array|WP_Error
	 */
	public function create_price( array $price ) {
		$lookup_key = isset( $price['lookup_key'] ) ? (string) $price['lookup_key'] : '';
		$product_id = isset( $price['product_id'] ) ? (string) $price['product_id'] : '';
		$currency   = isset( $price['currency'] ) ? (string) $price['currency'] : '';
		$amount     = isset( $price['unit_amount'] ) ? (int) $price['unit_amount'] : 0;
		if ( '' === $lookup_key || '' === $product_id || '' === $currency || $amount <= 0 ) {
			return new WP_Error(
				'invalid_price',
				__( 'Stripe price requires lookup_key, product_id, currency, and a positive unit_amount.', 'nvoos-saas-controller' )
			);
		}

		$body = array(
			'lookup_key'          => $lookup_key,
			'product'             => $product_id,
			'currency'            => $currency,
			'unit_amount'         => (string) $amount,
			'transfer_lookup_key' => 'true',
		);
		if ( ! empty( $price['recurring_interval'] ) ) {
			$body['recurring[interval]'] = (string) $price['recurring_interval'];
		}
		if ( ! empty( $price['nickname'] ) ) {
			$body['nickname'] = (string) $price['nickname'];
		}

		// Bake the desired tuple into the idempotency key so the upstream
		// behaves correctly when the operator edits a price field after a
		// failed first attempt.
		$idempotency_key = 'nvoos-price-' . hash( 'sha256', wp_json_encode( $body ) );

		$result = $this->post( '/prices', $body, 'create_stripe_price', $lookup_key, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'         => isset( $result['id'] ) ? (string) $result['id'] : '',
			'lookup_key' => $lookup_key,
			'product'    => isset( $result['product'] ) ? (string) $result['product'] : $product_id,
		);
	}

	/**
	 * Archive a Stripe product (Phase 10 — orphan cleanup).
	 *
	 * Stripe never permanently deletes products that have ever had a price
	 * or transaction attached; the documented "delete" semantics are
	 * actually `POST /v1/products/{id}` with `active=false`. We use the
	 * same call here because the orphan workflow is reconcile-driven:
	 * archived products do not appear in `/v1/products` listings (live
	 * mode is `active=true` by default), which is what `list_products()`
	 * relies on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Stripe product id (`prod_…`).
	 * @return array|WP_Error `[ 'id' => …, 'active' => false ]` on success.
	 */
	public function archive_product( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return new WP_Error(
				'invalid_product',
				__( 'Stripe product id is required to archive.', 'nvoos-saas-controller' )
			);
		}

		$body            = array( 'active' => 'false' );
		$idempotency_key = 'nvoos-product-archive-' . $id;
		$result          = $this->post( '/products/' . rawurlencode( $id ), $body, 'archive_stripe_product', $id, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'     => isset( $result['id'] ) ? (string) $result['id'] : $id,
			'active' => ! empty( $result['active'] ),
		);
	}

	/**
	 * Archive a Stripe price (Phase 10 — orphan cleanup).
	 *
	 * Stripe forbids deleting prices for the same reason as products
	 * (history immutability). The idiomatic equivalent is
	 * `POST /v1/prices/{id}` with `active=false`, which removes the price
	 * from active listings without breaking historical invoices.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Stripe price id (`price_…`).
	 * @return array|WP_Error
	 */
	public function archive_price( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return new WP_Error(
				'invalid_price',
				__( 'Stripe price id is required to archive.', 'nvoos-saas-controller' )
			);
		}

		$body            = array( 'active' => 'false' );
		$idempotency_key = 'nvoos-price-archive-' . $id;
		$result          = $this->post( '/prices/' . rawurlencode( $id ), $body, 'archive_stripe_price', $id, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'     => isset( $result['id'] ) ? (string) $result['id'] : $id,
			'active' => ! empty( $result['active'] ),
		);
	}

	/**
	 * Issue a single GET request and parse the Stripe envelope.
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
	 * Issue a single form-encoded POST request, parse the Stripe envelope,
	 * and record exactly one audit-log entry on the `stripe` channel.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path            API path.
	 * @param array  $body            Form fields (string-only values).
	 * @param string $action          Audit-log action verb.
	 * @param string $target          Audit-log target.
	 * @param string $idempotency_key Stripe idempotency key.
	 * @return array|WP_Error
	 */
	protected function post( $path, array $body, $action, $target, $idempotency_key ) {
		$started_us = microtime( true );

		$headers                    = $this->auth_headers();
		$headers['Content-Type']    = 'application/x-www-form-urlencoded';
		$headers['Idempotency-Key'] = (string) $idempotency_key;

		$response = wp_remote_post(
			self::BASE_URL . $path,
			array(
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
				'headers'   => $headers,
				'body'      => $this->build_form_body( $body ),
			)
		);

		$result = $this->parse_response( $response, $path );
		$this->record_audit( $action, $target, $result, $started_us );
		return $result;
	}

	/**
	 * Build a Stripe-compatible form body. Stripe encodes nested fields
	 * via bracket notation (`recurring[interval]=month`), which the caller
	 * has already flattened — we just need URL-encoding.
	 *
	 * @since 0.1.0
	 *
	 * @param array $body Flat key→value pairs.
	 * @return string
	 */
	protected function build_form_body( array $body ) {
		$parts = array();
		foreach ( $body as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $parts );
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
			// HTTP Basic auth with the secret key as the username (Stripe's
			// documented scheme); never logs.
			'Authorization' => 'Basic ' . base64_encode( $this->secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Decode a `wp_remote_*` return into a Stripe `data` array or a
	 * `WP_Error`. Stripe wraps lists in `{ object: 'list', data: [ … ] }`
	 * and resources in their own object — both are returned verbatim and
	 * the caller picks `data` when needed.
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
				__( 'Stripe API returned HTTP %1$d for %2$s.', 'nvoos-saas-controller' ),
				$status,
				$path
			);
			$code = 'stripe_http_' . $status;
			if ( is_array( $json ) && ! empty( $json['error']['message'] ) ) {
				$message .= ' ' . wp_strip_all_tags( (string) $json['error']['message'] );
				if ( ! empty( $json['error']['code'] ) ) {
					$code = 'stripe_' . sanitize_key( (string) $json['error']['code'] );
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
	 * @param string         $target     Resource id / lookup key.
	 * @param array|WP_Error $result     Parsed result.
	 * @param float          $started_us `microtime(true)` value at start.
	 * @return void
	 */
	protected function record_audit( $action, $target, $result, $started_us ) {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			return;
		}

		$is_error   = is_wp_error( $result );
		$message    = $is_error
			? (string) $result->get_error_message()
			: __( 'Stripe resource provisioned.', 'nvoos-saas-controller' );
		$latency_ms = (int) round( ( microtime( true ) - $started_us ) * 1000 );

		NVOOS_SaaS_Controller_Audit_Log::instance()->record(
			array(
				'channel'    => 'stripe',
				'action'     => $action,
				'target'     => $target,
				'status'     => $is_error ? 'error' : 'ok',
				'latency_ms' => $latency_ms,
				'message'    => $message,
			)
		);
	}
}
