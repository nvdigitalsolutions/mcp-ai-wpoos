<?php
/**
 * Tests for StoredFile value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\StoredFile;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class StoredFileTest extends TestCase {

	private StoredFile $imageFile;
	private StoredFile $pdfFile;

	protected function setUp(): void {
		$this->imageFile = new StoredFile(
			id: 100,
			filename: 'photo.png',
			mimeType: 'image/png',
			sizeBytes: 204800,
			localPath: '/var/www/uploads/photo.png',
			ownerId: 5,
			createdAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			publicUrl: 'https://example.com/uploads/photo.png',
			metadata: array( 'width' => 1920, 'height' => 1080 ),
		);

		$this->pdfFile = new StoredFile(
			id: 200,
			filename: 'report.pdf',
			mimeType: 'application/pdf',
			sizeBytes: 1048576,
			localPath: '/var/www/uploads/report.pdf',
			ownerId: 3,
			createdAt: new DateTimeImmutable( '2026-02-01T00:00:00Z' ),
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertSame( 100, $this->imageFile->id );
		$this->assertSame( 'photo.png', $this->imageFile->filename );
		$this->assertSame( 'image/png', $this->imageFile->mimeType );
		$this->assertSame( 204800, $this->imageFile->sizeBytes );
		$this->assertSame( '/var/www/uploads/photo.png', $this->imageFile->localPath );
		$this->assertSame( 5, $this->imageFile->ownerId );
		$this->assertSame( 'https://example.com/uploads/photo.png', $this->imageFile->publicUrl );
		$this->assertSame(
			array( 'width' => 1920, 'height' => 1080 ),
			$this->imageFile->metadata,
		);
	}

	public function testIsImageReturnsTrueForImageMimeTypes(): void {
		$this->assertTrue( $this->imageFile->isImage() );

		$jpeg = new StoredFile(
			id: 101,
			filename: 'photo.jpg',
			mimeType: 'image/jpeg',
			sizeBytes: 100,
			localPath: '/tmp/photo.jpg',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);
		$this->assertTrue( $jpeg->isImage() );

		$webp = new StoredFile(
			id: 102,
			filename: 'photo.webp',
			mimeType: 'image/webp',
			sizeBytes: 100,
			localPath: '/tmp/photo.webp',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);
		$this->assertTrue( $webp->isImage() );

		$svg = new StoredFile(
			id: 103,
			filename: 'icon.svg',
			mimeType: 'image/svg+xml',
			sizeBytes: 100,
			localPath: '/tmp/icon.svg',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);
		$this->assertTrue( $svg->isImage() );
	}

	public function testIsImageReturnsFalseForNonImage(): void {
		$this->assertFalse( $this->pdfFile->isImage() );
	}

	public function testIsPdf(): void {
		$this->assertTrue( $this->pdfFile->isPdf() );
		$this->assertFalse( $this->imageFile->isPdf() );
	}

	public function testGetExtension(): void {
		$this->assertSame( 'png', $this->imageFile->getExtension() );
		$this->assertSame( 'pdf', $this->pdfFile->getExtension() );
	}

	public function testGetExtensionWithComplexFilename(): void {
		$file = new StoredFile(
			id: 300,
			filename: 'my.document.v2.PDF',
			mimeType: 'application/pdf',
			sizeBytes: 500,
			localPath: '/tmp/doc.pdf',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);

		$this->assertSame( 'pdf', $file->getExtension() );
	}

	public function testGetExtensionWithNoExtension(): void {
		$file = new StoredFile(
			id: 301,
			filename: 'README',
			mimeType: 'text/plain',
			sizeBytes: 100,
			localPath: '/tmp/README',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);

		$this->assertSame( '', $file->getExtension() );
	}

	public function testJsonSerialize(): void {
		$json = $this->imageFile->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 100, $json['id'] );
		$this->assertSame( 'photo.png', $json['filename'] );
		$this->assertSame( 'image/png', $json['mime_type'] );
		$this->assertSame( 204800, $json['size_bytes'] );
		$this->assertSame( 'https://example.com/uploads/photo.png', $json['public_url'] );
		$this->assertSame( 5, $json['owner_id'] );
		$this->assertSame(
			array( 'width' => 1920, 'height' => 1080 ),
			$json['metadata'],
		);
		$this->assertStringContainsString( '2026-01-01', $json['created_at'] );
	}

	public function testDefaultValues(): void {
		$file = new StoredFile(
			id: 400,
			filename: 'minimal.txt',
			mimeType: 'text/plain',
			sizeBytes: 0,
			localPath: '/tmp/minimal.txt',
			ownerId: 1,
			createdAt: new DateTimeImmutable(),
		);

		$this->assertNull( $file->publicUrl );
		$this->assertSame( array(), $file->metadata );
	}
}
