<?php
/**
 * Tests for GenerateUuidTool, HashStringTool, ValidateJsonTool,
 * EnqueueJobTool, and GetJobStatusTool.
 *
 * @package Oos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tests\Unit\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\QueueClientInterface;
use Oos\Core\Domain\Entity\JobStatus;
use Oos\Core\Tool\GenerateUuidTool;
use Oos\Core\Tool\HashStringTool;
use Oos\Core\Tool\ValidateJsonTool;
use Oos\Core\Tool\EnqueueJobTool;
use Oos\Core\Tool\GetJobStatusTool;
use PHPUnit\Framework\TestCase;

final class MoreToolsTest extends TestCase {

	// ─── GenerateUuidTool ───────────────────────────────────────────

	public function testGenerateUuidV4(): void {
		$tool   = new GenerateUuidTool( $this->stubErrors() );
		$result = $tool->execute( array() );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['data']['uuids'] );
		$this->assertSame( 4, $result['data']['version'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$result['data']['uuids'][0],
		);
	}

	public function testGenerateMultipleUuids(): void {
		$tool   = new GenerateUuidTool( $this->stubErrors() );
		$result = $tool->execute( array( 'count' => 3 ) );

		$this->assertCount( 3, $result['data']['uuids'] );
		// All should be unique.
		$this->assertCount( 3, array_unique( $result['data']['uuids'] ) );
	}

	// ─── HashStringTool ─────────────────────────────────────────────

	public function testHashStringSha256(): void {
		$tool   = new HashStringTool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'hello' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'sha256', $result['data']['algorithm'] );
		$this->assertSame( 64, $result['data']['length'] );
		$this->assertFalse( $result['data']['salted'] );
	}

	public function testHashStringMd5(): void {
		$tool   = new HashStringTool( $this->stubErrors() );
		$result = $tool->execute( array( 'text' => 'hello', 'algorithm' => 'md5' ) );

		$this->assertSame( 'md5', $result['data']['algorithm'] );
		$this->assertSame( 32, $result['data']['length'] );
	}

	public function testHashStringWithSalt(): void {
		$tool   = new HashStringTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'text' => 'hello',
			'salt' => 'mysalt',
		) );

		$this->assertTrue( $result['data']['salted'] );
		// Salted hash should differ from unsalted.
		$unsalted = $tool->execute( array( 'text' => 'hello' ) );
		$this->assertNotSame( $unsalted['data']['hash'], $result['data']['hash'] );
	}

	// ─── ValidateJsonTool ───────────────────────────────────────────

	public function testValidateJsonValid(): void {
		$tool   = new ValidateJsonTool( $this->stubErrors() );
		$result = $tool->execute( array( 'json' => '{"key":"value"}' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['valid'] );
		$this->assertSame( 'object', $result['data']['type'] );
		$this->assertSame( 1, $result['data']['keys'] );
	}

	public function testValidateJsonInvalid(): void {
		$tool   = new ValidateJsonTool( $this->stubErrors() );
		$result = $tool->execute( array( 'json' => '{invalid}' ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['valid'] );
		$this->assertNotEmpty( $result['data']['error'] );
	}

	public function testValidateJsonPretty(): void {
		$tool   = new ValidateJsonTool( $this->stubErrors() );
		$result = $tool->execute( array(
			'json'   => '{"a":1,"b":2}',
			'pretty' => true,
		) );

		$this->assertTrue( $result['data']['valid'] );
		$this->assertStringContainsString( "\n", $result['data']['data'] );
	}

	// ─── EnqueueJobTool ─────────────────────────────────────────────

	public function testEnqueueJob(): void {
		$queue = $this->createMock( QueueClientInterface::class );
		$queue->method( 'enqueue' )
			->with( 'process_batch', array( 'ids' => array( 1, 2 ) ), array( 'group' => 'wp_mcp_ai', 'unique' => false ) )
			->willReturn( 'job_123' );

		$tool   = new EnqueueJobTool( $this->stubErrors(), $queue );
		$result = $tool->execute( array(
			'handler' => 'process_batch',
			'payload' => array( 'ids' => array( 1, 2 ) ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'job_123', $result['data']['job_id'] );
		$this->assertSame( 'process_batch', $result['data']['handler'] );
	}

	// ─── GetJobStatusTool ───────────────────────────────────────────

	public function testGetJobStatus(): void {
		$jobStatus = new JobStatus(
			jobId: 'job_123',
			status: 'completed',
			result: array( 'ok' => true ),
			attempts: 1,
		);

		$queue = $this->createMock( QueueClientInterface::class );
		$queue->method( 'getStatus' )->with( 'job_123' )->willReturn( $jobStatus );

		$tool   = new GetJobStatusTool( $this->stubErrors(), $queue );
		$result = $tool->execute( array( 'job_id' => 'job_123' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['data']['status'] );
		$this->assertSame( 'job_123', $result['data']['job_id'] );
	}

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
