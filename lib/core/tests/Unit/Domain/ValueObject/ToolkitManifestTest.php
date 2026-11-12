<?php
/**
 * Tests for ToolkitManifest — the declarative Pro toolkit contract.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;
use PHPUnit\Framework\TestCase;

final class ToolkitManifestTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function validManifest(): array {
		return array(
			'slug'        => 'commerce',
			'name'        => 'Commerce',
			'version'     => '1.0.0',
			'description' => 'Catalog and order operations.',
			'icon'        => '🛍️',
			'feature'     => 'commerce',
			'screens'     => array( 'settings', 'entities', 'dashboard' ),
			'settings'    => array(
				array(
					'key'     => 'currency',
					'label'   => 'Currency',
					'type'    => 'text',
					'default' => 'USD',
				),
			),
			'entities'    => array(
				array(
					'key'      => 'product',
					'label'    => 'Products',
					'singular' => 'Product',
					'id_field' => 'sku',
					'columns'  => array(
						array( 'key' => 'sku', 'label' => 'SKU', 'type' => 'string', 'required' => true ),
						array( 'key' => 'price', 'label' => 'Price', 'type' => 'currency' ),
						array( 'key' => 'stock', 'label' => 'Stock', 'type' => 'number' ),
						array(
							'key'   => 'status',
							'label' => 'Status',
							'type'  => 'enum',
							'enum'  => array( 'draft', 'active', 'archived' ),
						),
					),
				),
			),
			'dashboard'   => array(
				'cards' => array(
					array(
						'key'       => 'total_products',
						'label'     => 'Products',
						'source'    => 'entity:product',
						'aggregate' => 'count',
					),
					array(
						'key'       => 'stock_value',
						'label'     => 'Stock',
						'source'    => 'entity:product',
						'aggregate' => 'sum',
						'column'    => 'stock',
						'filter'    => array(
							'column' => 'status',
							'op'     => 'eq',
							'value'  => 'active',
						),
					),
				),
			),
		);
	}

	public function testValidManifestPassesValidation(): void {
		$this->assertSame( array(), ToolkitManifest::validate( $this->validManifest() ) );

		$manifest = ToolkitManifest::fromArray( $this->validManifest() );

		$this->assertSame( 'commerce', $manifest->slug() );
		$this->assertSame( 'Commerce', $manifest->name() );
		$this->assertSame( 'commerce', $manifest->feature() );
		$this->assertSame( array( 'settings', 'entities', 'dashboard' ), $manifest->screens() );
		$this->assertCount( 1, $manifest->entities() );
		$this->assertCount( 2, $manifest->dashboardCards() );
		$this->assertSame( 'product', $manifest->entity( 'product' )['key'] );
		$this->assertNull( $manifest->entity( 'order' ) );
	}

	public function testNormalizeFillsDefaults(): void {
		$raw = $this->validManifest();
		unset( $raw['entities'][0]['singular'], $raw['icon'], $raw['dashboard'], $raw['settings'] );

		$manifest = ToolkitManifest::fromArray( $raw );
		$entity   = $manifest->entity( 'product' );

		$this->assertSame( 'Products', $entity['singular'] );
		$this->assertSame( '', $entity['icon'] );
		$this->assertSame( array(), $manifest->dashboardCards() );
		$this->assertSame( array(), $manifest->settings() );
		$this->assertSame( array( 'cards' => array() ), $manifest->toArray()['dashboard'] );
	}

	public function testUnknownTopLevelKeyIsRejected(): void {
		$raw             = $this->validManifest();
		$raw['handlers'] = array();

		$this->assertStringContainsString( 'unknown top-level key', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testInvalidSlugAndVersionAreRejected(): void {
		$raw            = $this->validManifest();
		$raw['slug']    = 'Commerce Toolkit';
		$raw['version'] = '1.0';

		$errors = implode( ' ', ToolkitManifest::validate( $raw ) );

		$this->assertStringContainsString( 'slug must match', $errors );
		$this->assertStringContainsString( 'version must be semantic', $errors );
	}

	public function testEveryDeclaredScreenIsAccepted(): void {
		foreach ( ToolkitManifest::SCREENS as $screen ) {
			$raw             = $this->validManifest();
			$raw['screens']  = array( $screen );

			$this->assertSame(
				array(),
				ToolkitManifest::validate( $raw ),
				'Declared screen "' . $screen . '" must validate.'
			);
		}
	}

	public function testUnknownScreenIsRejected(): void {
		$raw            = $this->validManifest();
		$raw['screens'] = array( 'settings', 'reports' );

		$this->assertStringContainsString( 'unknown screen', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testEntitiesMustBeNonEmpty(): void {
		$raw             = $this->validManifest();
		$raw['entities'] = array();

		$this->assertStringContainsString( 'entities must be a non-empty list', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testDuplicateEntityKeysAreRejected(): void {
		$raw               = $this->validManifest();
		$raw['entities'][] = $raw['entities'][0];

		$this->assertStringContainsString( 'is duplicated', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testIdFieldMustReferenceAColumn(): void {
		$raw                        = $this->validManifest();
		$raw['entities'][0]['id_field'] = 'uuid';

		$this->assertStringContainsString( 'must name one of the columns', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testUnknownColumnTypeAndEnumWithoutValuesAreRejected(): void {
		$raw                                            = $this->validManifest();
		$raw['entities'][0]['columns'][1]['type']       = 'money';
		$raw['entities'][0]['columns'][3]['enum']       = array();

		$errors = implode( ' ', ToolkitManifest::validate( $raw ) );

		$this->assertStringContainsString( 'is not one of string/number', $errors );
		$this->assertStringContainsString( 'enum must be a non-empty list', $errors );
	}

	public function testSelectSettingRequiresOptions(): void {
		$raw                       = $this->validManifest();
		$raw['settings'][0]['type'] = 'select';

		$this->assertStringContainsString( 'options must be a non-empty list', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testDashboardCardMustReferenceAnEntity(): void {
		$raw                                 = $this->validManifest();
		$raw['dashboard']['cards'][0]['source'] = 'entity:order';

		$this->assertStringContainsString( 'references unknown entity', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testSumRequiresAColumnOfThatEntity(): void {
		$raw                                = $this->validManifest();
		$raw['dashboard']['cards'][1]['column'] = 'revenue';

		$this->assertStringContainsString( 'is not a column of that entity', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testInvalidFilterOperatorIsRejected(): void {
		$raw                                         = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter']['op'] = 'between';

		$this->assertStringContainsString( 'filter.op must be one of', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testFilterListIsAccepted(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array(
			array( 'column' => 'status', 'op' => 'eq', 'value' => 'active' ),
			array( 'column' => 'stock', 'op' => 'lte', 'value' => 5 ),
		);

		$this->assertSame( array(), ToolkitManifest::validate( $raw ) );
	}

	public function testFilterCanBindToADeclaredSetting(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array( 'column' => 'stock', 'op' => 'lte', 'setting' => 'currency' );

		$this->assertSame( array(), ToolkitManifest::validate( $raw ) );
	}

	public function testFilterRejectsAnUnknownSetting(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array( 'column' => 'stock', 'op' => 'lte', 'setting' => 'missing' );

		$this->assertStringContainsString( 'is not a declared setting', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testFilterRejectsBothValueAndSetting(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array(
			'column'  => 'stock',
			'op'      => 'lte',
			'value'   => 5,
			'setting' => 'currency',
		);

		$this->assertStringContainsString( 'exactly one of value or setting', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testFilterRequiresAValueOrASetting(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array( 'column' => 'stock', 'op' => 'lte' );

		$this->assertStringContainsString( 'exactly one of value or setting', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testEmptyFilterListIsRejected(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array();

		$this->assertStringContainsString( 'at least one filter', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testFilterListEntryMustBeAnObject(): void {
		$raw                                    = $this->validManifest();
		$raw['dashboard']['cards'][1]['filter'] = array( 'nope' );

		$this->assertStringContainsString( 'must be an object', implode( ' ', ToolkitManifest::validate( $raw ) ) );
	}

	public function testFromArrayThrowsOnInvalidDocument(): void {
		$this->expectException( InvalidArgumentException::class );

		ToolkitManifest::fromArray( array( 'slug' => 'x' ) );
	}
}
