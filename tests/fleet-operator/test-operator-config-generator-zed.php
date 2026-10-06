<?php
/**
 * Zed / Claude config generator tests for the Fleet Operator addon.
 *
 * Covers the npx-bridge JSON fragments emitted for editor clients: shape,
 * endpoint composition, SSH-variant env, slugified server keys, and JSON
 * validity for hostile inputs.
 *
 * @package WP_MCP_AI
 */

// Load the addon class directly — the addon is not active during tests.
require_once dirname( __DIR__, 2 ) . '/addons/fleet-operator/includes/class-wp-mcp-ai-operator-config-generator.php';

/**
 * Test suite for the Zed/Claude JSON config generators.
 */
class Test_WP_MCP_AI_Operator_Config_Generator_Zed extends WP_UnitTestCase {

	const PACKAGE = '@nvdigitalsolutions/nvoos-mcp-bridge@latest';

	/**
	 * Generate the standard fragment pair for assertions.
	 *
	 * @param string $label    Operator label.
	 * @param string $site_url Site URL.
	 * @param bool   $ssh      SSH variant.
	 * @return array With "zed" and "claude" decoded JSON.
	 */
	protected function generate_pair( $label = 'Zed Operator', $site_url = 'https://example.com', $ssh = false ) {
		$token  = 'op_abc123.SECRET';
		$zed    = json_decode( WP_MCP_AI_Operator_Config_Generator::generate_zed_json( $label, $site_url, $token, array(), $ssh ), true );
		$claude = json_decode( WP_MCP_AI_Operator_Config_Generator::generate_claude_json( $label, $site_url, $token, array(), $ssh ), true );
		return array( $zed, $claude );
	}

	/**
	 * Zed fragment carries the required custom-source shape.
	 */
	public function test_zed_shape() {
		list( $zed ) = $this->generate_pair();

		$this->assertIsArray( $zed, 'must be valid JSON' );
		$this->assertArrayHasKey( 'zed-operator', $zed );

		$server = $zed['zed-operator'];
		$this->assertSame( 'custom', $server['source'] );
		$this->assertSame( 'npx', $server['command']['path'] );
		$this->assertSame( array( '-y', self::PACKAGE ), $server['command']['args'] );
		$this->assertSame( 'op_abc123.SECRET', $server['command']['env']['MCP_AI_TOKEN'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/mcp', $server['command']['env']['MCP_AI_BASE_URL'] );
	}

	/**
	 * SSH variant swaps the env for SSH placeholders and selects the ssh bin.
	 */
	public function test_zed_ssh_variant() {
		list( $zed ) = $this->generate_pair( 'SSH Site', 'https://example.com', true );

		$server = $zed['ssh-site'];
		$this->assertSame( array( '-y', self::PACKAGE, 'ssh' ), $server['command']['args'] );
		$this->assertArrayNotHasKey( 'MCP_AI_BASE_URL', $server['command']['env'] );
		$this->assertSame( 'your-ssh-user', $server['command']['env']['MCP_AI_SSH_USER'] );
		$this->assertSame( 'your-ssh-host', $server['command']['env']['MCP_AI_SSH_HOST'] );
		$this->assertSame( '22', $server['command']['env']['MCP_AI_SSH_PORT'] );
		$this->assertSame( 'op_abc123.SECRET', $server['command']['env']['MCP_AI_TOKEN'] );
	}

	/**
	 * Claude fragment nests under mcpServers with the flat command shape.
	 */
	public function test_claude_shape() {
		list( , $claude ) = $this->generate_pair();

		$this->assertArrayHasKey( 'mcpServers', $claude );
		$server = $claude['mcpServers']['zed-operator'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( array( '-y', self::PACKAGE ), $server['args'] );
		$this->assertSame( 'op_abc123.SECRET', $server['env']['MCP_AI_TOKEN'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/mcp', $server['env']['MCP_AI_BASE_URL'] );
	}

	/**
	 * Server keys are slugified, matching the Hermes generator's naming.
	 */
	public function test_server_key_slugification() {
		list( $zed ) = $this->generate_pair( 'My Fancy Operator!' );
		$this->assertArrayHasKey( 'my-fancy-operator', $zed );
	}

	/**
	 * Hostile inputs (quotes, ampersands, trailing slash) stay valid JSON.
	 */
	public function test_json_remains_valid_for_hostile_inputs() {
		list( $zed, $claude ) = $this->generate_pair( 'O"Brien & Co', 'https://example.com/?ref=a&b' );

		// json_decode returning arrays already proves validity; assert the
		// endpoint survives the query string untouched.
		$this->assertSame(
			'https://example.com/?ref=a&b/wp-json/mcp-ai/v1/mcp',
			$zed[ array_key_first( $zed ) ]['command']['env']['MCP_AI_BASE_URL']
		);
		$this->assertArrayHasKey( 'obrien-co', $claude['mcpServers'] );
	}

	/**
	 * Empty allowlist changes nothing client-side (scoping is server-side).
	 */
	public function test_allowlist_does_not_leak_into_fragments() {
		$zed_raw = WP_MCP_AI_Operator_Config_Generator::generate_zed_json(
			'Scoped',
			'https://example.com',
			'op_abc123.SECRET',
			array( 'create_post', 'woo_*' )
		);
		$zed = json_decode( $zed_raw, true );
		$this->assertArrayNotHasKey( 'tools', $zed['scoped'] );
		$this->assertArrayNotHasKey( 'allowlist', $zed['scoped'] );
	}
}
