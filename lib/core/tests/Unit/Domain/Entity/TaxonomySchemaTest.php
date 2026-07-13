<?php
/**
 * Tests for TaxonomySchema value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\TaxonomySchema;
use PHPUnit\Framework\TestCase;

final class TaxonomySchemaTest extends TestCase {

	public function testHierarchicalTaxonomy(): void {
		$tax = new TaxonomySchema(
			slug: 'category',
			label: 'Categories',
			isHierarchical: true,
			isPublic: true,
		);

		$this->assertSame( 'category', $tax->slug );
		$this->assertSame( 'Categories', $tax->label );
		$this->assertTrue( $tax->isHierarchical );
		$this->assertTrue( $tax->isPublic );
	}

	public function testNonHierarchicalTaxonomy(): void {
		$tax = new TaxonomySchema(
			slug: 'post_tag',
			label: 'Tags',
			isHierarchical: false,
		);

		$this->assertSame( 'post_tag', $tax->slug );
		$this->assertFalse( $tax->isHierarchical );
	}

	public function testPrivateTaxonomy(): void {
		$tax = new TaxonomySchema(
			slug: 'internal_tax',
			label: 'Internal',
			isPublic: false,
		);

		$this->assertFalse( $tax->isPublic );
	}

	public function testDefaultValues(): void {
		$tax = new TaxonomySchema(
			slug: 'default_tax',
			label: 'Default',
		);

		$this->assertFalse( $tax->isHierarchical );
		$this->assertTrue( $tax->isPublic );
		$this->assertSame( '', $tax->description );
	}

	public function testJsonSerialize(): void {
		$tax = new TaxonomySchema(
			slug: 'category',
			label: 'Categories',
			isHierarchical: true,
			isPublic: true,
			description: 'Post categories.',
		);

		$json = $tax->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 'category', $json['slug'] );
		$this->assertSame( 'Categories', $json['label'] );
		$this->assertTrue( $json['is_hierarchical'] );
		$this->assertTrue( $json['is_public'] );
		$this->assertSame( 'Post categories.', $json['description'] );
	}
}
