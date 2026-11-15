<?php
/**
 * ContentStore persistence round-trip test (P5).
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;

final class ContentStoreRoundTripTest extends TestCase {

	use RefreshDatabase;

	public function test_create_and_find_round_trip(): void {
		$store = $this->app->make( ContentStoreInterface::class );

		$created = $store->create( new CreateContentCommand(
			title: 'Spike content',
			type: 'post',
			authorId: 0,
			status: 'publish',
			content: 'Body from the Laravel adapter.',
			excerpt: 'A spike fixture.',
			meta: array( 'source' => 'spike' ),
		) );

		$this->assertGreaterThan( 0, $created->id );
		$this->assertSame( 'Spike content', $created->title );

		$found = $store->find( $created->id );

		$this->assertNotNull( $found );
		$this->assertSame( $created->id, $found->id );
		$this->assertSame( 'Body from the Laravel adapter.', $found->content );
		$this->assertSame( 'publish', $found->status );
		$this->assertSame( 'post', $found->type );
	}
}
