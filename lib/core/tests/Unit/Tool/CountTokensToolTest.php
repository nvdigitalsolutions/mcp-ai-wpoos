<?php
/**
 * Tests for CountTokensTool — zero-dependency heuristic token counter.
 *
 * @package Oos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tests\Unit\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Tool\CountTokensTool;
use PHPUnit\Framework\TestCase;

final class CountTokensToolTest extends TestCase {

	private CountTokensTool $tool;

	protected function setUp(): void {
		$errors = $this->createMock( ErrorFactoryInterface::class );
		$this->tool = new CountTokensTool( $errors );
	}

	public function testGetSlug(): void {
		$this->assertSame( 'count_tokens', $this->tool->getSlug() );
	}

	public function testGetRequiredCapability(): void {
		$this->assertSame( 'read', $this->tool->getRequiredCapability() );
	}

	public function testCountPlainText(): void {
		$result = $this->tool->execute( array( 'text' => 'Hello world' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['data']['tokens'] ); // 11 chars / 4 = 3
		$this->assertSame( 'heuristic', $result['data']['method'] );
		$this->assertSame( 4, $result['data']['chars_per_token'] );
	}

	public function testCountMessages(): void {
		$result = $this->tool->execute( array(
			'messages' => array(
				array( 'role' => 'user', 'content' => 'Hello' ),
				array( 'role' => 'assistant', 'content' => 'Hi there!' ),
			),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 3, $result['data']['tokens'] );
		$this->assertStringContainsString( '2 message', $result['data']['note'] );
	}

	public function testEmptyInputReturnsError(): void {
		$expectedError = array(
			'success' => false,
			'error'   => array( 'code' => 'validation_failed' ),
		);

		$errors = $this->createMock( ErrorFactoryInterface::class );
		$errors->method( 'validationFailed' )->willReturn( $expectedError );

		$tool = new CountTokensTool( $errors );
		$result = $tool->execute( array() );

		$this->assertSame( $expectedError, $result );
	}

	public function testWithModelContextLimit(): void {
		$result = $this->tool->execute( array(
			'text'  => str_repeat( 'a', 4000 ), // ~1000 tokens
			'model' => 'gpt-4o-mini',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'gpt-4o-mini', $result['data']['model'] );
		$this->assertSame( 128000, $result['data']['context_limit'] );
		$this->assertGreaterThan( 0, $result['data']['remaining'] );
		$this->assertLessThan( 100, $result['data']['utilization_pct'] );
	}

	public function testUnknownModelNoLimitInfo(): void {
		$result = $this->tool->execute( array(
			'text'  => 'hello',
			'model' => 'unknown-model-xyz',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'context_limit', $result['data'] );
	}
}
