<?php

namespace App\Support\Professions;

use App\Models\Profession;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Laravel\Adapter\ProfessionRepository as BaseProfessionRepository;

/**
 * The estate-owned half of the profession catalogue — Proposal 149 §170.
 *
 * WHAT IT IS
 *
 * `save_profession`'s destination and the source `OverlayProfessionRepository`
 * merges over the base catalogue. It implements both halves of the split the
 * shared package declares: the overlay read
 * ({@see ProfessionOverlayInterface}) and the optional write
 * ({@see ProfessionWriteInterface}). The package default is a JSON file; this
 * store is the app's, and it exists because the app needs three things a file
 * cannot give it:
 *
 *  - **tenancy as an invariant.** The row carries `tenant_id` and `Profession`
 *    applies the global scope, so a slug lookup cannot reach another estate's
 *    override.
 *  - **an upsert that is actually unique.** `(tenant_id, slug)` is a unique key,
 *    so a second `save_profession` for `lawyer` updates the row instead of
 *    appending a shadow.
 *  - **queryability.** The catalogue is grouped by category and counted on two
 *    panels; a JSON blob would make each of those a PHP loop over every estate.
 *
 * COPY-ON-WRITE
 *
 * A save for a slug with no overlay row is seeded from the base record first, so
 * omitting `description` keeps the base description rather than blanking it —
 * the WordPress "omitted !== cleared" rule. The consequence is documented where
 * the merge happens: the estate takes ownership of that row at the first edit
 * and stops receiving base updates for it.
 *
 * WHY THERE IS NO TENANT CLOSURE
 *
 * Neighbouring stores take one because they can be resolved before
 * `ResolveApiTenant` runs. This one reads {@see TenantContext::current()} at call
 * time instead, exactly as `Profession`'s global scope does — so the read scope
 * and the written `tenant_id` cannot drift, which is the failure a second
 * resolver would eventually cause.
 *
 * WHY THE VERSION IS MEMOISED
 *
 * `catalogueVersion()` is read on every cached catalogue access to build the
 * cache key. The memo is cleared on write, and the binding is request-scoped, so
 * a long-lived Octane worker cannot pin a pre-write version. The fingerprint is
 * `COUNT(*) | MAX(updated_at)` rather than a content hash: it is one aggregate
 * query, and it changes on any insert, update or delete.
 *
 * WHY THE ROWS ARE MEMOISED TOO
 *
 * The merge decorator asks this store for the overlay once per record read, and
 * a catalogue listing walks every slug — so without a memo, rendering 260
 * professions issued 260 full-table selects. Measured on the admin catalogue
 * page, that was the difference between one query and several hundred. The memo
 * is request-scoped for the same reason the fingerprint is, and `save()` clears
 * it so a writer sees its own write.
 */
final class ProfessionOverlayStore implements ProfessionOverlayInterface, ProfessionWriteInterface
{
    /**
     * Memoised fingerprint for this request; null after a write.
     */
    private ?string $version = null;

    /**
     * Memoised overlay rows for this request; null before the first read and
     * after a write.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $overlays = null;

    /**
     * @param ProfessionRepositoryInterface $base The BASE tier, for copy-on-write
     *                                             seeding. Must not be the merge
     *                                             decorator: asking it for a slug
     *                                             would consult this store again.
     */
    public function __construct(private readonly ProfessionRepositoryInterface $base)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public function overlays(): array
    {
        if ( null !== $this->overlays ) {
            return $this->overlays;
        }

        $records = array();

        foreach (Profession::query()->get() as $row) {
            $record = $row->toRecord();

            $records[(string) $record['slug']] = $record;
        }

        return $this->overlays = $records;
    }

    /**
     * {@inheritdoc}
     */
    public function overlayVersion(): string
    {
        if (null !== $this->version) {
            return $this->version;
        }

        $row = Profession::query()
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')
            ->first();

        $fingerprint = (int) ($row->total ?? 0).'|'.(string) ($row->latest ?? '');

        return $this->version = 'ov-'.substr(hash('sha256', $fingerprint), 0, 16);
    }

