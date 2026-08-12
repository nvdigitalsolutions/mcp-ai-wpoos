<?php
/**
 * Hermes (and generic MCP host) config generation.
 *
 * Produces ready-to-paste fragments for ~/.hermes/config.yaml (mcp_servers
 * block) and ~/.hermes/.env (secrets) from operator credentials. Output is
 * Hermes-flavoured but intentionally standard MCP: any host that accepts
 * remote HTTP MCP servers with bearer headers can consume the same values.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds Hermes config.yaml / .env fragments for operator credentials.
 */
class WP_MCP_AI_Operator_Config_Generator {

	/**
	 * Convert a label into a YAML-safe server name.
	 *
	 * @param string $label Operator or site label.
	 * @return string Lowercase slug.
	 */
	public static function slugify( $label ) {
		$slug = sanitize_title( $label );
		return '' === $slug ? 'site' : $slug;
	}

	/**
	 * Build an environment variable name for a server's token.
	 *
	 * @param string $server_name Server name.
	 * @return string
	 */
	public static function env_var_name( $server_name ) {
		$slug = preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $server_name ) );
		$slug = null === $slug ? 'site' : trim( $slug, '_' );
		return 'NVOOS_' . strtoupper( $slug ) . '_TOKEN';
	}

	/**
	 * Escape a scalar for single-quoted YAML.
	 *
	 * @param string $value Raw value.
	 * @return string Quoted YAML scalar.
	 */
	protected static function yaml_quote( $value ) {
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * Generate the Hermes config fragment for a single operator credential.
	 *
	 * @param string $label      Operator label (used as the mcp_servers key).
	 * @param string $site_url   Canonical site URL (e.g. https://example.com).
	 * @param string $token      Full operator token (op_xxxx.SECRET).
	 * @param array  $allowlist  Raw allowlist entries.
	 * @param bool   $untrusted  Whether to set trust: untrusted (approve writes).
	 * @return array With "yaml", "env", and "include" keys.
	 */
	public static function generate_for_site( $label, $site_url, $token, $allowlist, $untrusted = true ) {
		$server_name = self::slugify( $label );
		$env_var     = self::env_var_name( $server_name );
		$endpoint    = trailingslashit( $site_url ) . 'wp-json/mcp-ai/v1/mcp';

		$include = WP_MCP_AI_Operator_Tool_Scope::expand_allowlist( $allowlist );
		sort( $include );

		$include_lines = array();
		foreach ( $include as $entry ) {
			$include_lines[] = '        - ' . self::yaml_quote( $entry );
		}

		$yaml  = 'mcp_servers:' . "\n";
		$yaml .= '  ' . $server_name . ':' . "\n";
		$yaml .= '    url: ' . self::yaml_quote( $endpoint ) . "\n";
		$yaml .= '    headers:' . "\n";
		$yaml .= '      Authorization: "Bearer ${env:' . $env_var . '}"' . "\n";
		if ( $untrusted ) {
			$yaml .= '    trust: untrusted  # approve every write-capable tool call' . "\n";
		}
		if ( empty( $include_lines ) ) {
			$yaml .= '    tools:' . "\n";
			$yaml .= '      include: []  # no tools allowed; extend the allowlist in WP admin' . "\n";
		} else {
			$yaml .= '    tools:' . "\n";
			$yaml .= '      include:' . "\n";
			$yaml .= implode( "\n", $include_lines ) . "\n";
		}

		$env = $env_var . '=' . $token;

		return array(
			'yaml'    => $yaml,
			'env'     => $env,
			'include' => $include,
		);
	}

	/**
	 * Generate config fragments for a fleet of sites.
	 *
	 * @param array $sites List of arrays with label, site_url, token, allowlist keys.
	 * @return array With "yaml" and "env" keys.
	 */
	public static function generate_fleet( $sites ) {
		$yaml_blocks = array();
		$env_lines   = array();

		foreach ( (array) $sites as $site ) {
			$label     = isset( $site['label'] ) ? $site['label'] : __( 'Site', 'mcp-ai-wpoos' );
			$site_url  = isset( $site['site_url'] ) ? $site['site_url'] : '';
			$token     = isset( $site['token'] ) ? $site['token'] : '';
			$allowlist = isset( $site['allowlist'] ) ? $site['allowlist'] : array();

			if ( '' === $site_url || '' === $token ) {
				continue;
			}

			$generated     = self::generate_for_site( $label, $site_url, $token, $allowlist );
			$yaml_blocks[] = trim( $generated['yaml'] );
			$env_lines[]   = $generated['env'];
		}

		return array(
			'yaml' => implode( "\n\n", $yaml_blocks ),
			'env'  => implode( "\n", $env_lines ),
		);
	}
}
