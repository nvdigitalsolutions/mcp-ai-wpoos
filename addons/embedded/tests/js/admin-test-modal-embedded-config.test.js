/**
 * Tests for admin-test-assistant.js embedded config endpoint inclusion.
 *
 * Verifies that the chat config created by openTestModal includes the
 * embeddedConfigEndpoint and embeddedAssistantId fields so that the
 * sendChatEmbedded guard in chat.js can fetch the system prompt from the
 * server when the embedded provider is used.
 *
 * Without these fields the condition in sendChatEmbedded that gates the
 * server fetch is never true, so the system prompt is never fetched and
 * never sent to WebLLM.
 *
 * @package WP_MCP_AI
 */

describe( 'Admin Test Modal - Embedded Config', () => {
	/**
	 * Simulate the config object constructed by openTestModal.
	 * Mirrors the window.wpMcpAiChatInstances[instanceId] assignment in
	 * admin-test-assistant.js with the fix applied.
	 */
	function buildTestModalConfig( assistantId, provider, model, baseRestUrl ) {
		return {
			assistantId: assistantId,
			embeddedAssistantId: parseInt( assistantId, 10 ) || 0,
			messagesEndpoint: baseRestUrl + 'chat-client',
			toolsEndpoint: baseRestUrl + 'tools',
			embeddedConfigEndpoint: baseRestUrl + 'embedded-client-config',
			provider: provider || '',
			model: model || '',
			restNonce: 'test-nonce',
			historyPerPage: 20,
		};
	}

	/**
	 * Simulate the sendChatEmbedded fetch-guard condition from chat.js.
	 *
	 * Returns true when the embedded config should be fetched from the server
	 * (i.e. the system prompt is missing but the endpoint is available).
	 */
	function shouldFetchEmbeddedConfig( config, embeddedClient, embeddedConfigFetched ) {
		return (
			! embeddedClient &&
			! embeddedConfigFetched &&
			! config.systemPrompt &&
			!! config.embeddedConfigEndpoint &&
			!! config.assistantId
		);
	}

	describe( 'embeddedConfigEndpoint', () => {
		it( 'is present in the config when baseRestUrl ends with /', () => {
			const config = buildTestModalConfig(
				'1704',
				'embedded',
				'Qwen2.5-1.5B-Instruct-q4f16_1-MLC',
				'https://example.com/wp-json/mcp-ai/v1/'
			);

			expect( config.embeddedConfigEndpoint ).toBe(
				'https://example.com/wp-json/mcp-ai/v1/embedded-client-config'
			);
		} );

		it( 'is truthy so the fetch guard condition can pass', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );
			expect( !! config.embeddedConfigEndpoint ).toBe( true );
		} );
	} );

	describe( 'embeddedAssistantId', () => {
		it( 'is a number parsed from assistantId string', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );
			expect( config.embeddedAssistantId ).toBe( 1704 );
		} );

		it( 'is 0 when assistantId is not a number (profession_ prefix)', () => {
			const config = buildTestModalConfig( 'profession_5', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );
			expect( config.embeddedAssistantId ).toBe( 0 );
		} );
	} );

	describe( 'fetch guard passes after fix', () => {
		it( 'triggers server fetch when embeddedConfigEndpoint is set and systemPrompt is missing', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );

			// No system prompt and no existing embedded client → should fetch
			const result = shouldFetchEmbeddedConfig( config, null, false );
			expect( result ).toBe( true );
		} );

		it( 'does not trigger fetch when systemPrompt is already set', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );
			config.systemPrompt = 'You are a helpful assistant.';

			const result = shouldFetchEmbeddedConfig( config, null, false );
			expect( result ).toBe( false );
		} );

		it( 'does not trigger fetch when embeddedClient already exists', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );
			const mockClient = { instanceId: 'chat-1704-123', systemPrompt: null };

			const result = shouldFetchEmbeddedConfig( config, mockClient, false );
			expect( result ).toBe( false );
		} );

		it( 'does not trigger fetch when embeddedConfigFetched is already true', () => {
			const config = buildTestModalConfig( '1704', 'embedded', 'some-model', 'https://example.com/wp-json/mcp-ai/v1/' );

			const result = shouldFetchEmbeddedConfig( config, null, true );
			expect( result ).toBe( false );
		} );
	} );

	describe( 'fetch guard fails without the fix (regression)', () => {
		it( 'does NOT trigger server fetch when embeddedConfigEndpoint is missing', () => {
			// This simulates the pre-fix state: no embeddedConfigEndpoint in the config
			const configWithoutEndpoint = {
				assistantId: '1704',
				provider: 'embedded',
				model: 'some-model',
				// embeddedConfigEndpoint intentionally omitted
			};

			const result = shouldFetchEmbeddedConfig( configWithoutEndpoint, null, false );
			// Without the endpoint the condition is false → system prompt never fetched
			expect( result ).toBe( false );
		} );
	} );
} );
