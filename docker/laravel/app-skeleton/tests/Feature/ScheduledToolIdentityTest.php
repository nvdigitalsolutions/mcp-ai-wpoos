<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Schedules\ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

/**
 * A timed run has no session, and content tools used to require one.
 *
 * `ContentStore::userCanAccess()` authorised with `Gate::allows()`, which
 * short-circuits to false when nothing is authenticated — whatever user id the
 * caller passed. A schedule resolves its actor and passes it in context, so
 * `get_post` and `update_post` failed on every timed run while passing in every
 * test that happened to call `actingAs()` first.
 *
 * This file deliberately does **not** authenticate. If someone adds `actingAs`
 * to the base test case, the precondition assertion below fails rather than
 * letting the regression disappear.
 */
class ScheduledToolIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $overrides
     */
    private function schedule(string $handler, array $payload, array $overrides = array()): object
    {
        $id  = $overrides['id'] ?? 'identity-' . $handler;
        $now = now();

        DB::table('nvoos_schedules')->insert(array_merge(array(
            'id'              => $id,
            'name'            => 'Identity check',
            'schedule_type'   => 'cron',
            'enabled'         => true,
            'next_run_at'     => null,
            'last_run_at'     => null,
            'priority'        => 10,
            'tags'            => json_encode(array()),
            'run_count'       => 0,
            'handler'         => $handler,
            'payload'         => json_encode($payload),
            'cron_expression' => '0 0 * * *',
            'created_at'      => $now,
            'updated_at'      => $now,
        ), $overrides));

        return DB::table('nvoos_schedules')->where('id', $id)->first();
    }

    public function test_a_scheduled_run_can_read_content_without_a_session(): void
    {
        // The precondition that made this a bug.
        $this->assertNull(Auth::user(), 'This test must run without an authenticated session.');

        $admin = User::factory()->admin()->create();
        $store = $this->app->make(ContentStoreInterface::class);

        $post = $store->create(new CreateContentCommand(
            title: 'Scheduled read',
            type: 'post',
            authorId: (int) $admin->id,
            status: 'publish',
            content: 'Body.',
        ));

        $row = $this->schedule('get_post', array('post_id' => $post->id));

        $result = $this->app->make(ScheduleRunner::class)->run($row);

        $this->assertSame('success', $result['status'], (string) ($result['error'] ?? ''));
        $this->assertNull(Auth::user(), 'The run must not leave a session behind.');
    }

    public function test_a_scheduled_run_can_edit_content_without_a_session(): void
    {
        $this->assertNull(Auth::user(), 'This test must run without an authenticated session.');

        $admin = User::factory()->admin()->create();
        $store = $this->app->make(ContentStoreInterface::class);

        $post = $store->create(new CreateContentCommand(
            title: 'Before the schedule',
            type: 'post',
            authorId: (int) $admin->id,
            status: 'publish',
            content: 'Body.',
        ));

        $row = $this->schedule('update_post', array(
            'post_id' => $post->id,
            'title'   => 'Changed by a schedule',
        ));

        $result = $this->app->make(ScheduleRunner::class)->run($row);

        $this->assertSame('success', $result['status'], (string) ($result['error'] ?? ''));
        $this->assertSame('Changed by a schedule', $store->find((int) $post->id)->title);
        $this->assertNull(Auth::user(), 'The run must not leave a session behind.');
    }
}