    /**
     * {@inheritdoc}
     *
     * Only keys the caller sent are written. An explicit empty string clears a
     * field, which is the affordance the WordPress CPT gives; an omitted key
     * leaves it alone, which is what stops a title-only update from wiping a
     * description.
     *
     * @param array<string, mixed> $data Profession fields.
     *
     * @return array{saved: bool, action?: string, profession?: array<string, mixed>, error?: string}
     */
    public function save(array $data): array
    {
        $slug = BaseProfessionRepository::key((string) ($data['slug'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));

        if ('' === $slug || '' === $title) {
            return array(
                'saved' => false,
                'error' => 'slug and title are required.',
            );
        }

        $existing = Profession::query()->where('slug', $slug)->first();
        $action = null === $existing ? 'created' : 'updated';

        $record = null !== $existing
            ? $existing->toRecord()
            : $this->seed($slug);

        $record['slug'] = $slug;
        $record['name'] = $title;

        foreach (array('description', 'role_description', 'knowledge_base') as $key) {
            if (array_key_exists($key, $data)) {
                $record[$key] = (string) $data[$key];
            }
        }

        if (array_key_exists('category', $data)) {
            $category = BaseProfessionRepository::key((string) $data['category']);

            $record['category'] = '' === $category ? 'other' : $category;
        }

        foreach (array('expertise', 'warnings', 'default_tools') as $key) {
            if (array_key_exists($key, $data)) {
                $record[$key] = BaseProfessionRepository::stringList($data[$key]);
            }
        }

        $attributes = array(
            'title' => (string) $record['name'],
            'description' => (string) $record['description'],
            'category' => (string) $record['category'],
            'role_description' => (string) $record['role_description'],
            'expertise' => array_values((array) $record['expertise']),
            'warnings' => array_values((array) $record['warnings']),
            'knowledge_base' => (string) $record['knowledge_base'],
            'default_tools' => array_values((array) $record['default_tools']),
        );

        try {
            $saved = $this->persist($slug, $attributes);
        } catch (QueryException $exception) {
            return array(
                'saved' => false,
                'error' => 'The profession could not be saved: '.$exception->getMessage(),
            );
        }

        $this->version = null;
        $this->overlays = null;

        return array(
            'saved' => true,
            'action' => $action,
            'profession' => $saved->toRecord(),
        );
    }

    /**
     * Insert or update, retrying once as an update if a concurrent create won.
     *
     * Two administrators saving the same new slug at the same moment is rare but
     * real, and the unique key turns it into a QueryException for the loser.
     * Returning "could not be saved" for a row that now exists would be a false
     * negative: the second attempt is an update and settles.
     *
     * @param array<string, mixed> $attributes Column values, without the slug.
     */
    private function persist(string $slug, array $attributes): Profession
    {
        $row = Profession::query()->firstOrNew(array('slug' => $slug));

        if (! $row->exists) {
            try {
                $row->fill($attributes)->save();

                return $row;
            } catch (QueryException $exception) {
                $winner = Profession::query()->where('slug', $slug)->first();

                if (null === $winner) {
                    throw $exception;
                }

                $row = $winner;
            }
        }

        $row->fill($attributes)->save();

        return $row;
    }

    /**
     * A base record to start from, or a blank one.
     *
     * @return array<string, mixed>
     */
    private function seed(string $slug): array
    {
        $found = $this->base->getBySlug($slug);

        if (! empty($found['found']) && is_array($found['profession'] ?? null)) {
            return $found['profession'];
        }

        return array(
            'id' => 0,
            'slug' => $slug,
            'name' => $slug,
            'description' => '',
            'category' => 'other',
            'role_description' => '',
            'expertise' => array(),
            'warnings' => array(),
            'knowledge_base' => '',
            'default_tools' => array(),
        );
    }
}
