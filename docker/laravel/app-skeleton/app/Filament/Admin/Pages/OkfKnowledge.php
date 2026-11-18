<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\OkfCatalogue;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Okf\OkfReadException;
use Nvoos\Core\Domain\Okf\OkfReader;

/**
 * OKF knowledge bundles — Proposal 149 §50, `/admin/okf`.
 *
 * The console's `okf` key is the chat drawer: bundles, concepts, one concept,
 * cross-bundle search and assistant grants. The WordPress surface this row also
 * covers is `okf-bundle-manager-admin-page` — bundle CRUD. **Only the read half
 * is ported**, and the contract row says so:
 *
 *  - there is no bundle writer on this runtime. `WP_MCP_AI_OKF_Writer` lives in
 *    the plugin, and a create/delete control here would have to invent one;
 *  - `okf/skills` is honest-empty on the API because no grant store exists, so
 *    the page does not render a grant section that could only ever be empty.
 *
 * The knowledge root is **deployment-global**, not estate data: one directory
 * at `config('oos.okf.path')` serves every estate, exactly as the shared
 * console drawer does. The page states that rather than implying the list is
 * the tenant's own.
 *
 * Filtering mirrors `OkfController`: type/status/trust plus a `q` over id,
 * title, description and tags. The page differs in one deliberate way — it
 * reports the pre-cap total and says when the list is truncated, because the
 * §45 `/ops/audit-log` rule is that a list never silently stops.
 */
class OkfKnowledge extends Page
{
    /** Matches the controller's cap. */
    private const MAX_CONCEPTS = 200;

    /** Matches the controller's cross-bundle cap. */
    private const MAX_SEARCH_RESULTS = 50;

    protected static ?string $slug = 'okf';

    protected static ?string $navigationLabel = 'Knowledge (OKF)';

    protected static ?string $title = 'OKF knowledge bundles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // The deeper read: same reference data as Tools, nested by bundle.
    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.okf';

    /** Selected bundle name; '' when the deployment has none. */
    public string $bundle = '';

    /** Selected concept id inside that bundle; '' closes the detail panel. */
    public string $concept = '';

    /** Local filter over the concept list. */
    public string $conceptSearch = '';

    /** Type / status / trust-tier filters, '' meaning "any". */
    public string $type = '';

    public string $status = '';

    public string $trustTier = '';

    /** Whether stale concepts are listed. */
    public bool $includeStale = true;

    /** Cross-bundle search query. */
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function mount(): void
    {
        $this->bundle = (string) ($this->bundleNames()[0] ?? '');
    }

    // ── Bundles ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function bundleNames(): array
    {
        return OkfCatalogue::fromConfig()->names();
    }

    /**
     * Bundle descriptors, as the console's bundle list reads them.
     *
     * @return list<array<string, mixed>>
     */
    public function bundles(): array
    {
        return OkfCatalogue::fromConfig()->bundles();
    }

    /**
     * The configured knowledge root, so an empty list is explainable.
     */
    public function root(): string
    {
        return OkfCatalogue::fromConfig()->root();
    }

    public function selectBundle(string $name): void
    {
        if (! in_array($name, $this->bundleNames(), true)) {
            return;
        }

        $this->bundle = $name;
        $this->concept = '';
        $this->conceptSearch = '';
        $this->type = '';
        $this->status = '';
        $this->trustTier = '';
        $this->includeStale = true;
    }

    // ── Concepts ────────────────────────────────────────────────────────

