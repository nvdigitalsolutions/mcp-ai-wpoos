<?php

namespace App\Services;

use App\Models\Assistant;
use App\Support\BundleException;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Assistant export / import — the `nvoos-assistant` v1 bundle (Proposal 149 §54).
 *
 * **One writer, one estate.** Until §54 this logic lived only in
 * `AssistantPortabilityController`, which queried `Assistant` with no estate
 * filter at all. The consequences were three separate cross-estate paths:
 *
 *  - `export()` returned **every estate's** assistants, system prompts and
 *    `meta` included, and `ids[]` could name any id;
 *  - `import()` resolved its slug/title conflict unscoped, so `mode=overwrite`
 *    could `fill()->save()` another estate's assistant;
 *  - `Assistant::create()` never set `tenant_id` (the model has no `creating`
 *    hook), so an import in estate B silently wrote into the column default;
 *    and the `is_default` reset ran `update()` across the whole table.
 *
 * All three are fixed by construction rather than by adding `where()` clauses
 * one at a time: every read goes through `estate()`, and every write goes
 * through {@see AssistantWriter}, which already owns estate assignment,
 * per-estate slug uniqueness and per-estate default exclusivity. The panel page
 * and the REST controller both call this class, so there is no second import
 * path — the rule §46 applies to every write surface.
 *
 * **The bundle is the transport, not the model.** `meta` round-trips verbatim
 * (that is what makes a WordPress export importable here and vice versa), but
 * `tenant_id` is never read from a bundle: a bundle is a document an operator
 * pastes in, so it can claim anything. The estate comes from the runtime.
 *
 * **What a bundle never carries.** Round-tripping verbatim is also why the
 * export applies a credential denylist rather than trusting the stored shape: an
 * assistant created by importing a hand-edited bundle can hold a key that
 * WordPress's own export would have stripped. `redact()` removes those keys and
 * the MCP App tokens on the way out, so every surface — panel, REST, tool —
 * produces the same bundle.
 */
final class AssistantPortability
{
    public const FORMAT = 'nvoos-assistant';

    public const FORMAT_VERSION = 1;

    /**
     * Meta keys that never leave the estate in a bundle.
     *
     * The same denylist the WordPress engine applies. It is not theoretical
     * here: `meta` round-trips verbatim — that is what makes a bundle portable
     * — so an assistant created by importing a hand-edited bundle can hold a
     * credential key that WordPress's own export would have removed. Applying
     * the denylist on the way out is what keeps this engine's bundle equal to
     * that one, whichever way the record arrived.
     *
     * @var list<string>
     */
    private const META_DENYLIST = array( '_wp_mcp_ai_credentials' );

    /**
     * Fields inside an MCP App entry that are credentials, not configuration.
     *
     * A `_wp_mcp_ai_mcp_apps` entry keeps its server URL and auth *type* so the
     * connection can be re-established on the other side; the token and OAuth
     * payload cannot cross, because the receiving runtime issues its own.
     *
     * @var list<string>
     */
    private const MCP_APP_SECRET_KEYS = array( 'token', 'oauth_data' );

    public function __construct(private readonly AssistantWriter $writer)
    {
    }

    /**
     * Build a bundle for the current estate.
     *
     * @param list<int>|null $ids Null exports every assistant in the estate;
     *                            a list exports exactly those, and is refused
     *                            outright if any id is not in the estate.
     *
     * @return array{format:string, format_version:int, plugin_version:string, exported_at:string, assistants:list<array<string,mixed>>}
     *
     * @throws BundleException When an id is not in the current estate.
     */
    public function export(?array $ids = null): array
    {
        $query = $this->estate();

        if (is_array($ids) && [] !== $ids) {
            $wanted = array_values(array_unique(array_map('intval', $ids)));

            $found = $this->estate()
                ->whereIn('id', $wanted)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $missing = array_values(array_diff($wanted, $found));

            // Refused, never silently dropped: a bundle that quietly contains
            // fewer assistants than were asked for is how a restore lands
            // incomplete. An id that is absent and an id that belongs to
            // another estate get the same message on purpose — whether the row
            // exists elsewhere is not the caller's business.
            if ([] !== $missing) {
                throw BundleException::unknownAssistants($missing);
            }

            $query->whereIn('id', $wanted);
        }

        $assistants = $query->orderBy('title')->get();

        return array(
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => (string) config('app.version', '1.2.0'),
            'exported_at' => now()->toIso8601String(),
            'assistants' => $assistants
                ->map(fn (Assistant $assistant): array => $this->redact($assistant->toBundleEntry()))
                ->all(),
        );
    }

