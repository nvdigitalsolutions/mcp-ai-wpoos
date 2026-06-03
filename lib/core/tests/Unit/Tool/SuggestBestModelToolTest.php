<?php
/**
 * Tests for SuggestBestModelTool — pure-logic tool with no external dependencies.
 *
 * @package Oos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tests\Unit\Tool;

use Oos\Core\Tool\SuggestBestModelTool;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use PHPUnit\Framework\TestCase;

final class SuggestBestModelToolTest extends TestCase {

	private SuggestBestModelTool $tool;

	protected function setUp(): void {
		$errors = $this->createMock( ErrorFactoryInterface::class );
		$this->tool = new SuggestBestModelTool( $errors );
	}

	public function testGetSlug(): void {
		$this->assertSame( 'suggest_best_model', $this->tool->getSlug() );
	}

	public function testGetRequiredCapability(): void {
		$this->assertSame( 'read', $this->tool->getRequiredCapability() );
	}

	public function testSuggestsCodingModel(): void {
		$result = $this->tool->execute( array( 'task' => 'coding a PHP plugin' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'claude-sonnet-4-6', $result['data']['model'] );
		$this->assertSame( 'quality', $result['data']['priority'] );
	}

	public function testSuggestsWritingModel(): void {
		$result = $this->tool->execute( array( 'task' => 'writing a blog post' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'claude-opus-4-6', $result['data']['model'] );
	}

	public function testSuggestsVisionModel(): void {
		$result = $this->tool->execute( array( 'task' => 'vision task' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'gpt-4o', $result['data']['model'] );
	}

	public function testSuggestsMathModel(): void {
		$result = $this->tool->execute( array( 'task' => 'math problem' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'o4-mini', $result['data']['model'] );
	}

	public function testPrioritizesSpeed(): void {
		$result = $this->tool->execute( array(
			'task'     => 'coding',
			'priority' => 'speed',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'gpt-4o-mini', $result['data']['model'] );
		$this->assertSame( 'speed', $result['data']['priority'] );
	}

	public function testPrioritizesCost(): void {
		$result = $this->tool->execute( array(
			'task'     => 'writing',
			'priority' => 'cost',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'gemini-2.0-flash', $result['data']['model'] );
	}

	public function testFallbackForUnknownTask(): void {
		$result = $this->tool->execute( array( 'task' => 'something completely new' ) );

		$this->assertTrue( $result['success'] );
		// Falls back to 'analysis' recommendations.
		$this->assertSame( 'gpt-5', $result['data']['model'] );
		$this->assertSame( 'quality', $result['data']['priority'] );
	}

	public function testProvidesAlternatives(): void {
		$result = $this->tool->execute( array(
			'task'     => 'translation',
			'priority' => 'quality',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'claude-sonnet-4-6', $result['data']['model'] );
		$this->assertNotEmpty( $result['data']['alternatives'] );
		$this->assertCount( 2, $result['data']['alternatives'] );
	}

	public function testParametersSchema(): void {
		$schema = $this->tool->getParametersSchema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'task', $schema['properties'] );
		$this->assertArrayHasKey( 'priority', $schema['properties'] );
		$this->assertContains( 'task', $schema['required'] );
	}
}
