<?php
/**
 * Tests for Cloudflare Workers AI URL Encoding
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare Workers AI URL construction and encoding.
 */
class Test_Cloudflare_URL_Encoding extends WP_UnitTestCase {

	/**
	 * Test that model IDs with forward slashes are not over-encoded.
	 */
	public function test_model_id_preserves_forward_slashes() {
		// Test the encoding logic that should be used.
		$model         = '@cf/meta/llama-3.1-8b-instruct';
		$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

		// Forward slashes should be preserved.
		$this->assertStringContainsString( '/', $escaped_model, 'Forward slashes should be preserved in model ID' );

		// @ symbol should be encoded.
		$this->assertStringContainsString( '%40', $escaped_model, '@ symbol should be encoded as %40' );

		// Should not contain raw @ symbol.
		$this->assertStringNotContainsString( '@', $escaped_model, 'Raw @ symbol should not be present after encoding' );

		// Expected result.
		$this->assertEquals( '%40cf/meta/llama-3.1-8b-instruct', $escaped_model );
	}

	/**
	 * Test URL construction with properly escaped model ID.
	 */
	public function test_url_construction_with_model_id() {
		$account_id    = 'test-account-123';
		$model         = '@cf/meta/llama-3.1-8b-instruct';
		$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

		$url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
			rawurlencode( $account_id ),
			$escaped_model
		);

		// URL should contain properly escaped @ symbol.
		$this->assertStringContainsString( '%40cf', $url, 'URL should contain %40 for @ symbol' );

		// URL should contain forward slashes, not %2F.
		$this->assertStringContainsString( '/meta/', $url, 'URL should contain unencoded forward slashes' );
		$this->assertStringNotContainsString( '%2F', $url, 'URL should not contain %2F (encoded slash)' );

		// Expected URL.
		$expected = 'https://api.cloudflare.com/client/v4/accounts/test-account-123/ai/run/%40cf/meta/llama-3.1-8b-instruct';
		$this->assertEquals( $expected, $url );
	}

	/**
	 * Test that spaces in model IDs are properly encoded.
	 */
	public function test_model_id_with_spaces() {
		$model         = '@cf/example/model with spaces';
		$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

		// Spaces should be encoded.
		$this->assertStringContainsString( '%20', $escaped_model, 'Spaces should be encoded as %20' );
		$this->assertStringNotContainsString( ' ', $escaped_model, 'Raw spaces should not be present after encoding' );

		// Forward slashes should still be preserved.
		$this->assertStringContainsString( '/', $escaped_model, 'Forward slashes should be preserved even with spaces' );

		// Expected result.
		$this->assertEquals( '%40cf/example/model%20with%20spaces', $escaped_model );
	}

	/**
	 * Test various Cloudflare model IDs from the catalog.
	 */
	public function test_various_cloudflare_model_ids() {
		$model_ids = array(
			'@cf/meta/llama-3.1-8b-instruct',
			'@cf/meta/llama-3.1-8b-instruct-fast',
			'@cf/meta/llama-3.2-1b-instruct',
			'@cf/mistralai/mistral-7b-instruct-v0.1',
			'@cf/qwen/qwen1.5-0.5b-chat',
			'@cf/microsoft/phi-2',
		);

		foreach ( $model_ids as $model ) {
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

			// All should have @ encoded.
			$this->assertStringContainsString( '%40', $escaped_model, "Model ID '{$model}' should have @ encoded" );

			// All should preserve forward slashes.
			$this->assertStringContainsString( '/', $escaped_model, "Model ID '{$model}' should preserve forward slashes" );

			// None should have encoded slashes.
			$this->assertStringNotContainsString( '%2F', $escaped_model, "Model ID '{$model}' should not have encoded slashes" );
		}
	}

	/**
	 * Test that the wrong approach (using rawurlencode) breaks the URL.
	 */
	public function test_rawurlencode_breaks_url() {
		$model = '@cf/meta/llama-3.1-8b-instruct';

		// Wrong approach: using rawurlencode.
		$wrong_encoded = rawurlencode( $model );

		// This will encode slashes as %2F, which breaks the URL path.
		$this->assertStringContainsString( '%2F', $wrong_encoded, 'rawurlencode incorrectly encodes slashes' );
		$this->assertEquals( '%40cf%2Fmeta%2Fllama-3.1-8b-instruct', $wrong_encoded );

		// Correct approach: selective encoding.
		$correct_encoded = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );
		$this->assertStringNotContainsString( '%2F', $correct_encoded, 'Correct encoding should not encode slashes' );
		$this->assertEquals( '%40cf/meta/llama-3.1-8b-instruct', $correct_encoded );
	}
}
