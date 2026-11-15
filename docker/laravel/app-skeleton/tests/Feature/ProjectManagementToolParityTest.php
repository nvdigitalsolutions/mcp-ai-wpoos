<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 090-E step 6 — parity fixtures for the project-management read tools.
 *
 * The corpus at `tests/fixtures/pm/tools.json` records the WordPress tools'
 * responses for a fixed seeded state. This replays the same arguments against
 * the Laravel runtime and compares everything except ids and dates, which
 * differ by construction.
 *
 * The write tools are covered by the next increment; 090-E §8 guardrail 6 says
 * a family lands read tools first.
 */
class ProjectManagementToolParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seedFamily();
    }

    /**
     * Resolve the committed corpus.
     *
     * CI runs `artisan test` from `docker/laravel/app-skeleton`, five levels
     * inside the checkout; the dev container mounts that same directory as
     * `/app` and exposes the repository elsewhere, so the file is overridable
     * via `NVOOS_PM_FIXTURE`.
     */
    private static function corpusFile(): string
    {
        // Data providers run before the application boots, so this cannot use
        // base_path() or env(). CI runs from docker/laravel/app-skeleton, five
        // levels inside the checkout; the dev container mounts that directory
        // as /app and exposes the repository elsewhere, hence the override.
        //
        // A dedicated variable rather than NVOOS_FIXTURES_DIR, which
        // FixtureReplayTest already owns for a different tree.
        $explicit = getenv('NVOOS_PM_FIXTURE');

        if (is_string($explicit) && '' !== $explicit && is_file($explicit)) {
            return $explicit;
        }

        return dirname(__DIR__, 5).'/tests/fixtures/pm/tools.json';
    }

    /**
     * The committed corpus.
     */
    private function corpus(): array
    {
        $file = self::corpusFile();

        $this->assertFileExists($file, 'PM parity corpus not found. Looked for '.$file.'.');

        $decoded = json_decode((string) file_get_contents($file), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Seed the same three projects and three tasks the capture used.
     *
     * `created_at` is patched afterwards so ordering is a real date comparison
     * rather than a tie broken by the database.
     */
    private function seedFamily(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $project = function (string $title, string $body, array $meta, string $date) use ($store): int {
            $id = $store->create(
                new CreateContentCommand(
                    title: $title,
                    type: 'mcp_ai_project',
                    authorId: 1,
                    status: 'publish',
                    content: $body,
                    meta: $meta,
                )
            )->id;

            $this->stamp($id, $date);

            return $id;
        };

        $task = function (string $title, string $body, array $meta, string $date) use ($store): int {
            $id = $store->create(
                new CreateContentCommand(
                    title: $title,
                    type: 'mcp_ai_task',
                    authorId: 1,
                    status: 'publish',
                    content: $body,
                    meta: $meta,
                )
            )->id;

            $this->stamp($id, $date);

            return $id;
        };

        $this->ids['atlas'] = $project(
            'Atlas Migration',
            'Move the estate to the new platform.',
            [
                '_project_status' => 'active',
                '_project_start_date' => '2026-01-05',
                '_project_end_date' => '2026-03-31',
                '_project_assigned_to' => ['1'],
            ],
            '2026-01-03 09:00:00'
        );

        $this->ids['beacon'] = $project(
            'Beacon Audit',
            'Accessibility and performance review.',
            [
                '_project_status' => 'planning',
                '_project_start_date' => '2026-02-01',
                '_project_end_date' => '2026-04-30',
                '_project_assigned_to' => [],
            ],
            '2026-01-02 09:00:00'
        );

        $this->ids['cinder'] = $project(
            'Cinder Rebuild',
            'Rebuild the storefront.',
            [
                '_project_status' => 'completed',
                '_project_start_date' => '2025-10-01',
                '_project_end_date' => '2025-12-20',
                '_project_assigned_to' => [],
            ],
            '2026-01-01 09:00:00'
        );

        $this->ids['plan'] = $task(
            'Draft the migration plan',
            'Write the cutover plan.',
            [
                '_task_project_id' => $this->ids['atlas'],
                '_task_status' => 'in-progress',
                '_task_priority' => 'high',
                '_task_due_date' => '2026-01-20',
                '_task_assigned_to' => 1,
            ],
            '2026-01-06 09:00:00'
        );

        $this->ids['freeze'] = $task(
            'Freeze plugin updates',
            'Announce and freeze.',
            [
                '_task_project_id' => $this->ids['atlas'],
                '_task_status' => 'pending',
                '_task_priority' => 'medium',
                '_task_due_date' => '2026-01-25',
                '_task_assigned_to' => 0,
                '_task_depends_on' => [$this->ids['plan']],
            ],
            '2026-01-05 09:00:00'
        );

        $this->ids['contrast'] = $task(
            'Run the contrast sweep',
            'WCAG AA across the top 20 pages.',
            [
                '_task_project_id' => $this->ids['beacon'],
                '_task_status' => 'completed',
                '_task_priority' => 'low',
                '_task_due_date' => '2026-02-10',
                '_task_assigned_to' => 0,
            ],
            '2026-01-04 09:00:00'
        );
    }

    private function stamp(int $id, string $date): void
    {
        DB::table('nvoos_posts')->where('id', $id)->update([
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    /**
     * Replace ids and dates so the comparison is runtime-neutral.
     */
    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            // User ids differ between runtimes for the same reason record ids
            // do. Checked before the array recursion so `assigned_to` lists are
            // handled as user ids rather than as indexed values.
            if ('assigned_to' === $key) {
                $out[$key] = is_array($item)
                    ? array_map(static fn ($v) => is_numeric($v) ? '<id>' : $v, $item)
                    : (is_numeric($item) ? '<id>' : $item);

                continue;
            }

            if (is_array($item)) {
                $out[$key] = $this->normalise($item);

                continue;
            }

            if (in_array($key, ['id', 'project_id', 'task_id', 'depends_on'], true)) {
                $out[$key] = is_numeric($item) ? '<id>' : $item;

                continue;
            }

            if (is_string($key) && (str_contains($key, 'date') || str_contains($key, '_at')) && '' !== (string) $item) {
                $out[$key] = '<date>';

                continue;
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Map a symbolic id to the local one.
     */
    private function args(array $args): array
    {
        $symbols = [
            '<project:atlas>' => $this->ids['atlas'],
            '<task:plan>' => $this->ids['plan'],
            '<task:freeze>' => $this->ids['freeze'],
        ];

        foreach ($args as $key => $value) {
            if (is_string($value) && isset($symbols[$value])) {
                $args[$key] = $symbols[$value];
            }
        }

        return $args;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function corpusCases(): array
    {
        $file = self::corpusFile();

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        $cases = [];

        foreach ((array) $decoded as $name => $case) {
            $cases[$name] = [$name, $case['tool'], $case['args']];
        }

        return $cases;
    }

    /**
     * Every captured argument set produces the recorded response.
     */
    #[DataProvider('corpusCases')]
    public function test_the_read_tools_match_the_wordpress_corpus(string $case, string $tool, array $args): void
    {
        $corpus = $this->corpus()[ $case ];

        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has($tool), $tool.' is not registered.');

        // The tools authorize on the context's user id; the registry normally
        // supplies it from the authenticated session.
        $result = $registry->get($tool)->execute($this->args($args), ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        $expected = $corpus['expected'];
        unset($expected['success']);

        $this->assertSame(
            $this->normalise($expected),
            $this->normalise($result['data'])
        );
    }
}
