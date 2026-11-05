<?php
/**
 * REST — the form submission bridge (Proposal 114 §3.9).
 *
 * One public route: POST /nvoos-forge/v1/forms/submit. The forged static
 * site rebuilds source forms as design components and posts JSON here; this
 * controller runs the origin/rate/spam gates, then dispatches the payload to
 * the NATIVE handler — JetFormBuilder's form processor or Elementor Pro's
 * form ajax handler — so validation, storage, mail, and post-submit actions
 * behave exactly like a WordPress-rendered submission. No handler → typed
 * 501; unknown form → 404; a caught bot → silent 200 discard. The no-JS
 * floor is an ordinary form-encoded POST that redirects back to the static
 * origin with ?form=ok|error.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge\Rest;

use NvoosForgeBridge\Settings;

/**
 * Forms route.
 */
class Forms extends \WP_REST_Controller {

	/**
	 * Namespace.
	 */
	private const NS = 'nvoos-forge/v1';

	/**
	 * Timestamp window for JSON submissions (± milliseconds).
	 */
	private const TIMESTAMP_WINDOW_MS = 7200000; // 2 hours.

	/**
	 * Per-IP submission cap per window.
	 */
	private const RATE_MAX_PER_IP = 5;

	/**
	 * Rate window (seconds).
	 */
	private const RATE_WINDOW_SECONDS = 600; // 10 minutes.

	/**
	 * Field-count and per-value length caps (oversized payloads are 400s).
	 */
	private const MAX_FIELDS       = 100;
	private const MAX_FIELD_LENGTH = 10000;

	/**
	 * Reserved payload keys — routing + anti-spam, never form fields.
	 */
	private const RESERVED_KEYS = array( 'form', 'post_id', 'fields', 'honeypot', 'timestamp', 'page', 'token', '_redirect' );

	/**
	 * Cloudflare Turnstile siteverify endpoint (filterable — the provider's
	 * documented verification API; the plugin offloads none of its own
	 * content, this is a server-to-server captcha check).
	 */
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-to-server captcha verification against the provider's documented API; no plugin content or scripts are offloaded.
	private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/forms/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_submit' ),
				// Public by design — the origin allowlist, rate limit, spam
				// ladder, and optional captcha below ARE the authorization.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a submission — gates first, native dispatch second.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_submit( \WP_REST_Request $request ): \WP_REST_Response {
		// 0. Feature gate — disabled means the surface does not exist.
		if ( ! Settings::forms_enabled() ) {
			return $this->error_response( 'nvoos_forge_forms_disabled', __( 'Form submissions are disabled on this site.', 'nvoos-forge-bridge' ), 404 );
		}

		// 1. Origin gate (fail closed — browsers send Origin on every
		// cross-origin POST; anything else falls back to the Referer's origin).
		$origin = $this->request_origin( $request );
		if ( '' === $origin || ! Settings::forms_origin_allowed( $origin ) ) {
			return $this->error_response( 'nvoos_forge_bad_origin', __( 'The request origin is not allowed.', 'nvoos-forge-bridge' ), 403 );
		}

		// 2. Rate gate — per-IP (the endpoint has a single route, so this
		// covers the per-endpoint cap as well).
		if ( $this->rate_exceeded( $request ) ) {
			return $this->error_response( 'nvoos_forge_forms_rate_limited', __( 'Too many submissions — try again later.', 'nvoos-forge-bridge' ), 429 );
		}

		// 3. Parse the body (JSON for the enhanced path, form-encoded for no-JS).
		$is_json = $this->is_json_request( $request );
		$payload = $this->parse_payload( $request, $is_json );
		if ( is_wp_error( $payload ) ) {
			return $this->error_from_wp_error( $payload );
		}

		// 4. Spam ladder — silent discard (200, zero side effects, no audit
		// line: no enumeration signal).
		if ( '' !== trim( (string) $payload['honeypot'] ) ) {
			return $this->discarded_response();
		}
		if ( $is_json ) {
			// The enhanced path always carries the client timestamp.
			if ( ! is_numeric( $payload['timestamp'] ) || $this->stale_timestamp( (int) $payload['timestamp'] ) ) {
				return $this->discarded_response();
			}
		} elseif ( is_numeric( $payload['timestamp'] ) && $this->stale_timestamp( (int) $payload['timestamp'] ) ) {
			// The no-JS floor may omit the timestamp; when present it must be fresh.
			return $this->discarded_response();
		}

