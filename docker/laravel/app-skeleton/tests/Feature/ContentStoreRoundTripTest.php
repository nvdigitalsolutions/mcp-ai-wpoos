<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

class ContentStoreRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_store_round_trip(): void
    {
        $store = app(ContentStoreInterface::class);

        $created = $store->create(new CreateContentCommand(
            title: 'App-level spike content',
            type: 'post',
            authorId: 0,
            status: 'publish',
            content: 'Round-trip body.',
        ));

        $found = $store->find($created->id);

        $this->assertNotNull($found);
        $this->assertSame('App-level spike content', $found->title);
        $this->assertSame('Round-trip body.', $found->content);
    }
}
