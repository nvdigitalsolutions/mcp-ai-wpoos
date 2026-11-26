<?php

namespace Tests\Feature;

use App\Models\AdminSavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\SavedViewRepository;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 T-0.9 — saved table views are per user, per resource, per estate.
 *
 * The estate scoping is the part that matters: the same user switching to
 * another customer's estate must not see the views they saved on the first
 * one, because a saved view is a filter over THAT estate's rows.
 */
class AdminSavedViewTest extends TestCase
{
    use RefreshDatabase;

    private SavedViewRepository $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->views = new SavedViewRepository;
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    public function test_saving_a_view_persists_its_state(): void
    {
        $user = User::factory()->create();

        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'draft', 'sort' => '-name'));

        $saved = $this->views->forUser($user, 'assistants');

        $this->assertCount(1, $saved);
        $this->assertSame('Drafts', $saved->first()->name);
        $this->assertSame(array('status' => 'draft', 'sort' => '-name'), $saved->first()->state);
    }

    /**
     * Re-saving the same name overwrites rather than throwing on the unique
     * index — a user adjusting their saved view expects an update.
     */
    public function test_saving_the_same_name_replaces_the_state(): void
    {
        $user = User::factory()->create();

        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'draft'));
        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'pending'));

        $this->assertSame(1, AdminSavedView::query()->count());
        $this->assertSame(array('status' => 'pending'), $this->views->forUser($user, 'assistants')->first()->state);
    }

    public function test_views_are_listed_by_name_and_scoped_to_the_resource(): void
    {
        $user = User::factory()->create();

        $this->views->save($user, 'assistants', 'Zeta', array('status' => 'draft'));
        $this->views->save($user, 'assistants', 'Alpha', array('status' => 'draft'));
        $this->views->save($user, 'teams', 'Other', array('status' => 'draft'));

        $this->assertSame(
            array('Alpha', 'Zeta'),
            $this->views->forUser($user, 'assistants')->pluck('name')->all()
        );
        $this->assertSame(
            array('Other'),
            $this->views->forUser($user, 'teams')->pluck('name')->all()
        );
    }

    public function test_a_view_saved_on_one_estate_is_invisible_on_another(): void
    {
        $user = User::factory()->create();

        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'draft'));

        TenantContext::set(Tenant::OPERATOR_ID);
        $this->assertCount(0, $this->views->forUser($user, 'assistants'));

        // Same name on the second estate is a separate row, not a collision.
        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'pending'));

        $this->assertSame(2, AdminSavedView::query()->count());
        $this->assertSame(array('status' => 'pending'), $this->views->forUser($user, 'assistants')->first()->state);
    }

    public function test_views_are_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->views->save($owner, 'assistants', 'Drafts', array('status' => 'draft'));

        $this->assertCount(1, $this->views->forUser($owner, 'assistants'));
        $this->assertCount(0, $this->views->forUser($other, 'assistants'));
    }

    public function test_forget_removes_only_the_named_view_on_the_current_estate(): void
    {
        $user = User::factory()->create();

        $this->views->save($user, 'assistants', 'Drafts', array('status' => 'draft'));
        $this->views->save($user, 'assistants', 'Live', array('status' => 'published'));

        $this->assertTrue($this->views->forget($user, 'assistants', 'Drafts'));
        $this->assertSame(array('Live'), $this->views->forUser($user, 'assistants')->pluck('name')->all());

        // Already gone.
        $this->assertFalse($this->views->forget($user, 'assistants', 'Drafts'));
    }
}
