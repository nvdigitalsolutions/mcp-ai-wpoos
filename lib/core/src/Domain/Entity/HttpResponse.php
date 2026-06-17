<?php
/**
 * HTTP response value object for the oOS AI orchestration core.
 *
 * Immutable data carrier returned by {@see HttpClientInterface::send()}.
 * Simple struct — no PSR-7 stream wrapping, no mutability.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

/**
 * Immutable HTTP response.
 */
final readonly class HttpResponse implements \JsonSerializable {

	/**
	 * @param int    $statusCode HTTP status code (200, 404, 500, etc.).
	 * @param string $body       Response body as a string.
	 * @param array  $headers    Response headers as key-value pairs.
	 */
	public function __construct(
		public int $statusCode,
		public string $body,
		public array $headers = array(),
	) {}

	/**
	 * JSON-serializable for logging and debugging.
	 *
	 * @return array{statusCode: int, body: string, headers: array}
	 */
	public function jsonSerialize(): array {
		return array(
			'statusCode' => $this->statusCode,
			'body'       => $this->body,
			'headers'    => $this->headers,
		);
	}
}
