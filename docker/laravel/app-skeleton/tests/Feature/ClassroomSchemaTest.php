<?php

namespace Tests\Feature;

use Database\Seeders\ClassroomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Tests\TestCase;

/**
 * 090-d cluster 3 — the classroom family's storage (local-mirror scope).
 *
 * The read tools land next; this pins the two content types, the taxonomy and
 * the seeder they will query, so the schema cannot drift before the tools need
 * it.
 */
class ClassroomSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_classroom_content_types_are_registered(): void
    {
        $schema = $this->app->make(SchemaStoreInterface::class);

        $this->assertNotNull($schema->getPostType('mcp_ai_eca'), 'mcp_ai_eca should be a known type.');
        $this->assertNotNull($schema->getPostType('mcp_ai_student'), 'mcp_ai_student should be a known type.');
        $this->assertNotNull($schema->getTaxonomy('mcp_ai_eca_category'), 'The ECA category taxonomy should be known.');

        $this->assertNotEmpty($schema->listTaxonomies('mcp_ai_eca'));
    }

    public function test_the_seeder_writes_an_idempotent_classroom(): void
    {
        $this->seed(ClassroomSeeder::class);
        $this->seed(ClassroomSeeder::class);

        $store = $this->app->make(ContentStoreInterface::class);

        $this->assertSame(1, $store->query(new ContentQuery(types: array('mcp_ai_eca')))->total);
        $this->assertSame(2, $store->query(new ContentQuery(types: array('mcp_ai_student')))->total);
    }
}
