<?php
/**
 * OAuth 2.1 resource-server contract for the ChatGPT plugin bridge.
 *
 * Central static helpers for the MCP Authorization specification surface:
 * the RFC 9728 protected-resource metadata document, WWW-Authenticate
 * challenges, per-tool OAuth security schemes, the OpenAI profile-tool
 * schema, and audience acceptance for tokens minted against the MCP
 * resource identifier.
 *
 * The site acts as the *resource server* (its MCP endpoint is the protected
 * resource); Auth0 (already integrated via Settings → NV oOS) acts as the
 * *authorization server*. Nothing here is advertised until an
 * authorization server is configured, so sites without Auth0 keep today's
 * behaviour exactly.
 *
 * @package WP_MCP_AI
 * @since 1.1.94
 * @see    https://www.rfc-editor.org/rfc/rfc9728 (Protected Resource Metadata)
 * @see    docs/project/proposals/053-chatgpt-plugin-addon-implementation-plan.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for the MCP OAuth resource-server contract.
 */
class WP_MCP_AI_OAuth_Resource_Server {

	/**
	 * Option name for the OpenAI plugin domain-verification challenge token.
	 *
	 * @var string
	 */
	const OPTION_CHALLENGE_TOKEN = 'wp_mcp_ai_openai_apps_challenge_token';

	/**
	 * Whether an authorization server is advertised (Auth0 configured).
	 *
	 * Every contract surface (metadata, challenges, security schemes) stays
	 * silent until this returns true, so existing sites are unaffected.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return ! empty( $settings['auth0_domain'] );
	}

	/**
	 * The canonical RFC 9728 resource identifier: the site's MCP endpoint URL.
	 *
	 * Tokens minted for ChatGPT plugin connections carry this value in their
	 * `aud` claim (Auth0 echoes the `resource` parameter), so it doubles as
	 * the audience the existing bearer-token validator must accept.
	 *
	 * @return string
	 */
	public static function get_resource_identifier() {
		return rest_url( 'mcp-ai/v1/mcp' );
	}

	/**
	 * URL of the protected-resource metadata document.
	 *
	 * @return string
	 */
	public static function get_resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	/**
	 * Authorization server issuers advertised in the metadata document.
	 *
	 * @return string[]
	 */
	public static function get_authorization_servers() {
		$servers = array();

		if ( self::is_configured() ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$domain   = rtrim( trim( $settings['auth0_domain'] ), '/' );
			if ( '' !== $domain ) {
				$servers[] = 'https://' . $domain . '/';
			}
		}

		/**
		 * Filter the authorization servers advertised to MCP clients.
		 *
		 * @param string[] $servers Authorization server issuer URLs.
		 */
		return apply_filters( 'wp_mcp_ai_oauth_authorization_servers', $servers );
	}

	/**
	 * Scopes the plugin's OAuth consent screen may request.
	 *
	 * `site:read` covers read-only tools; `content:write` covers
	 * create/update/publish tools. Pro integrations may add scopes (e.g.
	 * `store:manage`) via the filter.
	 *
	 * @return string[]
	 */
	public static function get_supported_scopes() {
		/**
		 * Filter the OAuth scopes advertised in the metadata document.
		 *
		 * @param string[] $scopes Scope names.
		 */
		return apply_filters( 'wp_mcp_ai_oauth_supported_scopes', array( 'site:read', 'content:write' ) );
	}

	/**
	 * Build the WWW-Authenticate challenge value (RFC 9728 §3).
	 *
	 * Empty string when no authorization server is configured, so callers
	 * must check is_configured() before emitting the header.
	 *
	 * @param string $resource_url Unused; kept for the call-site signature.
	 * @return string Challenge header value, or empty when not configured.
	 */
	public static function build_www_authenticate( $resource_url = '' ) {
		if ( ! self::is_configured() ) {
			return '';
		}

		$header = 'Bearer resource_metadata="' . self::get_resource_metadata_url() . '"';

		$scopes = implode( ' ', self::get_supported_scopes() );
		if ( '' !== $scopes ) {
			$header .= ', scope="' . $scopes . '"';
		}

		return $header;
	}

