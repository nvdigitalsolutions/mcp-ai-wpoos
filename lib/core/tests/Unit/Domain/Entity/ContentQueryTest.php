<?php
/**
 * Tests for ContentQuery value object.
 *
 * @package Oos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tests\Unit\Domain\Entity;

use Oos\Core\Domain\Entity\ContentQuery;
use PHPUnit\Framework\TestCase;

final class ContentQueryTest extends TestCase {

	public function testDefaultValues(): void {
		$query = new ContentQuery();

		$this->assertSame( array(), $query->types );
		$this->assertSame( array( 'publish' ), $query->statuses );
		$this->assertNull( $query->search );
		$this->assertNull( $query->authorId );
		$this->assertSame( array(), $query->include );
		$this->assertSame( array(), $query->exclude );
		$this->assertSame( array(), $query->metaQuery );
		$this->assertSame( array(), $query->taxQuery );
		$this->assertSame( 'date', $query->orderBy );
		$this->assertSame( 'DESC', $query->order );
		$this->assertSame( 1, $query->page );
		$this->assertSame( 20, $query->perPage );
		$this->assertNull( $query->userId );
	}

	public function testCustomQuery(): void {
		$query = new ContentQuery(
			types: array( 'post', 'page' ),
			statuses: array( 'publish', 'draft' ),
			search: 'hello',
			authorId: 3,
			include: array( 1, 2, 3 ),
			exclude: array( 99 ),
			orderBy: 'title',
			order: 'ASC',
			page: 2,
			perPage: 10,
			userId: 5,
		);

		$this->assertSame( array( 'post', 'page' ), $query->types );
		$this->assertSame( array( 'publish', 'draft' ), $query->statuses );
		$this->assertSame( 'hello', $query->search );
		$this->assertSame( 3, $query->authorId );
		$this->assertSame( array( 1, 2, 3 ), $query->include );
		$this->assertSame( array( 99 ), $query->exclude );
		$this->assertSame( 'title', $query->orderBy );
		$this->assertSame( 'ASC', $query->order );
		$this->assertSame( 2, $query->page );
		$this->assertSame( 10, $query->perPage );
		$this->assertSame( 5, $query->userId );
	}

	public function testMetaAndTaxQueries(): void {
		$query = new ContentQuery(
			metaQuery: array(
				array(
					'key'   => '_custom_field',
					'value' => 'active',
				),
			),
			taxQuery: array(
				array(
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => array( 'news' ),
				),
			),
		);

		$this->assertCount( 1, $query->metaQuery );
		$this->assertSame( '_custom_field', $query->metaQuery[0]['key'] );
		$this->assertCount( 1, $query->taxQuery );
		$this->assertSame( 'category', $query->taxQuery[0]['taxonomy'] );
	}
}
