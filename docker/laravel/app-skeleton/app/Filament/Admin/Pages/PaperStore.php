<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\RecordsSchema;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;

/**
 * Paper Store and record schemas — Proposal 149 §52, `/admin/paper-store`.
 *
 * READ-ONLY browser over `PaperStoreInterface`. The console owns CRUD
 * (`POST|PUT|DELETE /paper-store/{collection}`), and this page deliberately
 * does not duplicate it: the row is slated for the manifest engine (Wave 3,
 * order item 10), which will render records from a schema rather than from a
 * second hand-written form. A create/edit dialog here would be a writer that
 * Wave 3 immediately replaces.
 *
 * **Shares the deployment, not the estate.** The Paper Store is one flat-file
 * directory (`config('oos.paper_store_root')`) read by both runtimes, exactly
 * as the contract promises ("a record written by WordPress is readable here and
 * vice versa"). Unlike §47, §49 and §51 this is *not* an estate-scoping defect
 * to fix: the store holds curated shared knowledge (research records, prompts,
 * workflows, brand kits) and has no owner column on either runtime. Making it
 * per-estate would break the cross-runtime property. The page states the
 * sharing rather than implying the list is the tenant's own.
 *
 * The second console key on this row, `recordsSchema`, is rendered as a small
 * table from {@see RecordsSchema} — the same catalogue
 * `GET /pro/records/schema` serves, so the two cannot disagree about which
 * entities exist.
 *
 * The read loop mirrors `PaperStoreController`: search merges per-collection
 * pages and sorts by `updated_at` descending, capped at 50. It is duplicated on
 * purpose and temporarily — see the class note above about Wave 3 — rather than
 * refactoring a tested controller for a surface that is about to be replaced.
 */
class PaperStore extends Page
{
    /** Mirrors the console's cross-collection search cap. */
    private const MAX_SEARCH_RESULTS = 50;

    /** Records shown per collection. */
    private const PAGE_SIZE = 50;

    protected static ?string $slug = 'paper-store';

    protected static ?string $navigationLabel = 'Paper Store';

    protected static ?string $title = 'Paper Store and record schemas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Stored knowledge: after the read-only catalogues, before memory.
    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.paper-store';

    /** Selected collection; '' means none. */
    public string $collection = '';

    /** Selected record id inside that collection; '' closes the detail. */
    public string $recordId = '';

    /** Local filters over the record list. */
    public string $status = '';

    public string $tag = '';

    /** Cross-collection search. */
    public string $search = '';

    /**
     * The opened record, or null.
     *
     * @var array<string, mixed>|null
     */
    public ?array $detail = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function mount(): void
    {
        $this->collection = (string) ($this->collections()[0]['name'] ?? '');
    }

    // ── Collections ─────────────────────────────────────────────────────

    /**
     * Collections, with the record count each holds.
     *
     * A flat-file store has to read a collection to count it; collections are
     * few (research records, prompts, workflows, brand kits) and the page asks
     * once per render.
     *
     * @return list<array{name: string, total: int}>
     */
    public function collections(): array
    {
        $store = $this->store();
        $rows = [];

        foreach ($store->collections() as $name) {
            $rows[] = array(
                'name' => (string) $name,
                'total' => (int) $store->query((string) $name, array('limit' => 1))['total'],
            );
        }

        return $rows;
    }

    public function selectCollection(string $name): void
    {
        $names = array_column($this->collections(), 'name');

        if (! in_array($name, $names, true)) {
            return;
        }

        $this->collection = $name;
        $this->recordId = '';
        $this->detail = null;
        $this->status = '';
        $this->tag = '';
    }

    // ── Records ─────────────────────────────────────────────────────────

    /**
     * The filtered record page, with the pre-cap total.
     *
     * @return array{items: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function records(): array
    {
        if ('' === $this->collection) {
            return array('items' => array(), 'total' => 0, 'truncated' => false);
        }

        $page = $this->store()->query($this->collection, array(
            'status' => trim($this->status),
            'tags' => trim($this->tag),
            'limit' => self::PAGE_SIZE,
            'offset' => 0,
        ));

        return array(
            'items' => array_values($page['records']),
            'total' => (int) $page['total'],
            'truncated' => (int) $page['total'] > self::PAGE_SIZE,
        );
    }

    /**
     * Open one record.
     *
     * A missing id shows the "not found" panel rather than an error page — the
     * same answer the API gives (`PaperStoreController::missing()`).
     */
    public function openRecord(string $id): void
    {
        $record = '' === $this->collection ? null : $this->store()->find($this->collection, $id);

        if (null === $record) {
            $this->recordId = '';
            $this->detail = array('error' => 'Paper Store record not found.');

            return;
        }

        $this->recordId = $id;
        $this->detail = array(
            'id' => (string) $record['id'],
            'collection' => (string) $record['collection'],
            'title' => (string) $record['title'],
            'description' => (string) ($record['description'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'type' => (string) ($record['type'] ?? ''),
            'tags' => array_values((array) ($record['tags'] ?? array())),
            'body' => $record['body'] ?? null,
            'meta' => (array) ($record['meta'] ?? array()),
            'created_at' => (string) ($record['created_at'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
        );
    }

    public function closeRecord(): void
    {
        $this->recordId = '';
        $this->detail = null;
    }

    // ── Cross-collection search ─────────────────────────────────────────

    /**
     * @return array{query: string, results: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function searchResults(): array
    {
        $query = trim($this->search);

        if ('' === $query) {
            return array('query' => '', 'results' => array(), 'total' => 0, 'truncated' => false);
        }

        $store = $this->store();
        $records = array();

        foreach ($store->collections() as $name) {
            $page = $store->query((string) $name, array(
                'search' => $query,
                'limit' => self::MAX_SEARCH_RESULTS,
            ));

            $records = array_merge($records, $page['records']);
        }

        usort(
            $records,
            static fn (array $a, array $b): int => strcmp(
                (string) ($b['updated_at'] ?? ''),
                (string) ($a['updated_at'] ?? '')
            )
        );

        $total = count($records);

        return array(
            'query' => $query,
            'results' => array_slice($records, 0, self::MAX_SEARCH_RESULTS),
            'total' => $total,
            'truncated' => $total > self::MAX_SEARCH_RESULTS,
        );
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function clearFilters(): void
    {
        $this->status = '';
        $this->tag = '';
    }

    // ── Record schemas ──────────────────────────────────────────────────

    /**
     * The `recordsSchema` console key, read from the same catalogue the API
     * serves.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entities(): array
    {
        return RecordsSchema::entities();
    }

    private function store(): PaperStoreInterface
    {
        return app(PaperStoreInterface::class);
    }
}
