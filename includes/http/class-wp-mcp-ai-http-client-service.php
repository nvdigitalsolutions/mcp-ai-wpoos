<?php
/**
 * HTTP Client Service
 *
 * Provides Symfony HttpClient integration for NV oOS.
 * Wraps external HTTP requests with retry logic, timeout management,
 * and streaming support (e.g. for Server-Sent Events).
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Class WP_MCP_AI_Http_Client_Service
 *
 * Wraps Symfony HttpClient for WordPress plugin use.
 * Provides streaming, retry logic, and private-network blocking
 * for external (non-WordPress-loopback) HTTP requests.
 *
 * Note: WordPress loopback and local-AI requests (Ollama, LM Studio, etc.)
 * must continue to use wp_remote_get/post so that WordPress HTTP filters,
 * proxy settings, and SSL overrides are respected. This service is intended
 * for external API calls where streaming or advanced retry behaviour is needed.
 */
class WP_MCP_AI_Http_Client_Service {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Http_Client_Service|null
	 */
	private static $instance = null;

	/**
	 * Underlying Symfony HTTP client.
	 *
	 * @var HttpClientInterface
	 */
	private $client;

	/**
	 * Default request timeout in seconds.
	 *
	 * @var float
	 */
	private $default_timeout = 30.0;

	/**
	 * Maximum number of automatic retries.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Http_Client_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->client = $this->create_client();
	}

	/**
	 * Create the Symfony HTTP client with retry support.
	 *
	 * Wraps the base client with RetryableHttpClient for automatic
	 * retry on transient network errors (5xx, timeouts).
	 *
	 * @return HttpClientInterface
	 */
	private function create_client() {
		$options = array(
			'timeout'         => $this->default_timeout,
			'max_redirects'   => 5,
			'headers'         => array(
				'User-Agent' => 'WP-MCP-AI/' . ( defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0' ),
			),
		);

		$base_client = HttpClient::create( $options );

		return new RetryableHttpClient( $base_client, null, $this->max_retries );
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $url     Target URL.
	 * @param array  $options Symfony HttpClient request options.
	 * @return array|\WP_Error Response array with 'status', 'headers', 'body' on success,
	 *                         or WP_Error on transport failure.
	 */
	public function get( $url, array $options = array() ) {
		return $this->request( 'GET', $url, $options );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $url     Target URL.
	 * @param array  $options Symfony HttpClient request options (e.g. 'json', 'body').
	 * @return array|\WP_Error Response array with 'status', 'headers', 'body' on success,
	 *                         or WP_Error on transport failure.
	 */
	public function post( $url, array $options = array() ) {
		return $this->request( 'POST', $url, $options );
	}

	/**
	 * Perform an arbitrary HTTP request.
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, etc.).
	 * @param string $url     Target URL.
	 * @param array  $options Symfony HttpClient request options.
	 * @return array|\WP_Error Response array with 'status', 'headers', 'body' on success,
	 *                         or WP_Error on transport failure.
	 */
	public function request( $method, $url, array $options = array() ) {
		try {
			$response = $this->client->request( $method, $url, $options );

			return array(
				'status'  => $response->getStatusCode(),
				'headers' => $response->getHeaders( false ),
				'body'    => $response->getContent( false ),
			);
		} catch ( TransportExceptionInterface $e ) {
			return new \WP_Error(
				'http_client_transport_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP transport error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'http_client_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP client error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Stream a response chunk by chunk.
	 *
	 * Useful for consuming Server-Sent Events (SSE) or large response bodies
	 * without buffering the entire payload in memory.
	 *
	 * @param string   $url      Target URL.
	 * @param callable $callback Called for each chunk: function( string $chunk, bool $first, bool $last ): bool.
	 *                           Return false from the callback to abort streaming.
	 * @param array    $options  Symfony HttpClient request options.
	 * @param string   $method   HTTP method (default: GET).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function stream( $url, callable $callback, array $options = array(), $method = 'GET' ) {
		try {
			$response = $this->client->request( $method, $url, $options );
			$first     = true;

			foreach ( $this->client->stream( $response ) as $chunk ) {
				if ( $chunk->isTimeout() ) {
					continue;
				}

				if ( $chunk->isLast() ) {
					$callback( '', false, true );
					break;
				}

				$content = $chunk->getContent();
				if ( '' === $content ) {
					continue;
				}

				$continue = $callback( $content, $first, false );
				$first    = false;

				if ( false === $continue ) {
					$response->cancel();
					break;
				}
			}

			return true;
		} catch ( TransportExceptionInterface $e ) {
			return new \WP_Error(
				'http_client_stream_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP stream error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'http_client_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP client error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Set the default request timeout.
	 *
	 * Rebuilds the client only if the timeout value actually changed.
	 *
	 * @param float $timeout Timeout in seconds.
	 */
	public function set_default_timeout( $timeout ) {
		$timeout = (float) $timeout;
		if ( $timeout === $this->default_timeout ) {
			return;
		}
		$this->default_timeout = $timeout;
		$this->client          = $this->create_client();
	}

	/**
	 * Get the default request timeout.
	 *
	 * @return float Timeout in seconds.
	 */
	public function get_default_timeout() {
		return $this->default_timeout;
	}

	/**
	 * Set the maximum number of automatic retries.
	 *
	 * Rebuilds the client only if the retry count actually changed.
	 *
	 * @param int $max_retries Maximum retries (0 to disable).
	 */
	public function set_max_retries( $max_retries ) {
		$max_retries = absint( $max_retries );
		if ( $max_retries === $this->max_retries ) {
			return;
		}
		$this->max_retries = $max_retries;
		$this->client      = $this->create_client();
	}

	/**
	 * Get the maximum number of automatic retries.
	 *
	 * @return int Maximum retries.
	 */
	public function get_max_retries() {
		return $this->max_retries;
	}

	/**
	 * Get the underlying Symfony HttpClient instance.
	 *
	 * For advanced operations not covered by the wrapper methods.
	 *
	 * @return HttpClientInterface
	 */
	public function get_client() {
		return $this->client;
	}
}