    /**
     * Remove the keys a bundle must never carry.
     *
     * Two layers, because they answer different questions. The denylist is a
     * statement about whole keys — a credential record is never configuration
     * and has no meaningful empty form. The MCP App redaction is narrower: the
     * connection entry stays, its secret does not, so the receiving runtime can
     * still show *what* was connected while issuing its own token.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function redact(array $entry): array
    {
        $meta = $entry['meta'] ?? null;

        if (! is_array($meta)) {
            return $entry;
        }

        $entry['meta'] = $this->redactMeta($meta);

        return $entry;
    }

    /**
     * The denylist itself, applied to a `meta` map.
     *
     * Shared by both directions on purpose. Export redaction alone would leave
     * the key in the database, where every read path that is not the export
     * engine still sees it; import redaction alone would let a stored row leave
     * by a route nobody remembered. One implementation, both gates.
     *
     * @param array<mixed> $meta
     *
     * @return array<mixed>
     */
    private function redactMeta(array $meta): array
    {
        foreach (self::META_DENYLIST as $key) {
            unset($meta[$key]);
        }

        if (is_array($meta['_wp_mcp_ai_mcp_apps'] ?? null)) {
            $meta['_wp_mcp_ai_mcp_apps'] = $this->redactApps($meta['_wp_mcp_ai_mcp_apps']);
        }

        return $meta;
    }

    /**
     * Strip the credential fields from every MCP App entry.
     *
     * @param array<mixed> $apps
     *
     * @return array<mixed>
     */
    private function redactApps(array $apps): array
    {
        foreach ($apps as $index => $app) {
            if (! is_array($app)) {
                continue;
            }

            foreach (self::MCP_APP_SECRET_KEYS as $secret) {
                unset($app[$secret]);
            }

            $apps[$index] = $app;
        }

        return $apps;
    }

    /**
     * Decode and import a bundle document.
     *
     * Shared by both transports so the "that is not a bundle" wording cannot
     * differ between the console and the panel.
     *
     * @return array{dry_run:bool, imported:int, updated:int, skipped:int, assistants:list<array<string,mixed>>}
     *
     * @throws BundleException
     */
    public function importJson(string $json, string $mode = 'skip', bool $dryRun = false, ?string $statusOverride = null): array
    {
        $bundle = json_decode($json, true);

        if (! is_array($bundle)) {
            throw BundleException::notABundle();
        }

        return $this->import($bundle, $mode, $dryRun, $statusOverride);
    }

