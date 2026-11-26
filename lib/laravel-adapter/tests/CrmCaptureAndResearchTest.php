<?php
/**
 * The last two CRM slugs against the real adapter (Proposal 164 §164.70).
 *
 * `ToolProbesTest` covers the capture's shape, but a shape assertion would pass
 * on a tool that wrote nothing. The capture test therefore reads the record back
 * through `MemoryStoreInterface`: the wing, the tier, the tags and the policy
 * metadata all have to survive the JSON column, which is the part a fake store
 * cannot prove.
 *
 * The research test fakes the search provider because the tool's own work is
 * the query and the projection, not the network. A catch-all 500 is faked after
 * the real pattern so an unexpected URL fails as `search_failed` instead of
 * leaving the suite or reaching the internet.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;

final class CrmCaptureAndResearchTest extends TestCase {

	use RefreshDatabase;

	public function test_the_capture_writes_through_the_bound_memory_store(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'crm_capture_interaction' ), 'The capture tool is not registered.' );

		$result = $registry->get( 'crm_capture_interaction' )->execute(
			array(
				'account_id' => 'Acme Corp',
				'room' => 'interactions',
				'content' => 'Renewal call with the operations lead.',
			),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result, 'The capture tool should answer with the canonical envelope.' );
		$this->assertTrue( $result['success'] ?? false, print_r( $result, true ) );
		$this->assertSame( 'account/acmecorp', $result['data']['wing'] );
		$this->assertNotSame( '', $result['data']['memory_id'] );

		$stored = $this->app->make( MemoryStoreInterface::class )->get( $result['data']['memory_id'] );

		$this->assertTrue( $stored['found'], 'The capture did not reach the memory store.' );

		$record = $stored['record'];

		$this->assertSame( 'account/acmecorp', $record['wing'] );
		$this->assertSame( 'interactions', $record['room'] );
		$this->assertSame( 'recall', $record['tier'] );
		$this->assertSame( 'Renewal call with the operations lead.', $record['content'] );
		$this->assertSame( array( 'crm', 'capture' ), $record['tags'] );

		// The policy travelled in the metadata JSON column and came back decoded.
		$this->assertSame( 'pii', $record['metadata']['sensitivity'] );
		$this->assertSame( 'legitimate_interest', $record['metadata']['consent_basis'] );
		$this->assertTrue( $record['metadata']['verbatim'] );
		$this->assertSame( 'crm_capture_interaction', $record['metadata']['source'] );
	}

	public function test_the_research_reaches_the_search_provider_and_structures_the_hits(): void {
		Http::fake(
			array(
				'api.duckduckgo.com/*' => Http::response(
					array(
						'Heading' => 'Acme',
						'AbstractText' => 'Acme makes widgets.',
						'AbstractURL' => 'https://acme.test/about',
						'RelatedTopics' => array(
							array( 'Text' => 'Acme history', 'FirstURL' => 'https://acme.test/history' ),
						),
					)
				),
				// Anything else is a bug in the query path, not a network call.
				'*' => Http::response( array(), 500 ),
			)
		);

		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'research_company' ), 'The research tool is not registered.' );

		$result = $registry->get( 'research_company' )->execute(
			array(
				'company_name' => 'Acme',
				'industry' => 'Widgets',
				'location' => 'Lagos',
			),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result, 'The research tool should answer with the canonical envelope.' );
		$this->assertTrue( $result['success'] ?? false, print_r( $result, true ) );

		$this->assertSame(
			'Acme Widgets Lagos company information overview website contact',
			$result['data']['search_query']
		);
		$this->assertCount( 2, $result['data']['research_data']['search_results'] );
		$this->assertSame( 'Found 2 sources of information about Acme.', $result['data']['research_data']['findings_summary'] );

		Http::assertSent(
			static fn ( $request ): bool => str_contains( $request->url(), 'api.duckduckgo.com' )
				&& str_contains( urldecode( $request->url() ), 'Acme Widgets Lagos company information overview website contact' )
		);
	}
}
