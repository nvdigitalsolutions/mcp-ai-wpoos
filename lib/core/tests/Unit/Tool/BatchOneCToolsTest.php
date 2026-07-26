<?php
/**
 * Tests for Batch 1c tools — Erlang-C queuing theory calculators.
 *
 * Covers: CalculateErlangCTool, ErlangCConcurrencyAdvisorTool,
 * ErlangCQueueHealthTool, ErlangCStaffingAdvisorTool.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\CalculateErlangCTool;
use Nvoos\Core\Tool\ErlangCConcurrencyAdvisorTool;
use Nvoos\Core\Tool\ErlangCQueueHealthTool;
use Nvoos\Core\Tool\ErlangCStaffingAdvisorTool;
use PHPUnit\Framework\TestCase;

final class BatchOneCToolsTest extends TestCase {

	private ErlangCInterface $erlang;
	private ErrorFactoryInterface $errorFactory;
	private SettingsStoreInterface $settings;
	private HttpClientInterface $http;

	protected function setUp(): void {
		$this->erlang       = $this->createMock( ErlangCInterface::class );
		$this->errorFactory  = $this->createMock( ErrorFactoryInterface::class );
		$this->settings      = $this->createMock( SettingsStoreInterface::class );
		$this->http          = $this->createMock( HttpClientInterface::class );
	}

	private function errorResponse( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array( 'code' => $code, 'message' => $message ),
		);
	}

	// ═══════════════════════════════════════════════════════════════════
	// CalculateErlangCTool
	// ═══════════════════════════════════════════════════════════════════

	public function testCalculateErlangCSlug(): void {
		$tool = new CalculateErlangCTool( $this->errorFactory, $this->erlang );
		$this->assertSame( 'calculate_erlang_c', $tool->getSlug() );
	}

	public function testCalculateErlangCWithNumAgents(): void {
		$this->erlang->method( 'toErlangs' )->willReturn( 2.5 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.15 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 3.2 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.90 );
		$this->erlang->method( 'utilisation' )->willReturn( 0.625 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 4 );

		$tool   = new CalculateErlangCTool( $this->errorFactory, $this->erlang );
		$result = $tool->execute(
			array( 'arrival_rate' => 50, 'avg_handle_time' => 180, 'num_agents' => 5 ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['is_stable'] );
		$this->assertSame( 5, $result['data']['input']['num_agents'] );
	}

	public function testCalculateErlangCWithoutNumAgents(): void {
		$this->erlang->method( 'toErlangs' )->willReturn( 3.0 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 6 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.1 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 2.0 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.85 );
		$this->erlang->method( 'utilisation' )->willReturn( 0.5 );

		$tool   = new CalculateErlangCTool( $this->errorFactory, $this->erlang );
		$result = $tool->execute(
			array( 'arrival_rate' => 60, 'avg_handle_time' => 180 ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 6, $result['data']['agents_needed'] );
	}

	public function testCalculateErlangCMissingArrivalRate(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'arrival_rate must be greater than 0.' ) );

		$tool   = new CalculateErlangCTool( $this->errorFactory, $this->erlang );
		$result = $tool->execute(
			array( 'avg_handle_time' => 180 ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ErlangCConcurrencyAdvisorTool
	// ═══════════════════════════════════════════════════════════════════

	public function testConcurrencyAdvisorSlug(): void {
		$tool = new ErlangCConcurrencyAdvisorTool( $this->errorFactory, $this->erlang, $this->settings );
		$this->assertSame( 'erlang_c_concurrency_advisor', $tool->getSlug() );
	}

	public function testConcurrencyAdvisorWithProvidedRate(): void {
		$this->erlang->method( 'toErlangs' )->willReturn( 1.0 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 3 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.1 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 1.5 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.85 );
		$this->erlang->method( 'utilisation' )->willReturn( 0.4 );

		$tool   = new ErlangCConcurrencyAdvisorTool( $this->errorFactory, $this->erlang, $this->settings );
		$result = $tool->execute(
			array( 'arrival_rate_per_hour' => 10, 'avg_session_duration' => 60 ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'provided', $result['data']['observation']['data_source'] );
	}

	public function testConcurrencyAdvisorNotLoggedIn(): void {
		$this->errorFactory->method( 'forbidden' )
			->willReturn( $this->errorResponse( 'forbidden', 'You must be logged in.' ) );

		$tool   = new ErlangCConcurrencyAdvisorTool( $this->errorFactory, $this->erlang, $this->settings );
		$result = $tool->execute( array(), array() );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ErlangCQueueHealthTool
	// ═══════════════════════════════════════════════════════════════════

	public function testQueueHealthSlug(): void {
		$tool = new ErlangCQueueHealthTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$this->assertSame( 'erlang_c_queue_health', $tool->getSlug() );
	}

	public function testQueueHealthHealthy(): void {
		$this->erlang->method( 'toErlangs' )->willReturn( 2.0 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 5 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.05 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 1.0 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.95 );
		$this->erlang->method( 'utilisation' )->willReturn( 0.6 );

		$tool   = new ErlangCQueueHealthTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$result = $tool->execute(
			array(
				'arrival_rate_per_hour' => 40,
				'avg_handle_time'       => 180,
				'current_agents'        => 8,
			),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'healthy', $result['data']['status'] );
		$this->assertFalse( $result['data']['sla_at_risk'] );
	}

	public function testQueueHealthOverloaded(): void {
		// Traffic = 4.0, agents = 3 → unstable.
		$this->erlang->method( 'toErlangs' )->willReturn( 4.0 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 6 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.9 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 100.0 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.3 );
		$this->erlang->method( 'utilisation' )->willReturn( 1.0 );

		$tool   = new ErlangCQueueHealthTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$result = $tool->execute(
			array(
				'arrival_rate_per_hour' => 80,
				'avg_handle_time'       => 180,
				'current_agents'        => 3,
			),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'overloaded', $result['data']['status'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ErlangCStaffingAdvisorTool
	// ═══════════════════════════════════════════════════════════════════

	public function testStaffingAdvisorSlug(): void {
		$tool = new ErlangCStaffingAdvisorTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$this->assertSame( 'erlang_c_staffing_advisor', $tool->getSlug() );
	}

	public function testStaffingAdvisorMultiChannel(): void {
		$this->erlang->method( 'toErlangs' )->willReturn( 2.0 );
		$this->erlang->method( 'minAgentsForServiceLevel' )->willReturn( 4 );
		$this->erlang->method( 'probabilityWait' )->willReturn( 0.1 );
		$this->erlang->method( 'averageWaitTime' )->willReturn( 2.0 );
		$this->erlang->method( 'serviceLevel' )->willReturn( 0.85 );
		$this->erlang->method( 'utilisation' )->willReturn( 0.5 );

		$tool   = new ErlangCStaffingAdvisorTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$result = $tool->execute(
			array(
				'channels' => array(
					array( 'name' => 'voice', 'arrival_rate_per_hour' => 30, 'avg_handle_time' => 240 ),
					array( 'name' => 'chat', 'arrival_rate_per_hour' => 50, 'avg_handle_time' => 120 ),
				),
			),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 8, $result['data']['total_agents_required'] );
		$this->assertCount( 2, $result['data']['channels'] );
	}

	public function testStaffingAdvisorEmptyChannels(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'channels must be a non-empty array.' ) );

		$tool   = new ErlangCStaffingAdvisorTool( $this->errorFactory, $this->erlang, $this->settings, $this->http );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	public function testAllErlangSchemasReturnValidJsonSchema(): void {
		$tools = array(
			new CalculateErlangCTool( $this->errorFactory, $this->erlang ),
			new ErlangCConcurrencyAdvisorTool( $this->errorFactory, $this->erlang, $this->settings ),
			new ErlangCQueueHealthTool( $this->errorFactory, $this->erlang, $this->settings, $this->http ),
			new ErlangCStaffingAdvisorTool( $this->errorFactory, $this->erlang, $this->settings, $this->http ),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->getParametersSchema();
			$this->assertIsArray( $schema );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
			$this->assertFalse( $schema['additionalProperties'] ?? true, "{$tool->getSlug()} should disallow additional properties." );
		}
	}
}
