<?php
/**
 * Tests for GenerateSlugTool, FormatBytesTool, and StripHtmlTool.
 *
 * All three are zero-dependency pure-logic tools.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\GenerateSlugTool;
use Nvoos\Core\Tool\FormatBytesTool;
use Nvoos\Core\Tool\StripHtmlTool;
use PHPUnit\Framework\TestCase;

final class UtilityToolsTest extends TestCase {

	// ─── GenerateSlugTool ───────────────────────────────────────────

	public function testGenerateSlugBasic(): void {
		$tool   = new GenerateSlugTool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'Hello World!' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hello-world', $result['data']['slug'] );
		$this->assertSame( 11, $result['data']['length'] );
	}

	public function testGenerateSlugWithAccents(): void {
		$tool   = new GenerateSlugTool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'Café Münster' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'cafe-munster', $result['data']['slug'] );
	}

	public function testGenerateSlugCustomSeparator(): void {
		$tool   = new GenerateSlugTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'text'      => 'hello world',
			'separator' => '_',
		) );

		$this->assertSame( 'hello_world', $result['data']['slug'] );
	}

	public function testGenerateSlugMaxLength(): void {
		$tool   = new GenerateSlugTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'text'       => 'This is a very long title that should be truncated',
			'max_length' => 10,
		) );

		$this->assertLessThanOrEqual( 10, strlen( $result['data']['slug'] ) );
	}

	public function testGenerateSlugEmptyYieldsUntitled(): void {
		$tool   = new GenerateSlugTool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => '!@#$%' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'untitled', $result['data']['slug'] );
	}

	// ─── FormatBytesTool ────────────────────────────────────────────

	public function testFormatBytesZero(): void {
		$tool   = new FormatBytesTool( $this->stubErrors() );
		$result = $tool->execute( array( 'bytes' => 0 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '0 KB', $result['data']['formatted'] );
	}

	public function testFormatBytesKB(): void {
		$tool   = new FormatBytesTool( $this->stubErrors() );
		$result = $tool->execute( array( 'bytes' => 1536 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '1.5 KB', $result['data']['formatted'] );
	}

	public function testFormatBytesMB(): void {
		$tool   = new FormatBytesTool( $this->stubErrors() );
		$result = $tool->execute( array( 'bytes' => 5242880 ) ); // 5 MB

		$this->assertTrue( $result['success'] );
		$this->assertSame( '5.2 MB', $result['data']['formatted'] );
	}

	public function testFormatBytesBinary(): void {
		$tool   = new FormatBytesTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'bytes'  => 1536,
			'binary' => true,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'KiB', $result['data']['formatted'] );
	}

	public function testFormatBytesCustomDecimals(): void {
		$tool   = new FormatBytesTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'bytes'    => 1234567,
			'decimals' => 3,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '.', $result['data']['formatted'] );
	}

	// ─── StripHtmlTool ──────────────────────────────────────────────

	public function testStripHtmlSimple(): void {
		$tool   = new StripHtmlTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'html' => '<p>Hello <b>World</b>!</p>',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Hello World!', $result['data']['text'] );
	}

	public function testStripHtmlWithAllowedTags(): void {
		$tool   = new StripHtmlTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'html'         => '<p>Hello <b>World</b>!</p>',
			'allowed_tags' => array( 'b' ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '<b>World</b>', $result['data']['text'] );
	}

	public function testStripHtmlDecodesEntities(): void {
		$tool   = new StripHtmlTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'html' => 'Hello &amp; welcome to &#8220;AI&#8221;!',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '&', $result['data']['text'] );
	}

	public function testStripHtmlReportsReduction(): void {
		$tool   = new StripHtmlTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'html' => '<div class="x"><p>Hi</p></div>',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['data']['reduction_pct'] );
	}

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
