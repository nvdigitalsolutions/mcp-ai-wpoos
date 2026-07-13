<?php
/**
 * Tests for HttpResponse value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase {

	public function testSuccessfulResponse(): void {
		$response = new HttpResponse(
			statusCode: 200,
			body: '{"ok":true}',
			headers: array( 'Content-Type' => 'application/json' ),
		);

		$this->assertSame( 200, $response->statusCode );
		$this->assertSame( '{"ok":true}', $response->body );
		$this->assertSame(
			array( 'Content-Type' => 'application/json' ),
			$response->headers,
		);
	}

	public function testDefaultHeadersAreEmpty(): void {
		$response = new HttpResponse(
			statusCode: 404,
			body: 'Not Found',
		);

		$this->assertSame( 404, $response->statusCode );
		$this->assertSame( 'Not Found', $response->body );
		$this->assertSame( array(), $response->headers );
	}

	public function testJsonSerialize(): void {
		$response = new HttpResponse(
			statusCode: 201,
			body: '{"created":true}',
			headers: array( 'Location' => '/items/1' ),
		);

		$json = $response->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 201, $json['statusCode'] );
		$this->assertSame( '{"created":true}', $json['body'] );
		$this->assertSame( array( 'Location' => '/items/1' ), $json['headers'] );
	}

	public function testServerErrorResponse(): void {
		$response = new HttpResponse(
			statusCode: 500,
			body: 'Internal Server Error',
		);

		$this->assertSame( 500, $response->statusCode );
	}

	public function testEmptyBodyResponse(): void {
		$response = new HttpResponse(
			statusCode: 204,
			body: '',
		);

		$this->assertSame( 204, $response->statusCode );
		$this->assertSame( '', $response->body );
	}
}