	/**
	 * Build the `_meta["mcp/www_authenticate"]` payload for a failing tool
	 * call. This is what makes ChatGPT surface its OAuth linking UI.
	 *
	 * @param string $error_code        OAuth error code (e.g. insufficient_scope).
	 * @param string $error_description Human-readable description.
	 * @return string[] Challenge entries for the `_meta` key.
	 */
	public static function build_tool_auth_challenge( $error_code = 'insufficient_scope', $error_description = '' ) {
		if ( '' === $error_description ) {
			$error_description = __( 'Sign in to continue using this tool.', 'mcp-ai-wpoos' );
		}

		$challenge = 'Bearer resource_metadata="' . self::get_resource_metadata_url()
			. '", error="' . $error_code
			. '", error_description="' . $error_description . '"';

		return array( $challenge );
	}

	/**
	 * Whether an error code should trigger the OAuth linking challenge.
	 *
	 * @param string $code WP_Error code.
	 * @return bool
	 */
	public static function is_auth_error_code( $code ) {
		return in_array(
			$code,
			array(
				'wp_mcp_ai_mcp_auth_required',
				'wp_mcp_ai_missing_credentials',
				'wp_mcp_ai_invalid_bearer_token',
				'wp_mcp_ai_expired_bearer_token',
				'wp_mcp_ai_invalid_bearer_audience',
				'wp_mcp_ai_insufficient_bearer_scope',
			),
			true
		);
	}

	/**
	 * Whether a token audience matches the MCP resource identifier.
	 *
	 * ChatGPT plugin OAuth tokens are minted for the RFC 9728 `resource`
	 * value, which may differ from the legacy `auth0_audience` setting.
	 * The bearer validator accepts either.
	 *
	 * @param string|string[] $audience Decoded `aud` claim.
	 * @return bool
	 */
	public static function audience_matches_resource( $audience ) {
		$resource = self::get_resource_identifier();

		if ( is_array( $audience ) ) {
			foreach ( $audience as $single ) {
				if ( is_string( $single ) && $resource === $single ) {
					return true;
				}
			}
			return false;
		}

		return is_string( $audience ) && '' !== $audience && $resource === $audience;
	}

	/**
	 * Build the RFC 9728 protected-resource metadata document.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_protected_resource_document() {
		$document = array(
			'resource'               => self::get_resource_identifier(),
			'authorization_servers'  => self::get_authorization_servers(),
			'scopes_supported'       => self::get_supported_scopes(),
			'resource_documentation' => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/mcp-server-authentication.md',
		);

		/**
		 * Filter the protected-resource metadata document before it is sent.
		 *
		 * @param array<string,mixed> $document RFC 9728 document.
		 */
		return apply_filters( 'wp_mcp_ai_oauth_protected_resource', $document );
	}

	/**
	 * The OpenAI profile-tool output schema (stable opaque `id` required).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_profile_output_schema() {
		return array(
			'$schema'             => 'https://json-schema.org/draft/2020-12/schema',
			'type'                => 'object',
			'properties'          => array(
				'id'       => array(
					'type'        => 'string',
					'minLength'   => 1,
					'pattern'     => '\\S',
					'description' => __( 'Opaque profile identifier, unique within this app and unchanged across token refresh, reconnection, and display-metadata changes. Never reassigned to another profile.', 'mcp-ai-wpoos' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Display name for the authenticated profile.', 'mcp-ai-wpoos' ),
				),
				'email'    => array(
					'type'        => 'string',
					'description' => __( 'Email address for display; not used as the profile identity.', 'mcp-ai-wpoos' ),
				),
				'nickname' => array(
					'type'        => 'string',
					'description' => __( 'A useful label that helps users distinguish connected profiles.', 'mcp-ai-wpoos' ),
				),
			),
			'required'            => array( 'id' ),
			'additionalProperties' => false,
		);
	}
}
