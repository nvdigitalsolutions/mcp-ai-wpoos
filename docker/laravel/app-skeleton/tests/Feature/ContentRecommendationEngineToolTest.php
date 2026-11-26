<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

/**
 * `content_recommendation_engine` against the app's real content store — §24.
 *
 * The unit suite pins the scoring arithmetic with a mocked store. This pins the part
 * only the app can get wrong: that the rows are real, that the source cannot be
 * recommended to itself, that a draft is never offered as reading, and that
 * `category_based` filters on the taxonomy rows the database actually holds rather
 * than on a constant.
 *
 * No network: this tool never calls a provider.
 */
final class ContentRecommendationEngineToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('content_recommendation_engine'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_ranks_a_real_row_above_an_unrelated_one(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget guide.');

        $close = $this->create($user, 'Widget fitting notes', 'Widget fitting in practice.');
        $this->create($user, 'Sourdough notes', 'Bread and water.');

        $result = $this->tool()->execute(
            array( 'post_id' => $source->id ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['data']['recommendations']);

        // Every id is a row that exists — nothing invented, because nothing is asked
        // to invent.
        $this->assertSame($close->id, $result['data']['recommendations'][0]['id']);
        $this->assertSame('similarity', $result['data']['strategy']);

        $ids = \array_column($result['data']['recommendations'], 'id');
        $this->assertNotContains($source->id, $ids, 'The source recommended itself.');
    }

    public function test_a_draft_is_never_recommended(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget guide.');

        $this->create($user, 'Widget draft notes', 'Secret widget plans.', 'draft');

        $result = $this->tool()->execute(
            array( 'post_id' => $source->id ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);

        $titles = \array_column($result['data']['recommendations'], 'title');

        $this->assertNotContains('Widget draft notes', $titles);
    }

    public function test_category_mode_uses_the_real_taxonomy(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create(
            $user,
            'Widget fitting',
            'Fitting a widget guide.',
            'publish',
            array( 'category' => array( 'Workshop' ) )
        );

        $sharing = $this->create(
            $user,
            'Widget care',
            'Widget maintenance tips.',
            'publish',
            array( 'category' => array( 'Workshop' ) )
        );

        $other = $this->create(
            $user,
            'Widget baking',
            'Widgets in the oven.',
            'publish',
            array( 'category' => array( 'Baking' ) )
        );

        $result = $this->tool()->execute(
            array(
                'post_id'             => $source->id,
                'recommendation_type' => 'category_based',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);

        $ids = \array_column($result['data']['recommendations'], 'id');

        $this->assertContains($sharing->id, $ids);
        $this->assertNotContains($other->id, $ids, 'An item in another category survived the filter.');

        // The category is real, so the shared-term component is too.
        $this->assertSame(1.0, $result['data']['recommendations'][0]['signals']['taxonomy']);
    }

    public function test_a_source_without_categories_reports_that_finding(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget guide.');

        $result = $this->tool()->execute(
            array(
                'post_id'             => $source->id,
                'recommendation_type' => 'category_based',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['recommendations']);
        $this->assertStringContainsString('no categories or tags', $result['message']);
    }

    public function test_trending_returns_recent_rows(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $recent = $this->create($user, 'Widget news', 'Widget goings on.');

        $result = $this->tool()->execute(
            array( 'recommendation_type' => 'trending' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('recency', $result['data']['strategy']);

        $ids = \array_column($result['data']['recommendations'], 'id');

        $this->assertContains($recent->id, $ids);
    }

    public function test_the_unsupported_actions_report_what_is_missing(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        // This host's error factory returns a Throwable, and a tool hands it back
        // rather than throwing, so the returned value is the refusal. Its message is
        // the part a caller acts on.
        $expected = array(
            'train_model' => 'nothing to fit',
            'track_interaction' => 'records no interactions',
            'analyze_performance' => 'no click-through',
        );

        foreach ($expected as $action => $fragment) {
            $result = $this->tool()->execute(
                array( 'action' => $action ),
                array( 'user_id' => (int) $user->getKey() )
            );

            $this->assertInstanceOf(
                \RuntimeException::class,
                $result,
                \sprintf('%s should be refused, not silently accepted.', $action)
            );

            $this->assertStringContainsString(
                $fragment,
                $result->getMessage(),
                \sprintf('%s did not explain what is missing.', $action)
            );
        }
    }

    private function create(
        User $user,
        string $title,
        string $content,
        string $status = 'publish',
        array $taxonomy = array(),
    ): \Nvoos\Core\Domain\Entity\ContentItem {
        return $this->store()->create(new CreateContentCommand(
            title: $title,
            type: 'post',
            authorId: (int) $user->getKey(),
            status: $status,
            content: $content,
            excerpt: $content,
            taxonomyInput: $taxonomy,
        ));
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('content_recommendation_engine');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
