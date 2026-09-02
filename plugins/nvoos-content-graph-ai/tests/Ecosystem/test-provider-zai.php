<?php
/**
 * Z.AI provider client port tests (Wave D2a).
 *
 * Characterization suite for `NvoosContentGraphAi\Provider\ZaiClient`.
 * The client reuses nvoos/core's `OpenAiCompatibleClient`, so the suite
 * pins the provider identity contract against the base plugin's Z.AI
 * client: slug, default endpoint (https://api.z.ai/api/paas/v4), API-key
 * requirement, OpenAI-compatible request shape, SSE stream assembly,
 * models listing, base-URL override, and CoreBridge registration.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use NvoosContentGraphAi\Adapter\ContentGraphSettingsStore;
use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\Provider\ZaiClient;
use Nvoos\WordPress\Adapter\ErrorFactory;

/**
 * Test double exposing the protected default-URL contract.
 */
class Testable_Zai_Client extends ZaiClient {

	public function expose_default_base_url(): string {
		return $this->getDefaultBaseUrl();
	}
}

/**
 * HTTP client double recording every request and replaying queued responses.
 */
class Zai_Http_Double implements HttpClientInterface {

	/** @var array<int, array{method:string,url:string,headers:array,body:?string}> */
	public $requests = array();

	/** @var array<int, HttpResponse> */
	public $responses = array();

	public function send( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		return array_shift( $this->responses )
			?? new HttpResponse( 200, '{}', array() );
	}
}

/**
 * @group provider
 */
class Test_Provider_Zai extends \WP_UnitTestCase {

	private ContentGraphSettingsStore $settings;

	private Zai_Http_Double $http;

	private ErrorFactory $errors;

	private ZaiClient $client;

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		CredentialResolver::clearCache();

		$this->settings = new ContentGraphSettingsStore();
		$this->http     = new Zai_Http_Double();
		$this->errors   = new ErrorFactory();
		$this->client   = new ZaiClient( $this->settings, $this->http, $this->errors );
	}

	public function tearDown(): void {
		\NvoosContentGraphAi\Security\CredentialStore::delete( 'zai' );
		\delete_option( 'nvoos_content_graph_settings' );
		CredentialResolver::clearCache();

		parent::tearDown();
	}

	/**
	 * Seed an API key for this provider (routes through CredentialStore).
	 */
	private function seed_key( string $key ): void {
		$this->settings->set( 'ai_api_key_zai', $key );
		CredentialResolver::clearCache();
	}

	public function test_slug_and_default_base_url(): void {
		$this->assertSame( 'zai', $this->client->getProviderSlug() );

		$exposed = new Testable_Zai_Client( $this->settings, $this->http, $this->errors );
		$this->assertSame( 'https://api.z.ai/api/paas/v4', $exposed->expose_default_base_url() );
	}

	public function test_missing_api_key_error(): void {
		$result = $this->client->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_chat_builds_openai_compatible_request(): void {
		$this->seed_key( 'sk-zai-test' );
		$this->http->responses[] = new HttpResponse(
			200,
			'{"id":"z","object":"chat.completion","model":"glm-5.2","choices":[{"index":0,"message":{"role":"assistant","content":"hi"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1}}'
		);

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(
				'model'       => 'glm-5.2',
				'temperature' => 0.4,
				'max_tokens'  => 100,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'hi', $result['choices'][0]['message']['content'] );

		$this->assertCount( 1, $this->http->requests );
		$request = $this->http->requests[0];

		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.z.ai/api/paas/v4/chat/completions', $request['url'] );
		$this->assertSame( 'Bearer sk-zai-test', $request['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );

		$payload = \json_decode( (string) $request['body'], true );
		$this->assertSame( 'glm-5.2', $payload['model'] );
		$this->assertSame( array( array( 'role' => 'user', 'content' => 'hi' ) ), $payload['messages'] );
		$this->assertSame( 0.4, $payload['temperature'] );
		$this->assertSame( 100, $payload['max_tokens'] );
	}

	public function test_stream_assembles_tokens_and_tool_calls(): void {
		$this->seed_key( 'sk-zai-test' );
		$this->http->responses[] = new HttpResponse(
			200,
			implode(
				"\n",
				array(
					'data: {"choices":[{"delta":{"content":"Hel"},"finish_reason":null}]}',
					'',
					'data: {"choices":[{"delta":{"content":"lo"},"finish_reason":null}]}',
					'',
					'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"get_weather","arguments":"{\\"city\\":"}}]},"finish_reason":null}]}',
					'',
					'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\\"Paris\\"}"}}]},"finish_reason":null}]}',
					'',
					'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}',
					'',
					'data: [DONE]',
				)
			)
		);

		$chunks = array();
		$result = $this->client->stream(
			array( array( 'role' => 'user', 'content' => 'weather in Paris?' ) ),
			array( 'model' => 'glm-5.2' ),
			static function ( string $token ) use ( &$chunks ): void {
				$chunks[] = $token;
			}
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'Hel', 'lo' ), $chunks );
		$this->assertSame( 'Hello', $result['choices'][0]['message']['content'] );
		$this->assertSame( 'stop', $result['choices'][0]['finish_reason'] );

		$tool_calls = $result['choices'][0]['message']['tool_calls'];
		$this->assertCount( 1, $tool_calls );
		$this->assertSame( 'call_1', $tool_calls[0]['id'] );
		$this->assertSame( 'get_weather', $tool_calls[0]['function']['name'] );
		$this->assertSame( '{"city":"Paris"}', $tool_calls[0]['function']['arguments'] );

		$this->assertSame( 'POST', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/chat/completions', $this->http->requests[0]['url'] );
	}

	public function test_list_models_requests_models_endpoint(): void {
		$this->seed_key( 'sk-zai-test' );
		$this->http->responses[] = new HttpResponse(
			200,
			'{"object":"list","data":[{"id":"glm-4"},{"id":"glm-5.2"},{"id":"glm-4-flash"}]}'
		);

		$models = $this->client->listModels();

		$this->assertSame( array( 'glm-4', 'glm-4-flash', 'glm-5.2' ), $models );

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://api.z.ai/api/paas/v4/models', $this->http->requests[0]['url'] );
	}

	public function test_base_url_override(): void {
		$this->seed_key( 'sk-zai-test' );
		$this->settings->set( 'zai_base_url', 'https://proxy.example.com/v4/' );
		$this->http->responses[] = new HttpResponse(
			200,
			'{"choices":[{"message":{"content":"hi"}}]}'
		);

		$this->client->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame(
			'https://proxy.example.com/v4/chat/completions',
			$this->http->requests[0]['url']
		);
	}

	public function test_registered_in_core_bridge(): void {
		$slugs = \NvoosContentGraphAi\CoreBridge::instance()->providers->getRegisteredSlugs();

		$this->assertContains( 'zai', $slugs );
	}
}
