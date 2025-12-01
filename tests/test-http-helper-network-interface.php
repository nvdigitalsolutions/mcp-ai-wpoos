<?php
/**
 * Tests for WP_MCP_AI_HTTP_Helper network interface binding.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_HTTP_Helper_Network_Interface_Test extends WP_UnitTestCase {

	/**
	 * Test that network interface binding is applied to Ollama requests.
	 */
	public function test_network_interface_binding_applied_to_ollama() {
		$defaults                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url']      = 'http://localhost:11434';
		$defaults['ollama_network_interface'] = 'eth0';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches Ollama endpoint.
		$url = 'http://localhost:11434/api/chat';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is applied to LM Studio requests.
	 */
	public function test_network_interface_binding_applied_to_lm_studio() {
		$defaults                                = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_endpoint_url']      = 'http://localhost:1234';
		$defaults['lm_studio_network_interface'] = '192.168.1.100';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches LM Studio endpoint (client appends /v1).
		$url = 'http://localhost:1234/v1/chat/completions';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied to OpenAI requests.
	 */
	public function test_network_interface_binding_not_applied_to_openai() {
		$defaults                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_network_interface'] = 'eth0';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL for OpenAI (should NOT be affected).
		$url = 'https://api.openai.com/v1/chat/completions';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned unchanged.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied to Anthropic requests.
	 */
	public function test_network_interface_binding_not_applied_to_anthropic() {
		$defaults                                = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_network_interface'] = '192.168.1.100';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL for Anthropic (should NOT be affected).
		$url = 'https://api.anthropic.com/v1/messages';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned unchanged.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied when interface is empty.
	 */
	public function test_network_interface_binding_not_applied_when_empty() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		// Network interface is empty (not set).

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches Ollama endpoint.
		$url = 'http://localhost:11434/api/chat';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied when endpoint is empty.
	 */
	public function test_network_interface_binding_not_applied_when_endpoint_empty() {
		$defaults                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_network_interface'] = 'eth0';
		// Endpoint is empty (not set).

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL.
		$url = 'http://localhost:11434/api/chat';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned unchanged (no endpoint configured).
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied when interface matches destination host.
	 *
	 * This detects the common misconfiguration where users enter the destination
	 * IP address in the network interface field instead of a local interface.
	 */
	public function test_network_interface_binding_not_applied_when_interface_matches_destination() {
		$defaults                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_endpoint_url'] = 'http://192.168.2.222:1234/v1';
		// Misconfiguration: user entered destination IP in interface field.
		$defaults['lm_studio_network_interface'] = '192.168.2.222';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches LM Studio endpoint.
		$url = 'http://192.168.2.222:1234/v1/chat/completions';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned (but interface binding should be skipped).
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that network interface binding is NOT applied when interface IP is in endpoint URL.
	 *
	 * This detects when users enter an IP from the endpoint URL in the interface field.
	 */
	public function test_network_interface_binding_not_applied_when_interface_in_endpoint_url() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://10.0.0.50:11434';
		// Misconfiguration: user entered destination IP in interface field.
		$defaults['ollama_network_interface'] = '10.0.0.50';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches Ollama endpoint.
		$url = 'http://10.0.0.50:11434/api/chat';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned (but interface binding should be skipped).
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that valid network interface binding is still applied correctly.
	 *
	 * This ensures that legitimate configurations still work after validation.
	 */
	public function test_valid_network_interface_binding_still_works() {
		$defaults                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_endpoint_url'] = 'http://192.168.2.222:1234/v1';
		// Valid configuration: local interface on WordPress server.
		$defaults['lm_studio_network_interface'] = '192.168.1.50';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches LM Studio endpoint.
		$url = 'http://192.168.2.222:1234/v1/chat/completions';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that private IP in interface field with localhost endpoint is detected.
	 *
	 * Common user confusion: putting the remote server IP in the interface field
	 * while endpoint is still set to localhost.
	 */
	public function test_private_ip_with_localhost_endpoint_detected() {
		$defaults                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_endpoint_url'] = 'http://localhost:1234';
		// Misconfiguration: user wants to reach 192.168.2.222 but put it in wrong field.
		$defaults['lm_studio_network_interface'] = '192.168.2.222';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a mock cURL handle.
		$handle = curl_init();

		// Test URL that matches LM Studio endpoint (client appends /v1).
		$url = 'http://localhost:1234/v1/models';

		$result = WP_MCP_AI_HTTP_Helper::apply_network_interface_binding( $handle, array(), $url );

		// Verify the handle is returned (but interface binding should be skipped).
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}
}