    /**
     * Types present in the selected bundle, for the filter.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $reader = $this->reader();

        return null === $reader ? array() : $reader->getTypes();
    }

    /**
     * The filtered concept list, with the pre-cap total.
     *
     * @return array{items: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function concepts(): array
    {
        $reader = $this->reader();

        if (null === $reader) {
            return array('items' => array(), 'total' => 0, 'truncated' => false);
        }

        $criteria = array('include_stale' => $this->includeStale);

        foreach (array('type' => 'type', 'status' => 'status', 'trust_tier' => 'trustTier') as $key => $property) {
            $value = trim((string) $this->{$property});

            if ('' !== $value) {
                $criteria[$key] = $value;
            }
        }

        try {
            $concepts = $reader->search($criteria);
        } catch (OkfReadException) {
            return array('items' => array(), 'total' => 0, 'truncated' => false);
        }

        $needle = mb_strtolower(trim($this->conceptSearch));

        if ('' !== $needle) {
            $concepts = array_values(array_filter(
                $concepts,
                static function (array $concept) use ($needle): bool {
                    $tags = is_array($concept['tags'] ?? null) ? implode(' ', $concept['tags']) : '';
                    $haystack = mb_strtolower(implode(' ', array_filter(array(
                        (string) ($concept['concept_id'] ?? ''),
                        (string) ($concept['title'] ?? ''),
                        (string) ($concept['description'] ?? ''),
                        $tags,
                    ))));

                    return str_contains($haystack, $needle);
                }
            ));
        }

        $total = count($concepts);

        return array(
            'items' => array_slice($concepts, 0, self::MAX_CONCEPTS),
            'total' => $total,
            'truncated' => $total > self::MAX_CONCEPTS,
        );
    }

    /**
     * One concept, with the shared frontmatter allowlist.
     *
     * @return array<string, mixed>|null
     */
    public function conceptDetail(): ?array
    {
        $reader = $this->reader();

        if (null === $reader || '' === $this->concept) {
            return null;
        }

        try {
            $detail = $reader->getConcept($this->concept);
        } catch (OkfReadException $exception) {
            return array(
                'error' => $exception->getMessage(),
                'concept_id' => $this->concept,
            );
        }

        $frontmatter = is_array($detail['frontmatter'] ?? null) ? $detail['frontmatter'] : array();
        $body = (string) ($detail['body'] ?? '');

        return array(
            'concept_id' => (string) ($detail['concept_id'] ?? $this->concept),
            'frontmatter' => OkfCatalogue::allowlisted($frontmatter),
            'body' => $body,
            'links' => $reader->conceptLinks($body, (string) ($detail['concept_id'] ?? $this->concept)),
            'trust_tier' => $reader->getTrustTier($frontmatter),
            'stale' => $reader->isStale($frontmatter),
        );
    }

    public function openConcept(string $conceptId): void
    {
        $this->concept = $conceptId;
    }

    public function closeConcept(): void
    {
        $this->concept = '';
    }

    // ── Cross-bundle search ─────────────────────────────────────────────

    /**
     * @return array{query: string, results: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function searchResults(): array
    {
        $query = trim($this->search);

        if ('' === $query) {
            return array('query' => '', 'results' => array(), 'total' => 0, 'truncated' => false);
        }

        $needle = mb_strtolower($query);
        $catalogue = OkfCatalogue::fromConfig();
        $results = array();
        $total = 0;

        foreach ($catalogue->names() as $name) {
            try {
                $concepts = $catalogue->readerFor($name)->search(array('include_stale' => true));
            } catch (OkfReadException) {
                continue;
            }

            foreach ($concepts as $concept) {
                $tags = is_array($concept['tags'] ?? null) ? implode(' ', $concept['tags']) : '';
                $haystack = mb_strtolower(implode(' ', array_filter(array(
                    (string) ($concept['concept_id'] ?? ''),
                    (string) ($concept['title'] ?? ''),
                    (string) ($concept['description'] ?? ''),
                    $tags,
                ))));

                if (! str_contains($haystack, $needle)) {
                    continue;
                }

                ++$total;

                if (count($results) < self::MAX_SEARCH_RESULTS) {
                    $results[] = array('bundle' => $name) + $concept;
                }
            }
        }

        return array(
            'query' => $query,
            'results' => $results,
            'total' => $total,
            'truncated' => $total > self::MAX_SEARCH_RESULTS,
        );
    }

    public function clearFilters(): void
    {
        $this->conceptSearch = '';
        $this->type = '';
        $this->status = '';
        $this->trustTier = '';
        $this->includeStale = true;
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    private function reader(): ?OkfReader
    {
        if ('' === $this->bundle) {
            return null;
        }

        try {
            return OkfCatalogue::fromConfig()->readerFor($this->bundle);
        } catch (OkfReadException) {
            return null;
        }
    }
}
