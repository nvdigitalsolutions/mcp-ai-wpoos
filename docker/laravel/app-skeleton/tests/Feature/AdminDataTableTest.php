<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\OkfKnowledge;
use App\Filament\Admin\Pages\PaperStore;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §55.5, step 4 — the one data-table contract.
 *
 * Eight tables on `blueprints`, `paper-store` and OKF were hand-rolled copies of
 * the same declarations, and each copy had drifted: **no `<caption>` anywhere**
 * (WCAG 1.3.1 — a table without an accessible name), `scope` on some tables and
 * not others, no sticky header, and padding spelled out on every cell. The
 * shared component owns the shell so those exist once.
 *
 * Two things this test treats as contract rather than cosmetics:
 *
 *  - **The empty state lives in the body.** A filter that matches nothing used
 *    to replace the whole table with a sentence, which also removed the column
 *    headings — so the operator lost the answer to "what did I filter on?" at
 *    exactly the moment they needed it.
 *  - **No page may hand-roll a `<thead>` again.** That is the migration guard:
 *    the remaining `<table>` elements on these pages are definition lists with
 *    no header row, so `<thead>` in a page source now means a table escaped the
 *    component.
 */
final class AdminDataTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `@once` marks its key on the shared view factory, which outlives a
        // single test in the same process. Without this reset the style-count
        // assertion would depend on test order — the classic way a test passes
        // for the wrong reason.
        $this->app['view']->flushState();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_caption_names_the_table_for_screen_readers_only(): void
    {
        $html = $this->renderTable(caption: 'Blueprints in the fashion toolkit');

        $this->assertStringContainsString(
            '<caption class="fi-sr-only">Blueprints in the fashion toolkit</caption>',
            $html
        );
    }

    public function test_every_column_gets_a_scoped_header_in_order(): void
    {
        $html = $this->renderTable(columns: array(
            array('label' => 'Blueprint'),
            array('label' => 'Tools'),
        ));

        $this->assertSame(2, substr_count($html, 'scope="col"'));
        $this->assertSame(array('Blueprint', 'Tools'), $this->headerLabels($html));
    }

    public function test_an_actions_column_can_be_named_for_screen_readers_only(): void
    {
        $html = $this->renderTable(columns: array(
            array('label' => 'Status'),
            array('srLabel' => 'Actions'),
        ));

        $this->assertSame(array('Status', 'Actions'), $this->headerLabels($html));
        $this->assertMatchesRegularExpression(
            '/<th scope="col">\s*<span class="fi-sr-only">Actions<\/span>\s*<\/th>/',
            $html,
            'The actions heading must be announced but not printed.'
        );
    }

    public function test_a_zero_row_count_puts_the_empty_state_inside_the_body(): void
    {
        $html = $this->renderTable(
            columns: array(
                array('label' => 'Record'),
                array('label' => 'Status'),
                array('srLabel' => 'Actions'),
            ),
            rows: 0,
            empty: 'No record matches those filters.',
        );

        $this->assertStringContainsString('colspan="3"', $html);
        $this->assertStringContainsString('No record matches those filters.', $html);
        $this->assertStringNotContainsString('row-marker', $html);
    }

    public function test_a_non_zero_row_count_renders_the_slot_instead(): void
    {
        $html = $this->renderTable(rows: 2, empty: 'never printed');

        $this->assertStringContainsString('row-marker', $html);
        $this->assertStringNotContainsString('never printed', $html);
        $this->assertStringNotContainsString('colspan=', $html);
    }

    public function test_two_tables_on_one_page_share_a_single_style_block(): void
    {
        $html = Blade::render(
            '<x-admin.data-table caption="First" :columns="[array(\'label\' => \'A\')]" :rows="1">'
            .'<tr><td>one</td></tr></x-admin.data-table>'
            .'<x-admin.data-table caption="Second" :columns="[array(\'label\' => \'A\')]" :rows="1">'
            .'<tr><td>two</td></tr></x-admin.data-table>'
        );

        $this->assertSame(2, substr_count($html, '<table data-admin-data-table>'));
        $this->assertSame(1, substr_count($html, 'border-collapse: separate;'));
    }

    /**
     * The sticky header must not become a white band on a dark page.
     *
     * `--gray-50` is not theme-scoped, so a single background declaration left
     * the header light while `.fi-body` went to `--gray-950` — measured in a
     * browser with `.dark` toggled on. PHP cannot compute CSS, so what is
     * asserted here is the pair of declarations and their order; equal
     * specificity means the later one wins, which is why order is the contract.
     */
    public function test_the_sticky_header_follows_the_dark_theme(): void
    {
        $html = $this->renderTable();

        $this->assertStringContainsString('background: var(--gray-50);', $html);
        $this->assertStringContainsString(':where(.dark, .dark *) [data-admin-data-table] thead th', $html);
        $this->assertGreaterThan(
            strpos($html, 'background: var(--gray-50);'),
            strpos($html, 'background: var(--gray-950);'),
            'The dark declaration has to come after the light one to win at equal specificity.'
        );
    }

    /**
     * The migration guard, over **every** admin view.
     *
     * A definition list has no header row, so any `<thead>` in an admin view is a
     * data table that did not go through the component. That is precise where a
     * ban on `<table>` is not: the surviving tables on blueprints, paper-store,
     * OKF and security's snapshot detail are definition lists, and `<thead>` is
     * the one thing that tells the two apart.
     *
     * Blade comments are stripped first, for the reason `AdminPanelBladeTest`
     * records: prose is not code, and a view may legitimately explain the rule it
     * obeys.
     *
     * `/ops` is excluded on purpose — §55.4 left the operator panel's navigation
     * flat, so its four tables follow whenever that panel next changes.
     */
    public function test_no_admin_view_hand_rolls_a_table_header(): void
    {
        $files = array();

        foreach (File::allFiles(resource_path('views/filament/admin')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'The admin panel has Blade views; this guard would otherwise pass vacuously.');

        foreach ($files as $file) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents($file)
            );

            $this->assertStringNotContainsString(
                '<thead',
                $source,
                basename($file).' renders a table header directly. Use `<x-admin.data-table>` so the '
                .'caption, `scope` and sticky header cannot drift again — or drop the header row if '
                .'the table is a definition list.'
            );
        }
    }

    public function test_the_blueprints_page_renders_the_shared_table(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/blueprints')
            ->assertOk()
            ->assertSee('data-admin-data-table', false)
            ->assertSee('<caption', false)
            ->assertSee('scope="col"', false)
            // The caption is per toolkit group, so it names the group.
            ->assertSee('Blueprints in the ', false);
    }

    public function test_a_paper_store_search_with_no_matches_keeps_the_columns_visible(): void
    {
        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->set('search', 'zzz-no-such-record-zzz')
            ->assertSee('No record matches that query.')
            ->assertSee('data-admin-data-table', false)
            ->assertSee('scope="col"', false);
    }

    /**
     * Same contract on the OKF page. The catalogue root is unset here, so the
     * search walks zero bundles — which is exactly the empty result a filter with
     * no match produces.
     */
    public function test_an_okf_search_with_no_matches_keeps_the_columns_visible(): void
    {
        $this->bootPanel();

        Livewire::test(OkfKnowledge::class)
            ->set('search', 'zzz-no-such-concept-zzz')
            ->assertSee('No concept matches that query.')
            ->assertSee('data-admin-data-table', false)
            ->assertSee('scope="col"', false);
    }

    /**
     * @param list<array<string, string>> $columns
     */
    private function renderTable(
        string $caption = 'Table',
        array $columns = array(array('label' => 'Column')),
        ?int $rows = 1,
        ?string $empty = null,
    ): string {
        return Blade::render(
            '<x-admin.data-table :caption="$caption" :columns="$columns" :rows="$rows" :empty="$empty">'
            .'<tr><td>row-marker</td></tr>'
            .'</x-admin.data-table>',
            array(
                'caption' => $caption,
                'columns' => $columns,
                'rows' => $rows,
                'empty' => $empty,
            )
        );
    }

    /**
     * @return list<string>
     */
    private function headerLabels(string $html): array
    {
        preg_match_all('/<th scope="col">(.*?)<\/th>/s', $html, $matches);

        return array_map(
            static fn (string $cell): string => trim(strip_tags($cell)),
            $matches[1]
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
}
