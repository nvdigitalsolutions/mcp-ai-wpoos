<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

/**
 * `artisan nvoos:tool` has no session, and content tools need an actor.
 *
 * The command follows the WP-CLI convention: it runs unauthenticated unless
 * `--as` names a user. These tests pin the opt-in, the hint that tells an
 * operator the flag exists, and the usage error for a bad id.
 */
class NvoosToolCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: int, 1: int} The admin id and the post id.
     */
    private function seedPost(): array
    {
        $admin = User::factory()->admin()->create();

        $post = $this->app->make(ContentStoreInterface::class)->create(new CreateContentCommand(
            title: 'CLI read',
            type: 'post',
            authorId: (int) $admin->id,
            status: 'publish',
            content: 'Body.',
        ));

        return array((int) $admin->id, (int) $post->id);
    }

    public function test_as_runs_the_tool_as_that_user(): void
    {
        $this->assertNull(Auth::user(), 'The command must run without a session.');

        [$userId, $postId] = $this->seedPost();

        $this->artisan('nvoos:tool', array(
            'slug' => 'get_post',
            '--json' => json_encode(array('post_id' => $postId)),
            '--as' => $userId,
        ))
            ->expectsOutputToContain('CLI read')
            ->assertSuccessful();
    }

    public function test_without_as_it_runs_unauthenticated_and_says_so(): void
    {
        [, $postId] = $this->seedPost();

        // The tool cannot be authorised without an actor, so the command fails
        // and names the flag that would have made it work.
        $this->artisan('nvoos:tool', array(
            'slug' => 'get_post',
            '--json' => json_encode(array('post_id' => $postId)),
        ))
            ->expectsOutputToContain('hint: this command runs unauthenticated')
            ->assertFailed();
    }

    public function test_a_bad_as_value_fails_the_command(): void
    {
        $this->artisan('nvoos:tool', array('slug' => 'get_post', '--as' => 'nope'))
            ->expectsOutputToContain('--as must be a positive user id.')
            ->assertFailed();
    }
}
