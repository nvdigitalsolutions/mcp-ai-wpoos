<?php
/**
 * Tests for the infrastructure provider client adapters.
 *
 * Verifies that every adapter class:
 *   1. Implements Interface_WP_MCP_AI_Provider_Client.
 *   2. Returns the correct provider slug.
 *   3. Delegates chat(), stream(), and list_models() to the underlying
 *      concrete client.
 *   4. Forwards stream callbacks and sets stream=true in options.
 *   5. Accepts an injected concrete client via the constructor.
 *
 * These tests use lightweight stub clients to avoid real HTTP calls.
 *
 * @package WP_MCP_AI
 * @group   infrastructure
 * @group   provider-client
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file contains multiple stub classes by design.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- test file contains multiple stub classes by design.

// ---------------------------------------------------------------------------
// Stub helpers
// ---------------------------------------------------------------------------

/**
 * Captures calls made through the OpenAI adapter.
 */
class Stub_WP_MCP_AI_OpenAI_Client extends WP_MCP_AI_OpenAI_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Last stream arguments passed to create_chat_completion() in stream mode.
	 *
	 * @var array|null
	 */
	public $last_stream_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'openai_chat' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @param array $args Optional arguments.
	 * @return array
	 */
	public function list_models( array $args = array() ) {
		$this->models_called = true;
		return array( array( 'id' => 'gpt-test' ) );
	}
}

/**
 * Captures calls made through the Gemini adapter.
 */
class Stub_WP_MCP_AI_Gemini_Client extends WP_MCP_AI_Gemini_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Last stream arguments passed to stream_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_stream_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'gemini_chat' );
	}

	/**
	 * Stub: capture stream arguments and return a predictable value.
	 *
	 * @param array         $messages Chat messages.
	 * @param array         $options  Optional request options.
	 * @param callable|null $callback Optional streaming callback.
	 * @return array
	 */
	public function stream_chat_completion( array $messages, array $options = array(), $callback = null ) {
		$this->last_stream_args = array(
			'messages' => $messages,
			'options'  => $options,
			'callback' => $callback,
		);
		return array( 'stub' => 'gemini_stream' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @param array $options Optional options.
	 * @return array
	 */
	public function list_models( array $options = array() ) {
		$this->models_called = true;
		return array( array( 'name' => 'gemini-test' ) );
	}
}

/**
 * Captures calls made through the Ollama adapter.
 */
class Stub_WP_MCP_AI_Ollama_Client extends WP_MCP_AI_Ollama_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'ollama_chat' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @param array $args Optional arguments.
	 * @return array
	 */
	public function list_models( array $args = array() ) {
		$this->models_called = true;
		return array( array( 'name' => 'llama-test' ) );
	}
}

/**
 * Captures calls made through the Anthropic adapter.
 */
class Stub_WP_MCP_AI_Anthropic_Client extends WP_MCP_AI_Anthropic_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'anthropic_chat' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @param array $options Optional options.
	 * @return array
	 */
	public function list_models( array $options = array() ) {
		$this->models_called = true;
		return array( array( 'id' => 'claude-test' ) );
	}
}

/**
 * Captures calls made through the Cloudflare adapter.
 */
class Stub_WP_MCP_AI_Cloudflare_Client extends WP_MCP_AI_Cloudflare_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'cloudflare_chat' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @return array
	 */
	public function list_models() {
		$this->models_called = true;
		return array( array( 'id' => '@cf/test' ) );
	}
}

/**
 * Captures calls made through the LM Studio adapter.
 */
class Stub_WP_MCP_AI_LM_Studio_Client extends WP_MCP_AI_LM_Studio_Client {
	/**
	 * Last chat arguments passed to create_chat_completion().
	 *
	 * @var array|null
	 */
	public $last_chat_args = null;

	/**
	 * Whether list_models() has been called.
	 *
	 * @var bool
	 */
	public $models_called = false;

	/**
	 * Stub: capture chat arguments and return a predictable value.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Optional request options.
	 * @return array
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->last_chat_args = array(
			'messages' => $messages,
			'options'  => $options,
		);
		return array( 'stub' => 'lm_studio_chat' );
	}

	/**
	 * Stub: record that list_models was called and return a predictable value.
	 *
	 * @return array
	 */
	public function list_models() {
		$this->models_called = true;
		return array( array( 'id' => 'local-test' ) );
	}
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

/**
 * Test case for the provider adapter classes.
 */
class Test_Provider_Client_Adapters extends WP_UnitTestCase {

