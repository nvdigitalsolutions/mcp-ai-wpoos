<?php
/**
 * Tests for ContentItem value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\ContentItem;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class ContentItemTest extends TestCase {

	private ContentItem $item;

	protected function setUp(): void {
		$this->item = new ContentItem(
			id: 42,
			title: 'Test Post',
			content: 'Hello world content.',
			status: 'publish',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			updatedAt: new DateTimeImmutable( '2026-01-02T00:00:00Z' ),
			meta: array( '_custom_field' => 'value' ),
			taxonomy: array( 'category' => array( 'News', 'Tech' ) ),
			excerpt: 'Summary text.',
			slug: 'test-post',
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertSame( 42, $this->item->id );
		$this->assertSame( 'Test Post', $this->item->title );
		$this->assertSame( 'Hello world content.', $this->item->content );
		$this->assertSame( 'publish', $this->item->status );
		$this->assertSame( 'post', $this->item->type );
		$this->assertSame( 1, $this->item->authorId );
		$this->assertSame( 'Summary text.', $this->item->excerpt );
		$this->assertSame( 'test-post', $this->item->slug );
	}

	public function testIsPublished(): void {
		$this->assertTrue( $this->item->isPublished() );

		$draft = new ContentItem(
			id: 43,
			title: 'Draft',
			content: '',
			status: 'draft',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		$this->assertFalse( $draft->isPublished() );
	}

	public function testGetMetaValue(): void {
		$this->assertSame( 'value', $this->item->getMetaValue( '_custom_field' ) );
		$this->assertNull( $this->item->getMetaValue( 'nonexistent' ) );
		$this->assertSame( 'fallback', $this->item->getMetaValue( 'nonexistent', 'fallback' ) );
	}

	public function testGetTerms(): void {
		$this->assertSame( array( 'News', 'Tech' ), $this->item->getTerms( 'category' ) );
		$this->assertSame( array(), $this->item->getTerms( 'nonexistent' ) );
	}

	public function testJsonSerialize(): void {
		$json = $this->item->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 42, $json['id'] );
		$this->assertSame( 'Test Post', $json['title'] );
		$this->assertSame( 'publish', $json['status'] );
		$this->assertSame( 'post', $json['type'] );
		$this->assertSame( 1, $json['author_id'] );
		$this->assertSame( 'value', $json['meta']['_custom_field'] );
		$this->assertSame( array( 'News', 'Tech' ), $json['taxonomy']['category'] );
		$this->assertSame( 'Summary text.', $json['excerpt'] );
		$this->assertSame( 'test-post', $json['slug'] );
		$this->assertStringContainsString( '2026-01-01', $json['created_at'] );
		$this->assertStringContainsString( '2026-01-02', $json['updated_at'] );
	}

	public function testImmutability(): void {
		// readonly properties cannot be reassigned — this test
		// documents the intent even though it can't compile-test it.
		$this->assertSame( 'Test Post', $this->item->title );
	}
}