    /**
     * Import a decoded bundle into the current estate.
     *
     * The conflict lookup is slug-then-title **within the estate**, which is the
     * same order WordPress's importer uses (`find_existing_assistant()`), so an
     * entry collides here exactly when it would collide there.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array{dry_run:bool, imported:int, updated:int, skipped:int, assistants:list<array<string,mixed>>}
     *
     * @throws BundleException
     */
    public function import(array $bundle, string $mode = 'skip', bool $dryRun = false, ?string $statusOverride = null): array
    {
        $this->assertBundle($bundle);

        if (! in_array($mode, array('skip', 'overwrite', 'duplicate'), true)) {
            $mode = 'skip';
        }

        $report = array(
            'dry_run' => $dryRun,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'assistants' => array(),
        );

        /** @var array<int, mixed> $entries */
        $entries = $bundle['assistants'];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['title'])) {
                continue;
            }

            $title = (string) $entry['title'];
            $declared = trim((string) ($entry['slug'] ?? ''));

            // An entry with no slug is identified by its title. The old
            // `Str::slug($entry['slug'] ?? $title)` fell through to the literal
            // `assistant` whenever the key existed but was empty, which made
            // every slug-less entry in a bundle collide with the last one.
            $slug = Str::slug('' !== $declared ? $declared : $title) ?: 'assistant';
            $existing = $this->find($slug, $title);

            if (null !== $existing && 'skip' === $mode) {
                ++$report['skipped'];
                $report['assistants'][] = array('title' => $title, 'id' => $existing->id, 'action' => 'skipped');

                continue;
            }

            if ('duplicate' === $mode) {
                // Force a create. The writer suffixes the slug within the
                // estate, exactly as WordPress's `wp_insert_post()` does — the
                // old `-copy` suffix here was neither scoped nor parity.
                $existing = null;
            }

            $data = $this->entryToData($entry, $slug, $statusOverride);

            if (null !== $existing && '' === $declared) {
                // Slug-less bundle, existing row: keep the stored slug. Re-deriving
                // it from the title would silently re-slug an assistant that had
                // been suffixed at creation, breaking every saved link — the same
                // rule `AssistantWriter::update()` applies to a payload without a
                // slug.
                unset($data['slug']);
            }

            if ($dryRun) {
                $action = null !== $existing ? 'updated' : 'imported';
                null !== $existing ? ++$report['updated'] : ++$report['imported'];
                $report['assistants'][] = array(
                    'title' => $title,
                    'id' => $existing?->id,
                    'action' => $action,
                    'dry_run' => true,
                );

                continue;
            }

            if (null !== $existing) {
                $assistant = $this->writer->update($existing, $data);
                ++$report['updated'];
                $action = 'updated';
            } else {
                $assistant = $this->writer->create($data);
                ++$report['imported'];
                $action = 'imported';
            }

            $report['assistants'][] = array(
                'title' => $title,
                'id' => $assistant->id,
                'action' => $action,
            );
        }

        return $report;
    }

    /**
     * Assistants visible to the current estate.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Assistant>
     */
    private function estate()
    {
        return Assistant::query()->where('tenant_id', TenantContext::current());
    }

    /**
     * The estate's assistant matching this entry, or null.
     *
     * Slug first, then exact title — the WordPress order. Scoping the lookup is
     * the whole point: an unscoped one resolves the *other* estate's row and
     * the update below writes to it.
     */
    private function find(string $slug, string $title): ?Assistant
    {
        return $this->estate()->where('slug', $slug)->first()
            ?? $this->estate()->where('title', $title)->first();
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @throws BundleException
     */
    private function assertBundle(array $bundle): void
    {
        if (($bundle['format'] ?? '') !== self::FORMAT) {
            throw BundleException::notABundle();
        }

        $version = (int) ($bundle['format_version'] ?? 0);

        if (self::FORMAT_VERSION !== $version) {
            throw BundleException::unsupportedVersion($version);
        }

        $entries = $bundle['assistants'] ?? null;

        if (! is_array($entries) || [] === $entries) {
            throw BundleException::emptyBundle();
        }
    }

    /**
     * Map a bundle entry onto the assistant columns and `meta`.
     *
     * `meta` is otherwise carried through verbatim: it holds the WordPress
     * `_wp_mcp_ai_*` keys an export from either runtime writes, and rewriting it
     * here would break the round trip. The promoted keys are also left inside
     * `meta`, which is what the previous controller did and what an export from
     * this runtime produces, so a re-import is stable.
     *
     * **The credential denylist is the one exception**, and it is applied before
     * the promoted keys are read because it must also run for a bundle an
     * operator pasted in by hand — the case WordPress's own export redaction
     * never saw. Removing two named keys does not damage the round trip; keeping
     * them would store a live credential because a JSON file asked it to.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function entryToData(array $entry, string $slug, ?string $statusOverride): array
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : array();
        $meta = $this->redactMeta($meta);
        $status = $statusOverride ?? (string) ($entry['status'] ?? 'draft');

        if (! in_array($status, array('draft', 'publish', 'private'), true)) {
            $status = 'draft';
        }

        return array(
            'title' => (string) $entry['title'],
            'slug' => $slug,
            'status' => $status,
            'description' => $entry['excerpt'] ?? null,
            'system_prompt' => $entry['content'] ?? null,
            'provider' => $meta['provider'] ?? null,
            'model' => $meta['model'] ?? null,
            'temperature' => isset($meta['temperature']) ? (float) $meta['temperature'] : null,
            'max_tokens' => isset($meta['max_tokens']) ? (int) $meta['max_tokens'] : null,
            'tools' => is_array($meta['tools'] ?? null) ? array_values($meta['tools']) : array(),
            'meta' => $meta,
            'is_default' => (bool) ($meta['is_default'] ?? false),
        );
    }
}