	/**
	 * Sample chat messages used across tests.
	 *
	 * @var array
	 */
	private $messages;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
	}

	// =========================================================================
	// OpenAI
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_openai_implements_interface() {
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "openai" as its provider slug.
	 *
	 * @return void
	 */
	public function test_openai_slug() {
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client();
		$this->assertSame( 'openai', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_openai_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_OpenAI_Client();
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'openai_chat' ), $result );
		$this->assertNotNull( $stub->last_chat_args );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Passes stream=true in options to the concrete client.
	 *
	 * @return void
	 */
	public function test_openai_stream_sets_stream_flag() {
		$stub    = new Stub_WP_MCP_AI_OpenAI_Client();
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertTrue( $stub->last_chat_args['options']['stream'] );
	}

	/**
	 * Forwards a callback via the stream_callback option.
	 *
	 * @return void
	 */
	public function test_openai_stream_forwards_callback_via_options() {
		$stub     = new Stub_WP_MCP_AI_OpenAI_Client();
		$adapter  = new WP_MCP_AI_OpenAI_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_chat_args['options']['stream_callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_openai_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_OpenAI_Client();
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client( $stub );

		$result = $adapter->list_models();

		$this->assertTrue( $stub->models_called );
		$this->assertIsArray( $result );
	}

	/**
	 * Default constructor must create a concrete client instance internally.
	 *
	 * @return void
	 */
	public function test_openai_default_constructor_creates_concrete_client() {
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client();
		// Only verifies the adapter can be instantiated without arguments.
		$this->assertInstanceOf( WP_MCP_AI_OpenAI_Provider_Client::class, $adapter );
	}

	// =========================================================================
	// Gemini
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_gemini_implements_interface() {
		$adapter = new WP_MCP_AI_Gemini_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "gemini" as its provider slug.
	 *
	 * @return void
	 */
	public function test_gemini_slug() {
		$adapter = new WP_MCP_AI_Gemini_Provider_Client();
		$this->assertSame( 'gemini', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_gemini_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_Gemini_Client();
		$adapter = new WP_MCP_AI_Gemini_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'gemini_chat' ), $result );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Calls stream_chat_completion() on the Gemini concrete client.
	 *
	 * @return void
	 */
	public function test_gemini_stream_calls_stream_chat_completion() {
		$stub    = new Stub_WP_MCP_AI_Gemini_Client();
		$adapter = new WP_MCP_AI_Gemini_Provider_Client( $stub );

		$result = $adapter->stream( $this->messages );

		$this->assertSame( array( 'stub' => 'gemini_stream' ), $result );
		$this->assertNotNull( $stub->last_stream_args );
		$this->assertSame( $this->messages, $stub->last_stream_args['messages'] );
	}

	/**
	 * Forwards the callback to stream_chat_completion().
	 *
	 * @return void
	 */
	public function test_gemini_stream_passes_callback() {
		$stub     = new Stub_WP_MCP_AI_Gemini_Client();
		$adapter  = new WP_MCP_AI_Gemini_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_stream_args['callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_gemini_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_Gemini_Client();
		$adapter = new WP_MCP_AI_Gemini_Provider_Client( $stub );

		$adapter->list_models();

		$this->assertTrue( $stub->models_called );
	}

	// =========================================================================
	// Ollama
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_ollama_implements_interface() {
		$adapter = new WP_MCP_AI_Ollama_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "ollama" as its provider slug.
	 *
	 * @return void
	 */
	public function test_ollama_slug() {
		$adapter = new WP_MCP_AI_Ollama_Provider_Client();
		$this->assertSame( 'ollama', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_ollama_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_Ollama_Client();
		$adapter = new WP_MCP_AI_Ollama_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'ollama_chat' ), $result );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Passes stream=true in options to the concrete client.
	 *
	 * @return void
	 */
	public function test_ollama_stream_sets_stream_flag() {
		$stub    = new Stub_WP_MCP_AI_Ollama_Client();
		$adapter = new WP_MCP_AI_Ollama_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertTrue( $stub->last_chat_args['options']['stream'] );
	}

	/**
	 * Forwards a callback via the stream_callback option.
	 *
	 * @return void
	 */
	public function test_ollama_stream_forwards_callback_via_options() {
		$stub     = new Stub_WP_MCP_AI_Ollama_Client();
		$adapter  = new WP_MCP_AI_Ollama_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_chat_args['options']['stream_callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_ollama_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_Ollama_Client();
		$adapter = new WP_MCP_AI_Ollama_Provider_Client( $stub );

		$adapter->list_models();

		$this->assertTrue( $stub->models_called );
	}

	// =========================================================================
	// Anthropic
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_anthropic_implements_interface() {
		$adapter = new WP_MCP_AI_Anthropic_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "anthropic" as its provider slug.
	 *
	 * @return void
	 */
	public function test_anthropic_slug() {
		$adapter = new WP_MCP_AI_Anthropic_Provider_Client();
		$this->assertSame( 'anthropic', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_anthropic_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_Anthropic_Client();
		$adapter = new WP_MCP_AI_Anthropic_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'anthropic_chat' ), $result );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Passes stream=true in options to the concrete client.
	 *
	 * @return void
	 */
	public function test_anthropic_stream_sets_stream_flag() {
		$stub    = new Stub_WP_MCP_AI_Anthropic_Client();
		$adapter = new WP_MCP_AI_Anthropic_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertTrue( $stub->last_chat_args['options']['stream'] );
	}

	/**
	 * Forwards a callback via the stream_callback option.
	 *
	 * @return void
	 */
	public function test_anthropic_stream_forwards_callback_via_options() {
		$stub     = new Stub_WP_MCP_AI_Anthropic_Client();
		$adapter  = new WP_MCP_AI_Anthropic_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_chat_args['options']['stream_callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_anthropic_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_Anthropic_Client();
		$adapter = new WP_MCP_AI_Anthropic_Provider_Client( $stub );

		$result = $adapter->list_models();

		$this->assertTrue( $stub->models_called );
		$this->assertIsArray( $result );
	}

	// =========================================================================
	// Cloudflare
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_cloudflare_implements_interface() {
		$adapter = new WP_MCP_AI_Cloudflare_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "cloudflare" as its provider slug.
	 *
	 * @return void
	 */
	public function test_cloudflare_slug() {
		$adapter = new WP_MCP_AI_Cloudflare_Provider_Client();
		$this->assertSame( 'cloudflare', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_cloudflare_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_Cloudflare_Client();
		$adapter = new WP_MCP_AI_Cloudflare_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'cloudflare_chat' ), $result );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Passes stream=true in options to the concrete client.
	 *
	 * @return void
	 */
	public function test_cloudflare_stream_sets_stream_flag() {
		$stub    = new Stub_WP_MCP_AI_Cloudflare_Client();
		$adapter = new WP_MCP_AI_Cloudflare_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertTrue( $stub->last_chat_args['options']['stream'] );
	}

	/**
	 * Forwards a callback via the stream_callback option.
	 *
	 * @return void
	 */
	public function test_cloudflare_stream_forwards_callback_via_options() {
		$stub     = new Stub_WP_MCP_AI_Cloudflare_Client();
		$adapter  = new WP_MCP_AI_Cloudflare_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_chat_args['options']['stream_callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_cloudflare_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_Cloudflare_Client();
		$adapter = new WP_MCP_AI_Cloudflare_Provider_Client( $stub );

		$result = $adapter->list_models();

		$this->assertTrue( $stub->models_called );
		$this->assertIsArray( $result );
	}

	// =========================================================================
	// LM Studio
	// =========================================================================

	/**
	 * Adapter must implement the provider client interface.
	 *
	 * @return void
	 */
	public function test_lm_studio_implements_interface() {
		$adapter = new WP_MCP_AI_LM_Studio_Provider_Client();
		$this->assertInstanceOf( Interface_WP_MCP_AI_Provider_Client::class, $adapter );
	}

	/**
	 * Adapter must return "lm_studio" as its provider slug.
	 *
	 * @return void
	 */
	public function test_lm_studio_slug() {
		$adapter = new WP_MCP_AI_LM_Studio_Provider_Client();
		$this->assertSame( 'lm_studio', $adapter->get_provider_slug() );
	}

	/**
	 * Delegates chat() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_lm_studio_chat_delegates_to_concrete_client() {
		$stub    = new Stub_WP_MCP_AI_LM_Studio_Client();
		$adapter = new WP_MCP_AI_LM_Studio_Provider_Client( $stub );

		$result = $adapter->chat( $this->messages );

		$this->assertSame( array( 'stub' => 'lm_studio_chat' ), $result );
		$this->assertSame( $this->messages, $stub->last_chat_args['messages'] );
	}

	/**
	 * Passes stream=true in options to the concrete client.
	 *
	 * @return void
	 */
	public function test_lm_studio_stream_sets_stream_flag() {
		$stub    = new Stub_WP_MCP_AI_LM_Studio_Client();
		$adapter = new WP_MCP_AI_LM_Studio_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertTrue( $stub->last_chat_args['options']['stream'] );
	}

	/**
	 * Forwards a callback via the stream_callback option.
	 *
	 * @return void
	 */
	public function test_lm_studio_stream_forwards_callback_via_options() {
		$stub     = new Stub_WP_MCP_AI_LM_Studio_Client();
		$adapter  = new WP_MCP_AI_LM_Studio_Provider_Client( $stub );
		$callback = function ( $chunk, $done ) {};

		$adapter->stream( $this->messages, array(), $callback );

		$this->assertSame( $callback, $stub->last_chat_args['options']['stream_callback'] );
	}

	/**
	 * Delegates list_models() to the injected concrete client.
	 *
	 * @return void
	 */
	public function test_lm_studio_list_models_delegates() {
		$stub    = new Stub_WP_MCP_AI_LM_Studio_Client();
		$adapter = new WP_MCP_AI_LM_Studio_Provider_Client( $stub );

		$result = $adapter->list_models();

		$this->assertTrue( $stub->models_called );
		$this->assertIsArray( $result );
	}

	// =========================================================================
	// Cross-adapter consistency
	// =========================================================================

	/**
	 * Every adapter should return a non-empty, lowercase string slug.
	 *
	 * @dataProvider provider_slug_data
	 *
	 * @param string $adapter_class Fully-qualified adapter class name.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provider_slug_data' )]
	public function test_all_slugs_are_non_empty_strings( $adapter_class ) {
		$adapter = new $adapter_class();
		$slug    = $adapter->get_provider_slug();

		$this->assertIsString( $slug );
		$this->assertNotEmpty( $slug );
		$this->assertSame( strtolower( $slug ), $slug, 'Provider slug should be lowercase' );
	}

	/**
	 * Data provider for slug tests.
	 *
	 * Static as required by PHPUnit 10+.
	 *
	 * @return array
	 */
	public static function provider_slug_data() {
		return array(
			'openai'     => array( 'WP_MCP_AI_OpenAI_Provider_Client' ),
			'gemini'     => array( 'WP_MCP_AI_Gemini_Provider_Client' ),
			'ollama'     => array( 'WP_MCP_AI_Ollama_Provider_Client' ),
			'anthropic'  => array( 'WP_MCP_AI_Anthropic_Provider_Client' ),
			'cloudflare' => array( 'WP_MCP_AI_Cloudflare_Provider_Client' ),
			'lm_studio'  => array( 'WP_MCP_AI_LM_Studio_Provider_Client' ),
		);
	}

	/**
	 * Every adapter should have unique slugs.
	 *
	 * @return void
	 */
	public function test_all_slugs_are_unique() {
		$adapters = array(
			new WP_MCP_AI_OpenAI_Provider_Client(),
			new WP_MCP_AI_Gemini_Provider_Client(),
			new WP_MCP_AI_Ollama_Provider_Client(),
			new WP_MCP_AI_Anthropic_Provider_Client(),
			new WP_MCP_AI_Cloudflare_Provider_Client(),
			new WP_MCP_AI_LM_Studio_Provider_Client(),
		);

		$slugs = array_map(
			function ( $a ) {
				return $a->get_provider_slug();
			},
			$adapters
		);

		$unique = array_unique( $slugs );

		$this->assertCount( count( $adapters ), $unique, 'All provider slugs must be unique' );
	}

	/**
	 * Stream without a callback should not set stream_callback in options.
	 *
	 * Verifies stream() without a callback does not throw when the underlying client
	 * simply receives stream=true in options (e.g. OpenAI adapter path).
	 *
	 * @return void
	 */
	public function test_stream_without_callback_does_not_set_stream_callback_option() {
		$stub    = new Stub_WP_MCP_AI_OpenAI_Client();
		$adapter = new WP_MCP_AI_OpenAI_Provider_Client( $stub );

		$adapter->stream( $this->messages );

		$this->assertArrayNotHasKey( 'stream_callback', $stub->last_chat_args['options'] );
	}
}