		// 5. Optional captcha (Turnstile — action + hostname pinned to the
		// allowlisted origin, the 3zero rule).
		if ( Settings::forms_captcha_enabled() ) {
			$verified = $this->verify_turnstile( (string) $payload['token'], $origin, $this->client_ip( $request ) );
			if ( ! $verified ) {
				return $this->error_response( 'nvoos_forge_forms_captcha_failed', __( 'Captcha verification failed.', 'nvoos-forge-bridge' ), 403 );
			}
		}

		// 6. Server-side sanitization of every field.
		$fields = $this->sanitize_fields( $payload['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $this->error_from_wp_error( $fields );
		}

		// 7. Native dispatch — JetFormBuilder first when the identifier
		// resolves to a JFB form, then Elementor Pro, then typed errors.
		$result = $this->dispatch( (string) $payload['form'], (int) $payload['post_id'], $fields, (string) $payload['page'], $origin );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		/**
		 * Audit seam — fires once per processed submission (silent discards
		 * are excluded by design). Wire a logger here if you keep one.
		 *
		 * @param string $outcome 'accepted' | 'rejected'.
		 * @param string $form    The submitted form identifier.
		 * @param string $origin  The allowlisted origin that submitted.
		 * @param string $ip      The client IP.
		 */
		do_action( 'nvoos_forge_bridge_form_submission', $result['ok'] ? 'accepted' : 'rejected', (string) $payload['form'], $origin, $this->client_ip( $request ) );

		// 8. The no-JS floor: form-encoded submissions redirect back to the
		// static page (validated against the allowlist) with ?form=ok|error.
		if ( ! $is_json && '' !== (string) $payload['_redirect'] ) {
			$target = $this->redirect_target( (string) $payload['_redirect'], $origin );
			$target = add_query_arg( 'form', $result['ok'] ? 'ok' : 'error', $target );
			return new \WP_REST_Response( null, 302, array( 'Location' => $target ) );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => (bool) $result['ok'],
				'message' => (string) $result['message'],
				'errors'  => $result['errors'],
			)
		);
	}

	/**
	 * Build a typed error response (the REST error envelope shape the server
	 * would produce for a returned WP_Error — but built explicitly, because
	 * this handler's return type is WP_REST_Response).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private function error_response( string $code, string $message, int $status ): \WP_REST_Response {
		return $this->error_from_wp_error(
			new \WP_Error( $code, $message, array( 'status' => $status ) )
		);
	}

	/**
	 * Convert a WP_Error into the REST error envelope (code/message/data.status
	 * with the error's HTTP status).
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private function error_from_wp_error( \WP_Error $error ): \WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new \WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	/**
	 * The silent-discard response — indistinguishable from success.
	 *
	 * @return \WP_REST_Response
	 */
	private function discarded_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Accepted.', 'nvoos-forge-bridge' ),
			)
		);
	}

	/**
	 * Whether the request body is JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_json_request( \WP_REST_Request $request ): bool {
		$content_type = $request->get_content_type();
		return isset( $content_type['value'] ) && 'application/json' === $content_type['value'];
	}

	/**
	 * The request origin (Origin header, Referer fallback), normalized.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function request_origin( \WP_REST_Request $request ): string {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin ) {
			$referer = trim( (string) $request->get_header( 'referer' ) );
			if ( '' !== $referer ) {
				$parts = wp_parse_url( $referer );
				if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
					$origin = $parts['scheme'] . '://' . $parts['host'];
					if ( ! empty( $parts['port'] ) ) {
						$origin .= ':' . $parts['port'];
					}
				}
			}
		}
		return Settings::normalize_origin( $origin );
	}

	/**
	 * The client IP (best-effort, for rate limiting and captcha only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function client_ip( \WP_REST_Request $request ): string {
		$forwarded = trim( (string) $request->get_header( 'x_forwarded_for' ) );
		if ( '' !== $forwarded ) {
			$parts = explode( ',', $forwarded );
			$ip    = trim( $parts[0] );
			if ( '' !== $ip ) {
				return sanitize_text_field( $ip );
			}
		}
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Whether this IP exceeded the submission cap (and count the attempt).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function rate_exceeded( \WP_REST_Request $request ): bool {
		$key   = 'nvoos_forge_bridge_forms_rate_' . md5( $this->client_ip( $request ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX_PER_IP ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW_SECONDS );
		return false;
	}

	/**
	 * Whether a timestamp (ms) falls outside the ±2h window.
	 *
	 * @param int $timestamp Timestamp in milliseconds.
	 * @return bool
	 */
	private function stale_timestamp( int $timestamp ): bool {
		return abs( (int) round( microtime( true ) * 1000 ) - $timestamp ) > self::TIMESTAMP_WINDOW_MS;
	}

	/**
	 * Parse + shape the payload from a JSON or form-encoded body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $is_json JSON body.
	 * @return array|\WP_Error
	 */
	private function parse_payload( \WP_REST_Request $request, bool $is_json ) {
		if ( $is_json ) {
			$body = json_decode( (string) $request->get_body(), true );
			if ( ! is_array( $body ) ) {
				return new \WP_Error( 'nvoos_forge_forms_bad_request', __( 'Invalid JSON body.', 'nvoos-forge-bridge' ), array( 'status' => 400 ) );
			}
		} else {
			$body = $request->get_body_params();
		}

		$form = isset( $body['form'] ) && is_scalar( $body['form'] ) ? sanitize_text_field( wp_unslash( (string) $body['form'] ) ) : '';
		if ( '' === $form ) {
			return new \WP_Error( 'nvoos_forge_forms_bad_request', __( 'The form identifier is required.', 'nvoos-forge-bridge' ), array( 'status' => 400 ) );
		}

		$fields = array();
		if ( $is_json ) {
			$fields = isset( $body['fields'] ) && is_array( $body['fields'] ) ? $body['fields'] : array();
		} else {
			foreach ( $body as $key => $value ) {
				if ( in_array( (string) $key, self::RESERVED_KEYS, true ) ) {
					continue;
				}
				$fields[ (string) $key ] = $value;
			}
		}
		if ( empty( $fields ) ) {
			return new \WP_Error( 'nvoos_forge_forms_bad_request', __( 'No form fields were submitted.', 'nvoos-forge-bridge' ), array( 'status' => 400 ) );
		}

		return array(
			'form'      => $form,
			'post_id'   => isset( $body['post_id'] ) && is_scalar( $body['post_id'] ) ? absint( $body['post_id'] ) : 0,
			'fields'    => $fields,
			'honeypot'  => isset( $body['honeypot'] ) && is_scalar( $body['honeypot'] ) ? wp_unslash( (string) $body['honeypot'] ) : 'bot',
			'timestamp' => isset( $body['timestamp'] ) && is_scalar( $body['timestamp'] ) ? $body['timestamp'] : null,
			'page'      => isset( $body['page'] ) && is_scalar( $body['page'] ) ? esc_url_raw( wp_unslash( (string) $body['page'] ) ) : '',
			'token'     => isset( $body['token'] ) && is_scalar( $body['token'] ) ? sanitize_text_field( wp_unslash( (string) $body['token'] ) ) : '',
			'_redirect' => isset( $body['_redirect'] ) && is_scalar( $body['_redirect'] ) ? sanitize_text_field( wp_unslash( (string) $body['_redirect'] ) ) : '',
		);
	}

	/**
	 * Server-side sanitization of every submitted field.
	 *
	 * @param mixed $fields Fields.
	 * @return array|\WP_Error
	 */
	private function sanitize_fields( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > self::MAX_FIELDS ) {
			return new \WP_Error( 'nvoos_forge_forms_too_many_fields', __( 'Too many form fields.', 'nvoos-forge-bridge' ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $fields as $name => $value ) {
			$name = sanitize_text_field( wp_unslash( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			$out[ $name ] = $this->sanitize_value( $value );
		}
		return $out;
	}

	/**
	 * Sanitize one field value (scalar or array).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sanitize_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'sanitize_value' ), $value );
		}
		$value = wp_unslash( (string) $value );
		if ( strlen( $value ) > self::MAX_FIELD_LENGTH ) {
			$value = substr( $value, 0, self::MAX_FIELD_LENGTH );
		}
		return sanitize_textarea_field( $value );
	}

	/**
	 * Dispatch to the native handler, in the proposal's ladder order.
	 *
	 * @param string $form    The WP-side form identifier.
	 * @param int    $post_id The source WP post id (Elementor carriers).
	 * @param array  $fields  Sanitized fields.
	 * @param string $page    The static page URL (referrer metadata).
	 * @param string $origin  The allowlisted origin.
	 * @return array|\WP_Error
	 */
	private function dispatch( string $form, int $post_id, array $fields, string $page, string $origin ) {
		if ( self::jfb_available() ) {
			$jfb_form_id = self::resolve_jfb_form_id( $form );
			if ( $jfb_form_id > 0 ) {
				return $this->dispatch_jfb( $jfb_form_id, $fields, '' !== $page ? $page : $origin );
			}
		}
		if ( self::elementor_available() ) {
			if ( ! self::elementor_has_form( $form, $post_id ) ) {
				return new \WP_Error( 'nvoos_forge_form_not_found', __( 'The form does not exist on this site.', 'nvoos-forge-bridge' ), array( 'status' => 404 ) );
			}
			return $this->dispatch_elementor( $form, $post_id, $fields );
		}
		if ( self::jfb_available() ) {
			// A handler exists, but the identifier resolves to no known form.
			return new \WP_Error( 'nvoos_forge_form_not_found', __( 'The form does not exist on this site.', 'nvoos-forge-bridge' ), array( 'status' => 404 ) );
		}
		return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'No form handler (JetFormBuilder or Elementor Pro) is active on this site.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
	}

	/**
	 * Whether JetFormBuilder is active (filterable — the version-drift seam).
	 *
	 * @return bool
	 */
	public static function jfb_available(): bool {
		return apply_filters( 'nvoos_forge_bridge_forms_jfb_available', function_exists( 'jet_fb_handler' ) );
	}

	/**
	 * Whether Elementor Pro's form handler is active (filterable seam).
	 *
	 * @return bool
	 */
	public static function elementor_available(): bool {
		return apply_filters( 'nvoos_forge_bridge_forms_elementor_available', class_exists( 'ElementorPro\\Modules\\Forms\\Classes\\Ajax_Handler' ) );
	}

	/**
	 * Resolve a form id/name to a JetFormBuilder form post ID.
	 *
	 * @param string $form Identifier.
	 * @return int 0 when unresolved.
	 */
	public static function resolve_jfb_form_id( string $form ): int {
		$id = absint( $form );
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post && 'jet-form-builder' === $post->post_type ? $id : 0;
		}
		return self::jfb_form_by_title( $form );
	}

	/**
	 * Resolve a form NAME to a JetFormBuilder form post (the identifier may
	 * be a name instead of an ID).
	 *
	 * @param string $form Form name.
	 * @return int Form post ID, or 0.
	 */
	private static function jfb_form_by_title( string $form ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'jet-form-builder',
				'title'          => $form,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Whether an Elementor Pro form widget with this id exists.
	 *
	 * The table/column names are constants (never user input); the LIKE term
	 * is esc_like()d and every placeholder is bound by prepare().
	 *
	 * @param string $form    The widget form id.
	 * @param int    $post_id The carrying post (0 = search all).
	 * @return bool
	 */
	public static function elementor_has_form( string $form, int $post_id ): bool {
		global $wpdb;
		$term = '%' . $wpdb->esc_like( $form ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded existence check against Elementor's own meta; caching the lookup adds staleness for no win.
		if ( $post_id > 0 ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the constant wpdb property; the term + id are bound below.
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s AND post_id = %d LIMIT 1",
					$term,
					$post_id
				)
			);
		} else {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the constant wpdb property; the term is bound below.
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 1",
					$term
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return ! empty( $rows );
	}

	/**
	 * Dispatch through JetFormBuilder's own form processor.
	 *
	 * The public pipeline: the form id + refer carriers go into the request,
	 * `process_form()` runs validation, spam checks, actions, and mail, and
	 * the JSON response is captured (the handler's `wp_send_json` is a
	 * process-ending call, so it is intercepted at the die handler).
	 *
	 * @param int    $form_id JFB form post ID.
	 * @param array  $fields  Fields.
	 * @param string $page    Referrer.
	 * @return array|\WP_Error
	 */
	private function dispatch_jfb( int $form_id, array $fields, string $page ) {
		/**
		 * Version-drift seam — return an array (['ok' => bool, 'message' =>
		 * string, 'errors' => array|null]) or a WP_Error to pin this handler's
		 * contract against a specific JetFormBuilder release; null runs the
		 * native dispatch below.
		 *
		 * @param mixed  $override Null by default.
		 * @param int    $form_id  JFB form post ID.
		 * @param array  $fields   Sanitized fields.
		 * @param string $page     Referrer.
		 */
		$override = apply_filters( 'nvoos_forge_bridge_forms_jfb_dispatch', null, $form_id, $fields, $page );
		if ( null !== $override ) {
			return $this->normalize_result( $override );
		}

		if ( ! function_exists( 'jet_fb_handler' ) || ! method_exists( jet_fb_handler(), 'process_form' ) ) {
			return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The JetFormBuilder handler is not available.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
		}

		$handler   = jet_fb_handler();
		$form_key  = isset( $handler->form_key ) ? (string) $handler->form_key : '_jet_engine_booking_form_id';
		$refer_key = isset( $handler->refer_key ) ? (string) $handler->refer_key : '_jet_engine_refer';

		// Feed the handler the request exactly the way its rendered form does.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this IS the submission surface (JFB's own forms are nonce-free).
		$_POST[ $form_key ]  = $form_id;
		$_POST[ $refer_key ] = '' !== $page ? $page : home_url( '/' );
		foreach ( $fields as $name => $value ) {
			$_POST[ $name ] = $value;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( function_exists( 'jet_fb_context' ) ) {
			foreach ( $fields as $name => $value ) {
				jet_fb_context()->update_request( $value, $name );
				jet_fb_context()->set_field_type( 'text-field', $name );
			}
			jet_fb_context()->update_request( $form_id, $form_key );
		}

		$captured = $this->capture_json(
			function () use ( $handler ) {
				$handler->is_ajax = true; // JSON response, not a reload redirect.
				$handler->process_form();
			}
		);
		if ( null === $captured ) {
			return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The JetFormBuilder handler did not produce a response.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
		}
		return $this->map_handler_json( $captured );
	}

	/**
	 * Dispatch through Elementor Pro's form ajax handler.
	 *
	 * The admin-ajax contract is emulated in-process (action, form id, post
	 * id, a fresh nonce for the handler's own verification action, and the
	 * form_fields map), so validation, honeypot, actions, mail, and
	 * submission storage run exactly as for a rendered form.
	 *
	 * @param string $form_id Elementor form id.
	 * @param int    $post_id Source post id.
	 * @param array  $fields  Fields.
	 * @return array|\WP_Error
	 */
	private function dispatch_elementor( string $form_id, int $post_id, array $fields ) {
		/**
		 * Version-drift seam — receives the EXACT form_fields map the native
		 * handler gets (bracket keys already flattened). Return an array
		 * (['ok' => bool, 'message' => string, 'errors' => array|null]) or a
		 * WP_Error to pin this handler's contract against a specific Elementor
		 * release; null runs the native dispatch below.
		 *
		 * @param mixed  $override Null by default.
		 * @param string $form_id  Elementor form id.
		 * @param int    $post_id  Source post id.
		 * @param array  $form_fields The form_fields map.
		 */
		$form_fields = $this->elementor_fields( $fields );
		$override    = apply_filters( 'nvoos_forge_bridge_forms_elementor_dispatch', null, $form_id, $post_id, $form_fields );
		if ( null !== $override ) {
			return $this->normalize_result( $override );
		}

		$class = 'ElementorPro\\Modules\\Forms\\Classes\\Ajax_Handler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'ajax_send_form' ) ) {
			return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The Elementor Pro form handler is not available.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
		}

		$captured = $this->capture_json(
			function () use ( $class, $form_id, $post_id, $form_fields ) {
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- this IS the submission surface.
				$_POST['action']      = 'elementor_pro_forms';
				$_POST['form_id']     = $form_id;
				$_POST['post_id']     = $post_id;
				$_POST['queried_id']  = $post_id;
				$_POST['form_fields'] = $form_fields;
				$_POST['_nonce']      = wp_create_nonce( 'elementor_pro_forms' );
				// phpcs:enable WordPress.Security.NonceVerification.Missing
				( new $class() )->ajax_send_form();
			}
		);
		if ( null === $captured ) {
			return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The Elementor Pro handler did not produce a response.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
		}
		return $this->map_handler_json( $captured );
	}

	/**
	 * Shape the submitted fields into Elementor's form_fields map (both the
	 * nested array and the `form_fields[name]` flat-key wire forms).
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function elementor_fields( array $fields ): array {
		if ( isset( $fields['form_fields'] ) && is_array( $fields['form_fields'] ) ) {
			return $fields['form_fields'];
		}
		$out = array();
		foreach ( $fields as $name => $value ) {
			if ( preg_match( '/^form_fields\[(.+)\]$/', (string) $name, $matches ) ) {
				$out[ $matches[1] ] = $value;
			} else {
				$out[ (string) $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Normalize a seam override into the bridge envelope.
	 *
	 * @param mixed $result Seam result.
	 * @return array|\WP_Error
	 */
	private function normalize_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && array_key_exists( 'ok', $result ) ) {
			return array(
				'ok'      => (bool) $result['ok'],
				'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
				'errors'  => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : null,
			);
		}
		return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The form handler returned an unrecognized response.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
	}

	/**
	 * Map a native handler's JSON response into the envelope (both the
	 * `{status, message, errors}` (JFB) and `{success, data}` (wp_send_json)
	 * shapes are recognized).
	 *
	 * @param array $json Captured response data.
	 * @return array|\WP_Error
	 */
	private function map_handler_json( array $json ) {
		if ( array_key_exists( 'status', $json ) ) {
			$ok = 'success' === $json['status'];
			return array(
				'ok'      => $ok,
				'message' => isset( $json['message'] ) ? (string) $json['message'] : '',
				'errors'  => isset( $json['errors'] ) && is_array( $json['errors'] ) ? $json['errors'] : null,
			);
		}
		if ( array_key_exists( 'success', $json ) ) {
			$ok   = (bool) $json['success'];
			$data = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
			return array(
				'ok'      => $ok,
				'message' => isset( $data['message'] ) ? (string) $data['message'] : '',
				'errors'  => isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : null,
			);
		}
		return new \WP_Error( 'nvoos_forge_no_form_handler', __( 'The form handler returned an unrecognized response.', 'nvoos-forge-bridge' ), array( 'status' => 501 ) );
	}

	/**
	 * Run a dispatch that ends in `wp_send_json*` and capture the data it
	 * would have emitted, without letting it terminate the request.
	 *
	 * @param callable $dispatch Dispatch.
	 * @return array|null The captured response data, or null when the handler
	 *                    never emitted JSON.
	 * @throws \RuntimeException When the dispatch throws anything other than
	 *                           the capture sentinel.
	 */
	private function capture_json( callable $dispatch ) {
		$captured = null;
		$filter   = function () use ( &$captured ) {
			return function ( $message, $title, $args ) use ( &$captured ) {
				$captured = array(
					'message' => $message,
					'title'   => $title,
					'args'    => $args,
				);
				throw new \RuntimeException( 'nvoos_forge_bridge_json_captured' );
			};
		};
		add_filter( 'wp_die_json_handler', $filter );
		try {
			$dispatch();
		} catch ( \RuntimeException $exception ) {
			if ( 'nvoos_forge_bridge_json_captured' !== $exception->getMessage() ) {
				throw $exception;
			}
		} finally {
			remove_filter( 'wp_die_json_handler', $filter );
		}
		if ( null === $captured || ! is_array( $captured['message'] ) ) {
			return null;
		}
		return $captured['message'];
	}

	/**
	 * Verify a Cloudflare Turnstile token — action and hostname pinned to the
	 * allowlisted origin (the 3zero rule: a score without those checks is
	 * trust theater). Network failures fail closed.
	 *
	 * @param string $token Token.
	 * @param string $origin Origin.
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function verify_turnstile( string $token, string $origin, string $ip ): bool {
		if ( '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			apply_filters( 'nvoos_forge_bridge_forms_turnstile_endpoint', self::TURNSTILE_VERIFY_URL ),
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => Settings::forms_captcha_secret(),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false; // Fail closed.
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return false;
		}
		if ( ! empty( $body['action'] ) && 'nvoos_forge_form' !== $body['action'] ) {
			return false;
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! empty( $body['hostname'] ) && $host && strtolower( (string) $body['hostname'] ) !== strtolower( (string) $host ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate the no-JS redirect target against the origin allowlist (the
	 * open-redirect guard), falling back to the origin root.
	 *
	 * @param string $path   The `_redirect` value.
	 * @param string $origin The allowlisted origin.
	 * @return string
	 */
	private function redirect_target( string $path, string $origin ): string {
		$base = untrailingslashit( $origin );
		if ( preg_match( '#^https?://#i', $path ) ) {
			if ( ! Settings::forms_origin_allowed( $path ) ) {
				return $base . '/';
			}
			return untrailingslashit( $path );
		}
		if ( 0 === strpos( $path, '/' ) ) {
			return $base . $path;
		}
		return $base . '/' . $path;
	}
}
