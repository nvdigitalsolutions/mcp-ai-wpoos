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
 * 090-E step 6 — parity fixture for the project-management write tools.
 *
 * The corpus at `tests/fixtures/pm/writes.json` records the WordPress tools'
 * responses for a fixed seeded state. This replays the same arguments against
 * the Laravel runtime and compares everything except ids and dates, which
 * differ by construction.
 *
 * Unlike the read tools, the write tools return a **superset** of the
 * WordPress shape: `update_project` and `update_task` echo the stored row where
 * WordPress returns only the id, and both row assemblers always carry
 * `created_at` *and* `updated_at` where WordPress carries whichever one the
 * branch happened to return. The comparison is therefore "every recorded field
 * matches, and the only extra keys are the ones listed here" — see
 * `assertCovers()`.
 */
class ProjectManagementWriteParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Keys the port may add beyond the WordPress response.
     *
     * Deliberately tight: a new key fails the test until it is added here, so
     * the divergence is a decision rather than an accident.
     */
    private const EXTRA_KEYS = ['project', 'task', 'created_at', 'updated_at'];

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
     * A dedicated variable rather than `NVOOS_PM_FIXTURE`, which
     * `ProjectManagementToolParityTest` owns for the read corpus, so both
     * suites can be pointed at their fixtures in the same invocation.
     */
    private static function corpusFile(): string
    {
        // Data providers run before the application boots, so this cannot use
        // base_path() or env().
        $explicit = getenv('NVOOS_PM_WRITES_FIXTURE');

        if (is_string($explicit) && '' !== $explicit && is_file($explicit)) {
            return $explicit;
        }

        return dirname(__DIR__, 5).'/tests/fixtures/pm/writes.json';
    }

    /**
     * The committed corpus.
     */
    private function corpus(): array
    {
        $file = self::corpusFile();

        $this->assertFileExists($file, 'PM write corpus not found. Looked for '.$file.'.');

        $decoded = json_decode((string) file_get_contents($file), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Seed the same three projects and two tasks the capture used.
     *
     * `created_at` is patched afterwards so ordering is a real date comparison
     * rather than a tie broken by the database.
     */
    private function seedFamily(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $make = function (string $type, string $title, array $meta, string $date) use ($store): int {
            $id = $store->create(
                new CreateContentCommand(
                    title: $title,
                    type: $type,
                    authorId: 1,
                    status: 'publish',
                    content: 'Seeded for the write corpus.',
                    meta: $meta,
                )
            )->id;

            $this->stamp($id, $date);

            return $id;
        };

        $this->ids['atlas'] = $make(
            'mcp_ai_project',
            'Atlas Migration',
            [
                '_project_status' => 'active',
                '_project_start_date' => '2026-01-05',
                '_project_end_date' => '2026-03-31',
            ],
            '2026-01-03 09:00:00'
        );

        $this->ids['beacon'] = $make(
            'mcp_ai_project',
            'Beacon Audit',
            [
                '_project_status' => 'planning',
                '_project_start_date' => '2026-02-01',
                '_project_end_date' => '2026-04-30',
            ],
            '2026-01-02 09:00:00'
        );

        $this->ids['cinder'] = $make(
            'mcp_ai_project',
            'Cinder Rebuild',
            [
                '_project_status' => 'completed',
                '_project_start_date' => '2025-10-01',
                '_project_end_date' => '2025-12-20',
            ],
            '2026-01-01 09:00:00'
        );

        $this->ids['plan'] = $make(
            'mcp_ai_task',
            'Draft the migration plan',
            [
                '_task_project_id' => $this->ids['atlas'],
                '_task_status' => 'in-progress',
                '_task_priority' => 'high',
                '_task_due_date' => '2026-01-20',
            ],
            '2026-01-06 09:00:00'
        );

        $this->ids['contrast'] = $make(
            'mcp_ai_task',
            'Run the contrast sweep',
            [
                '_task_project_id' => $this->ids['beacon'],
                '_task_status' => 'pending',
                '_task_priority' => 'low',
                '_task_due_date' => '2026-02-10',
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
     *
     * `updated` normalises to `<date>` on an upsert because the key contains
     * `date`; `false` casts to an empty string, falls through, and stays a
     * boolean. The corpus records both, so this quirk is load-bearing.
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

            if (in_array($key, ['id', 'project_id', 'task_id'], true)) {
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
     *
     * Arguments are resolved too, not just scalars: `assigned_to` carries a
     * list, and a list is what the create tools validate.
     */
    private function args(array $args): array
    {
        $symbols = [
            '<project:atlas>' => $this->ids['atlas'],
            '<project:beacon>' => $this->ids['beacon'],
            '<project:cinder>' => $this->ids['cinder'],
            '<task:plan>' => $this->ids['plan'],
            '<task:contrast>' => $this->ids['contrast'],
            '<user>' => 1,
        ];

        foreach ($args as $key => $value) {
            if (is_string($value) && isset($symbols[$value])) {
                $args[$key] = $symbols[$value];

                continue;
            }

            if (is_array($value)) {
                $args[$key] = array_map(
                    static fn ($v) => is_string($v) && isset($symbols[$v]) ? $symbols[$v] : $v,
                    $value
                );
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
     * Every recorded field is present and equal; extras are allowlisted.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertCovers(array $expected, array $actual, string $case, string $path = ''): void
    {
        foreach ($expected as $key => $value) {
            $at = '' === $path ? (string) $key : $path.'.'.$key;

            $this->assertArrayHasKey($key, $actual, $case.': missing '.$at);

            // A list (today only `assigned_to`) is compared whole rather than
            // walked by index, so a reordering or a length change still fails.
            if (is_array($value) && array_is_list($value)) {
                $this->assertSame($value, $actual[$key], $case.': '.$at.' differs');

                continue;
            }

            if (is_array($value)) {
                $this->assertIsArray($actual[$key], $case.': '.$at.' is not an object');
                $this->assertCovers($value, $actual[$key], $case, $at);

                continue;
            }

            $this->assertSame($value, $actual[$key], $case.': '.$at.' differs');
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $extra) {
            $this->assertContains(
                $extra,
                self::EXTRA_KEYS,
                $case.': unexpected key '.$extra.' at '.('' === $path ? '<root>' : $path)
            );
        }
    }

    /**
     * Every captured argument set produces the recorded response.
     */
    #[DataProvider('corpusCases')]
    public function test_the_write_tools_match_the_wordpress_corpus(string $case, string $tool, array $args): void
    {
        $corpus = $this->corpus()[$case];

        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has($tool), $tool.' is not registered.');

        // The tools authorize on the context's user id; the registry normally
        // supplies it from the authenticated session.
        $result = $registry->get($tool)->execute($this->args($args), ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $case.': '.($result['message'] ?? 'failed'));

        // The port splits the payload into `message` + `data`; the WordPress
        // tools return one flat array. Reassemble so one comparison covers both.
        $actual = ['success' => $result['success'], 'message' => $result['message']];

        if (is_array($result['data'] ?? null)) {
            $actual += $result['data'];
        }

        $this->assertCovers(
            $this->normalise($corpus['expected']),
            $this->normalise($actual),
            $case
        );
    }
}
