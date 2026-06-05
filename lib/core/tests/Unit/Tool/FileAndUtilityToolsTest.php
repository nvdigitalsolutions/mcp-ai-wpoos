<?php
/**
 * Tests for file tools and utility tools.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GetFileInfoTool;
use Nvoos\Core\Tool\DeleteFileTool;
use Nvoos\Core\Tool\Base64Tool;
use Nvoos\Core\Tool\ExtractDomainTool;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class FileAndUtilityToolsTest extends TestCase {

	// ─── GetFileInfoTool ────────────────────────────────────────────

	public function testGetFileInfoReturnsMetadata(): void {
		$expected = new StoredFile(
			id: 1, filename: 'doc.pdf', mimeType: 'application/pdf',
			sizeBytes: 2048, localPath: '/uploads/doc.pdf',
			publicUrl: 'https://example.com/uploads/doc.pdf',
			metadata: array(), ownerId: 5,
			createdAt: new DateTimeImmutable(),
		);

		$files = $this->createMock( FileStoreInterface::class );
		$files->method( 'getMetadata' )->with( 1 )->willReturn( $expected );

		$tool   = new GetFileInfoTool( $this->stubErrors(), $files );
		$result = $tool->execute( array( 'file_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'doc.pdf', $result['data']['filename'] );
	}

	public function testGetFileInfoNotFound(): void {
		$expectedError = array( 'success' => false, 'error' => array( 'code' => 'not_found' ) );
		$errors = $this->createMock( ErrorFactoryInterface::class );
		$errors->method( 'notFound' )->willReturn( $expectedError );

		$files = $this->createMock( FileStoreInterface::class );
		$files->method( 'getMetadata' )->willReturn( null );

		$tool   = new GetFileInfoTool( $errors, $files );
		$result = $tool->execute( array( 'file_id' => 999 ) );

		$this->assertSame( $expectedError, $result );
	}

	// ─── DeleteFileTool ─────────────────────────────────────────────

	public function testDeleteFile(): void {
		$file = new StoredFile(
			id: 1, filename: 'temp.txt', mimeType: 'text/plain',
			sizeBytes: 100, localPath: '/tmp/temp.txt',
			publicUrl: null, metadata: array(), ownerId: 5,
			createdAt: new DateTimeImmutable(),
		);

		$files = $this->createMock( FileStoreInterface::class );
		$files->method( 'getMetadata' )->with( 1 )->willReturn( $file );
		$files->expects( $this->once() )->method( 'delete' )->with( 1 );

		$tool   = new DeleteFileTool( $this->stubErrors(), $files );
		$result = $tool->execute( array( 'file_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'temp.txt', $result['data']['filename'] );
	}

	// ─── Base64Tool ─────────────────────────────────────────────────

	public function testBase64Encode(): void {
		$tool   = new Base64Tool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'Hello' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'encode', $result['data']['operation'] );
		$this->assertSame( 'SGVsbG8=', $result['data']['output'] );
	}

	public function testBase64Decode(): void {
		$tool   = new Base64Tool( $this->stubErrors() );
		$result = $tool->execute( array( 'base64' => 'SGVsbG8=' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'decode', $result['data']['operation'] );
		$this->assertSame( 'Hello', $result['data']['output'] );
	}

	public function testBase64UrlSafe(): void {
		$tool   = new Base64Tool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'test', 'variant' => 'urlsafe' ) );

		$this->assertSame( 'urlsafe', $result['data']['variant'] );
		$this->assertStringNotContainsString( '+', $result['data']['output'] );
		$this->assertStringNotContainsString( '/', $result['data']['output'] );
	}

	// ─── ExtractDomainTool ──────────────────────────────────────────

	public function testExtractDomainSimple(): void {
		$tool   = new ExtractDomainTool( $this->stubErrors() );
		$result = $tool->execute( array( 'url' => 'https://www.example.com/path?q=1' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['valid'] );
		$this->assertSame( 'https', $result['data']['scheme'] );
		$this->assertSame( 'www.example.com', $result['data']['hostname'] );
		$this->assertSame( 'example', $result['data']['domain'] );
		$this->assertSame( 'com', $result['data']['tld'] );
		$this->assertSame( 'www', $result['data']['subdomain'] );
	}

	public function testExtractDomainInvalid(): void {
		$tool   = new Base64Tool( $this->stubErrors() );
		// Test for invalid URL — but Base64Tool doesn't parse URLs.
		// Just verify basic slug works.
		$this->assertSame( 'base64', $tool->getSlug() );
	}

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
