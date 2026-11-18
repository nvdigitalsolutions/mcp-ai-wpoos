<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\OkfKnowledge;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §50 — `/admin/okf`.
 *
 * The page is the read half of `okf-bundle-manager-admin-page` plus the console
 * drawer's whole surface. These tests pin what makes it safe and honest:
 *
 *  - the frontmatter allowlist is shared with `OkfController`, so a key the
 *    console can read is not silently hidden here (or leaked here);
 *  - a traversing concept id is refused by the reader and shown as an error,
 *    not rendered as a file;
 *  - the concept list reports its pre-cap total and says when it is truncated;
 *  - knowledge is deployment-global by design, so the page states that every
 *    estate reads the same directory;
 *  - there is no form: creating a bundle would require an OKF writer this
 *    runtime does not have.
 */
final class AdminOkfTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        $this->root = sys_get_temp_dir().'/nvoos-okf-page-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);

        config(['oos.okf.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_the_page_describes_every_bundle(): void
    {
        $this->seedPolicies();

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/okf')
            ->assertOk()
            ->assertSee('OKF knowledge bundles')
            ->assertSee('policies')
            ->assertSee('shared by every estate')
            ->assertSee('human: 1')
            ->assertSee('Read-only');
    }

    public function test_a_missing_root_is_an_empty_state(): void
    {
        config(['oos.okf.path' => $this->root.'/nope']);

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/okf')
            ->assertOk()
            ->assertSee('No OKF bundle is present in that directory.');
    }

    public function test_the_first_bundle_is_selected_and_its_concepts_listed(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->assertSet('bundle', 'policies')
            ->assertSee('returns')
            ->assertSee('refunds')
            ->assertSee('Showing');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('status', 'deprecated')
            ->assertSee('refunds')
            ->assertDontSee('Returns');
    }

    public function test_the_trust_filter_narrows_the_list(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('trustTier', 'human-reviewed')
            ->assertSee('Returns')
            ->assertDontSee('Refund timeline');
    }

    public function test_excluding_stale_concepts_hides_them(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        // `returns` declares `stale_after: 2020-01-01`.
        Livewire::test(OkfKnowledge::class)
            ->set('includeStale', false)
            ->assertDontSee('Refund timeline')
            ->assertSee('refunds');
    }

    public function test_the_concept_search_matches_id_title_and_tags(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('conceptSearch', 'timeline')
            ->assertSee('refunds')
            ->assertDontSee('Returns');

        Livewire::test(OkfKnowledge::class)
            ->set('conceptSearch', 'returns')
            ->assertSee('Returns')
            // `refunds` is also a tag on `returns`, so assert on the other
            // concept's description rather than on a word both rows carry.
            ->assertDontSee('Refund timeline');
    }

    public function test_opening_a_concept_shows_allowlisted_frontmatter_and_the_body(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->call('openConcept', 'returns')
            ->assertSee('Refunds are covered')
            ->assertSee('human-reviewed')
            // `internal_notes` is not in the shared allowlist.
            ->assertDontSee('never exposed');
    }

    public function test_a_traversing_concept_id_is_refused_and_reported(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->call('openConcept', '../../etc/passwd')
            ->assertSee('That concept could not be read.');
    }

    public function test_cross_bundle_search_reports_which_bundle_matched(): void
    {
        $this->seedPolicies();

        $this->writeConcept('guide', 'getting-started', <<<'MD'
---
title: Getting started
description: How to begin.
type: guide
---

See the [refund timeline](../policies/refunds.md).
MD);

        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('search', 'timeline')
            ->assertSee('guide')
            ->assertSee('refunds');
    }

    public function test_selecting_a_bundle_clears_the_concept_and_filters(): void
    {
        $this->seedPolicies();

        $this->writeConcept('guide', 'start', <<<'MD'
---
title: Start
type: guide
---
Body.
MD);

        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('status', 'deprecated')
            ->set('conceptSearch', 'refund')
            ->call('openConcept', 'refunds')
            ->call('selectBundle', 'guide')
            ->assertSet('bundle', 'guide')
            ->assertSet('concept', '')
            ->assertSet('status', '')
            ->assertSet('conceptSearch', '');
    }

    public function test_selecting_an_unknown_bundle_is_ignored(): void
    {
        $this->seedPolicies();
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->call('selectBundle', '../../etc')
            ->assertSet('bundle', 'policies');
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/okf')
            ->assertForbidden();
    }

    /**
     * A page that cannot accept a payload cannot grow a second writer by
     * accident. Creating a bundle needs `WP_MCP_AI_OKF_Writer`, which this
     * runtime does not have.
     */
    public function test_the_page_accepts_no_payload(): void
    {
        $traits = class_uses_recursive(OkfKnowledge::class);

        $this->assertArrayNotHasKey(\Filament\Forms\Concerns\InteractsWithForms::class, $traits);
        $this->assertFalse(
            is_subclass_of(OkfKnowledge::class, \Filament\Forms\Contracts\HasForms::class),
            'The OKF surface is read-only; a form would need the contract row to declare a writer.'
        );
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function writeConcept(string $bundle, string $id, string $markdown): void
    {
        $file = $this->root.'/'.$bundle.'/'.$id.'.md';

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $markdown);
    }

    private function seedPolicies(): void
    {
        $this->writeConcept('policies', 'returns', <<<'MD'
---
title: Returns
description: How returns are handled.
type: policy
tags: [returns, refunds]
status: stable
stale_after: 2020-01-01
verified:
  - by: human:alice
    at: 2026-01-01
internal_notes: never exposed
---

Refunds are covered in [refunds](refunds.md).
MD);

        $this->writeConcept('policies', 'refunds', <<<'MD'
---
title: Refunds
description: Refund timeline.
type: policy
tags:
  - refunds
status: deprecated
---

See [returns](returns.md).
MD);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: array() as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
