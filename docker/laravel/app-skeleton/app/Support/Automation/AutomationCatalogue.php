<?php

namespace App\Support\Automation;

use App\Support\TenantContext;
use App\Support\WorkflowRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;

/**
 * The read-only catalogue of automation definitions — Proposal 149 §30.
 *
 * Two record sets, one page: workflow definitions (content records) and
 * triggers (`nvoos_triggers`). They are shown together because the question
 * "what automations exist here" needs both — a workflow with no trigger runs
 * only when someone fires it, and a trigger names the workflow it runs.
 *
 * **The console owns both, and this reader does not write.** `automation-
 * definitions` declares `writer: console`; rebuilding CRUD in Filament would
 * maintain one page twice (§13.1). What the panel lacked was any view at all.
 *
 * ## Estate scope
 *
 * Both sources are estate-scoped through `TenantContext`, which
 * `App\Http\Middleware\SetTenantContext` pins to Filament's tenant on every
 * `/admin` request (`->tenantMiddleware([...], isPersistent: true)`). That is
 * why this reader takes no estate parameter: there is no API consumer to
 * disagree with, and a parameter would invite the caller to pick one. A
 * Livewire-only test must set the context itself, because the panel middleware
 * does not run there.
 *
 * ## What is deliberately not exposed
 *
 * A trigger's `webhook_url` is a bearer credential in practice — Slack and most
 * webhook providers embed the secret in the path. The row carries the **host**
 * and a `has_webhook` flag, never the URL, so "which endpoint does this call"
 * is answerable without turning the page into a credential dump. `config` is
 * likewise reduced to its `schedule` value rather than echoed whole.
 */
final class AutomationCatalogue
{
    /** Cap on workflow rows read in one render. */
    public const WORKFLOW_LIMIT = 100;

    /**
     * Workflow definitions, newest edit first.
     *
     * @return list<array<string, mixed>>
     */
    public static function workflows(ContentStoreInterface $content): array
    {
        $collection = $content->query(new ContentQuery(
            types: array(WorkflowRecords::TYPE),
            statuses: array('publish'),
            orderBy: 'modified',
            order: 'DESC',
            perPage: self::WORKFLOW_LIMIT,
        ));

        $rows = array();

        foreach ($collection->items as $item) {
            $summary = WorkflowRecords::summarise($item);
            $graph = WorkflowRecords::readGraph($item);

            $rows[] = array(
                'id' => (int) $summary['id'],
                'title' => (string) $summary['title'],
                'version' => (string) $summary['version'],
                'tags' => $summary['tags'],
                'modified' => (string) $summary['modified'],
                'nodes' => count($graph['nodes']),
                'edges' => count($graph['edges']),
            );
        }

        return $rows;
    }

    /**
     * Triggers in the current estate, by name.
     *
     * @return list<array<string, mixed>>
     */
    public static function triggers(): array
    {
        if (! Schema::hasTable('nvoos_triggers')) {
            return array();
        }

        $rows = DB::table('nvoos_triggers')
            ->where('tenant_id', TenantContext::current())
            ->orderBy('name')
            ->get();

        return array_values($rows->map(
            static fn (object $row): array => self::triggerRow($row)
        )->all());
    }

    /**
     * Both sets, so the page and its headers agree about what was read.
     *
     * @return array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>}
     */
    public static function rows(ContentStoreInterface $content): array
    {
        return array(
            'workflows' => self::workflows($content),
            'triggers' => self::triggers(),
        );
    }

    /**
     * Filter both sets for one search term.
     *
     * Server-side, as on the other catalogue pages: the header must report what
     * the runtime can serve, not what a client chose to keep.
     *
     * @param array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>} $rows
     * @return array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>}
     */
    public static function filter(array $rows, string $search = ''): array
    {
        $search = strtolower(trim($search));

        if ('' === $search) {
            return $rows;
        }

        return array(
            'workflows' => array_values(array_filter(
                $rows['workflows'],
                static function (array $row) use ($search): bool {
                    $haystack = strtolower(implode(' ', array(
                        (string) $row['title'],
                        (string) $row['version'],
                        implode(' ', (array) $row['tags']),
                    )));

                    return str_contains($haystack, $search);
                }
            )),
            'triggers' => array_values(array_filter(
                $rows['triggers'],
                static function (array $row) use ($search): bool {
                    $haystack = strtolower(implode(' ', array(
                        (string) $row['name'],
                        (string) $row['type'],
                        (string) $row['schedule'],
                    )));

                    return str_contains($haystack, $search);
                }
            )),
        );
    }

    /**
     * Counts a header can show without the view walking the rows.
     *
     * @param array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>} $rows
     * @return array{workflows: int, triggers: int, enabled: int, disabled: int}
     */
    public static function totals(array $rows): array
    {
        $enabled = 0;
        $disabled = 0;

        foreach ($rows['triggers'] as $row) {
            if ($row['enabled']) {
                ++$enabled;
            } else {
                ++$disabled;
            }
        }

        return array(
            'workflows' => count($rows['workflows']),
            'triggers' => count($rows['triggers']),
            'enabled' => $enabled,
            'disabled' => $disabled,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function triggerRow(object $row): array
    {
        $config = json_decode((string) ($row->config ?? ''), true);
        $schedule = is_array($config) && is_string($config['schedule'] ?? null)
            ? (string) $config['schedule']
            : '';

        $webhook = (string) ($row->webhook_url ?? '');
        $host = '' === $webhook ? '' : (string) (parse_url($webhook, PHP_URL_HOST) ?: '');

        $lastFired = '' === (string) ($row->last_fired_at ?? '')
            ? 0
            : (int) strtotime((string) $row->last_fired_at);

        return array(
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'enabled' => (bool) $row->enabled,
            'workflow_id' => (int) ($row->workflow_id ?? 0),
            'schedule' => $schedule,
            // Host only: most webhook providers put the secret in the path.
            'webhook_host' => $host,
            'has_webhook' => '' !== $webhook,
            'last_fired_at' => $lastFired,
        );
    }
}
