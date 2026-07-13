<?php
/**
 * Tests for ContentCollection value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class ContentCollectionTest extends TestCase {

	private ContentCollection $collection;
	private ContentItem $item;

	protected function setUp(): void {
		$this->item = new ContentItem(
			id: 1,
			title: 'Test Post',
			content: 'Hello.',
			status: 'publish',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		$this->collection = new ContentCollection(
			items: array( $this->item ),
			total: 50,
			page: 1,
			perPage: 10,
			totalPages: 5,
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertCount( 1, $this->collection->items );
		$this->assertSame( $this->item, $this->collection->items[0] );
		$this->assertSame( 50, $this->collection->total );
		$this->assertSame( 1, $this->collection->page );
		$this->assertSame( 10, $this->collection->perPage );
		$this->assertSame( 5, $this->collection->totalPages );
	}

	public function testHasItemsReturnsTrueWhenNotEmpty(): void {
		$this->assertTrue( $this->collection->hasItems() );
	}

	public function testHasItemsReturnsFalseWhenEmpty(): void {
		$empty = new ContentCollection(
			items: array(),
			total: 0,
			page: 1,
			perPage: 10,
			totalPages: 0,
		);

		$this->assertFalse( $empty->hasItems() );
	}

	public function testHasMorePagesReturnsTrueWhenNotLastPage(): void {
		$this->assertTrue( $this->collection->hasMorePages() );
	}

	public function testHasMorePagesReturnsFalseOnLastPage(): void {
		$lastPage = new ContentCollection(
			items: array( $this->item ),
			total: 50,
			page: 5,
			perPage: 10,
			totalPages: 5,
		);

		$this->assertFalse( $lastPage->hasMorePages() );
	}

	public function testHasMorePagesReturnsFalseWhenBeyondLastPage(): void {
		$beyond = new ContentCollection(
			items: array(),
			total: 50,
			page: 6,
			perPage: 10,
			totalPages: 5,
		);

		$this->assertFalse( $beyond->hasMorePages() );
	}

	public function testJsonSerialize(): void {
		$json = $this->collection->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 50, $json['total'] );
		$this->assertSame( 1, $json['page'] );
		$this->assertSame( 10, $json['per_page'] );
		$this->assertSame( 5, $json['total_pages'] );
		$this->assertCount( 1, $json['items'] );
	}

	public function testEmptyCollection(): void {
		$empty = new ContentCollection(
			items: array(),
			total: 0,
			page: 1,
			perPage: 20,
			totalPages: 0,
		);

		$this->assertFalse( $empty->hasItems() );
		$this->assertFalse( $empty->hasMorePages() );
		$this->assertSame( 0, $empty->total );
		$this->assertSame( 0, $empty->totalPages );
	}

	public function testMultipleItems(): void {
		$item2 = new ContentItem(
			id: 2,
			title: 'Second Post',
			content: 'Content.',
			status: 'draft',
			type: 'page',
			authorId: 2,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		$multi = new ContentCollection(
			items: array( $this->item, $item2 ),
			total: 2,
			page: 1,
			perPage: 20,
			totalPages: 1,
		);

		$this->assertTrue( $multi->hasItems() );
		$this->assertCount( 2, $multi->items );
		$this->assertSame( 2, $multi->total );
	}
}
