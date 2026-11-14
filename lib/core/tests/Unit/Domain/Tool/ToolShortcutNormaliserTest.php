<?php
/**
 * Unit tests for the shared tool-shortcut normaliser.
 *
 * Each case pins a rule the console relies on: the defaults that stop a blank
 * tile rendering, the id suffixes that keep React keys unique, and the search
 * that matches the tool slug as well as the visible text.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Tool;

use Nvoos\Core\Domain\Tool\ToolShortcutNormaliser;
use PHPUnit\Framework\TestCase;

final class ToolShortcutNormaliserTest extends TestCase {

	public function testAToolOnlyEntryGetsLabelPayloadCategoryAndIconDefaults(): void {
		$result = ToolShortcutNormaliser::normalise( array( array( 'tool' => 'get_weather' ) ) );

		$this->assertCount( 1, $result['shortcuts'] );
		$this->assertSame(
			array(
				'id'          => 'get_weather',
				'label'       => 'get_weather',
				'payload'     => 'get_weather',
				'tool'        => 'get_weather',
				'description' => '',
				'category'    => 'General',
				'icon'        => "\u{26A1}",
			),
			$result['shortcuts'][0]
		);
		$this->assertSame( array( 'General' ), $result['categories'] );
	}

	public function testAnEntryWithNeitherLabelNorPayloadIsDropped(): void {
		$result = ToolShortcutNormaliser::normalise(
			array(
				array( 'label' => '', 'payload' => '' ),
				array( 'tool' => 'kept' ),
			)
		);

		$this->assertCount( 1, $result['shortcuts'] );
		$this->assertSame( 'kept', $result['shortcuts'][0]['tool'] );
	}

	public function testANonArrayEntryIsSkipped(): void {
		$result = ToolShortcutNormaliser::normalise( array( 'nope', 42, array( 'tool' => 'kept' ) ) );

		$this->assertCount( 1, $result['shortcuts'] );
	}

	public function testTheIdFallsBackToTheLabelWhenThereIsNoTool(): void {
		$result = ToolShortcutNormaliser::normalise(
			array( array( 'label' => 'Say Hi', 'payload' => 'hi' ) )
		);

		$this->assertSame( 'sayhi', $result['shortcuts'][0]['id'] );
		$this->assertSame( '', $result['shortcuts'][0]['tool'] );
	}

	public function testDuplicateIdsGetNumericSuffixes(): void {
		$result = ToolShortcutNormaliser::normalise(
			array(
				array( 'tool' => 'same' ),
				array( 'tool' => 'same' ),
				array( 'tool' => 'same' ),
			)
		);

		$this->assertSame( 'same', $result['shortcuts'][0]['id'] );
		$this->assertSame( 'same-2', $result['shortcuts'][1]['id'] );
		$this->assertSame( 'same-3', $result['shortcuts'][2]['id'] );
	}

	public function testSearchMatchesTheToolSlugAndIsCaseInsensitive(): void {
		$raw = array(
			array( 'label' => 'Forecast', 'payload' => 'f', 'tool' => 'get_weather' ),
			array( 'label' => 'Timezone', 'payload' => 't', 'tool' => 'get_timezone' ),
		);

		$hit = ToolShortcutNormaliser::normalise( $raw, 'WEATH' );
		$this->assertCount( 1, $hit['shortcuts'] );
		$this->assertSame( 'get_weather', $hit['shortcuts'][0]['tool'] );

		$this->assertSame( array(), ToolShortcutNormaliser::normalise( $raw, 'zzz' )['shortcuts'] );
	}

	public function testCategoriesAreUniqueAndSorted(): void {
		$result = ToolShortcutNormaliser::normalise(
			array(
				array( 'tool' => 'a', 'category' => 'Zeta' ),
				array( 'tool' => 'b', 'category' => 'Alpha' ),
				array( 'tool' => 'c', 'category' => 'Zeta' ),
			)
		);

		$this->assertSame( array( 'Alpha', 'Zeta' ), $result['categories'] );
	}

	public function testDeclaredLabelPayloadCategoryAndIconArePreserved(): void {
		$result = ToolShortcutNormaliser::normalise(
			array(
				array(
					'label'       => 'Weather',
					'payload'     => 'weather in ',
					'tool'        => 'get_weather',
					'description' => 'Current conditions',
					'category'    => 'Utilities',
					'icon'        => 'W',
				),
			)
		);

		$shortcut = $result['shortcuts'][0];

		$this->assertSame( 'Weather', $shortcut['label'] );
		// Trailing whitespace is trimmed, matching the WordPress controller —
		// a payload reads as a phrase, not as prompt padding.
		$this->assertSame( 'weather in', $shortcut['payload'] );
		$this->assertSame( 'Utilities', $shortcut['category'] );
		$this->assertSame( 'W', $shortcut['icon'] );
	}

	public function testNonScalarFieldsAreTreatedAsAbsent(): void {
		$result = ToolShortcutNormaliser::normalise(
			array(
				array(
					'tool'  => 'get_weather',
					'label' => array( 'not', 'a', 'string' ),
				),
			)
		);

		// The label falls back to the tool, not to a stringified array.
		$this->assertSame( 'get_weather', $result['shortcuts'][0]['label'] );
	}
}
