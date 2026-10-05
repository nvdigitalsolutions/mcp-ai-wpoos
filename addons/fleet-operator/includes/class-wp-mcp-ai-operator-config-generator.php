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
	 * Build the shared npx command args + env for the published bridge
	 * package (@nvdigitalsolutions/nvoos-mcp-bridge).
	 *
	 * @param string $site_url Canonical site URL (e.g. https://example.com).
	 * @param string $token    Full operator token (op_xxxx.SECRET).
	 * @param bool   $ssh      Emit the SSH variant (nvoos-mcp-ssh).
	 * @return array With "args" and "env" keys.
	 */
	protected static function bridge_command( $site_url, $token, $ssh = false ) {
		$args = array( '-y', '@nvdigitalsolutions/nvoos-mcp-bridge@latest' );
		if ( $ssh ) {
			$args[] = 'ssh';
		}

		$env = array();
		if ( $ssh ) {
			// The site admin cannot know the operator's SSH endpoint — emit
			// placeholders the operator fills in, mirroring how the Hermes
			// config leaves host-specific values to the operator.
			$env['MCP_AI_SSH_USER'] = 'your-ssh-user';
			$env['MCP_AI_SSH_HOST'] = 'your-ssh-host';
			$env['MCP_AI_SSH_PORT'] = '22';
		} else {
			$env['MCP_AI_BASE_URL'] = trailingslashit( $site_url ) . 'wp-json/mcp-ai/v1/mcp';
		}
		$env['MCP_AI_TOKEN'] = $token;

		return array(
			'args' => $args,
			'env'  => $env,
		);
	}

	/**
	 * Generate a Zed context_servers JSON fragment for one operator
	 * credential, driving the site through the published npx bridge package.
	 *
	 * The allowlist is enforced server-side (tools/list scoping) — it does
	 * not need to appear in the client config, but is accepted so the call
	 * sites stay symmetric with generate_for_site().
	 *
	 * @param string $label      Operator label (used as the server key).
	 * @param string $site_url   Canonical site URL (e.g. https://example.com).
	 * @param string $token      Full operator token (op_xxxx.SECRET).
	 * @param array  $allowlist  Raw allowlist entries.
	 * @param bool   $ssh        Emit the SSH variant for SSH-only sites.
	 * @return string Pretty-printed JSON fragment for context_servers.
	 */
	public static function generate_zed_json( $label, $site_url, $token, $allowlist = array(), $ssh = false ) {
		$server_name = self::slugify( $label );
		$command     = self::bridge_command( $site_url, $token, $ssh );

		$fragment = array(
			$server_name => array(
				'source'  => 'custom',
				'command' => array(
					'path' => 'npx',
					'args' => $command['args'],
					'env'  => $command['env'],
				),
			),
		);

		return wp_json_encode( $fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Generate a Claude Desktop (or Cursor / VS Code) mcpServers JSON fragment
	 * for one operator credential through the same published npx package.
	 *
	 * @param string $label      Operator label (used as the server key).
	 * @param string $site_url   Canonical site URL (e.g. https://example.com).
	 * @param string $token      Full operator token (op_xxxx.SECRET).
	 * @param array  $allowlist  Raw allowlist entries (server-side scoping).
	 * @param bool   $ssh        Emit the SSH variant for SSH-only sites.
	 * @return string Pretty-printed JSON fragment for mcpServers.
	 */
	public static function generate_claude_json( $label, $site_url, $token, $allowlist = array(), $ssh = false ) {
		$server_name = self::slugify( $label );
		$command     = self::bridge_command( $site_url, $token, $ssh );

		$fragment = array(
			'mcpServers' => array(
				$server_name => array(
					'command' => 'npx',
					'args'    => $command['args'],
					'env'     => $command['env'],
				),
			),
		);

		return wp_json_encode( $fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
