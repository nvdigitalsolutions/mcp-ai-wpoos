<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsCoverage;
use App\Support\SettingsSchema;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Proposal 149 §183 — the coverage map may not quietly stop being true.
 *
 * The page is generated, which is what makes it worth reading, and also what
 * makes it able to rot: a regenerated catalogue with one new field leaves the
 * artefact missing a row, and a reader added for a key leaves the artefact
 * claiming it is inert. Neither is visible on screen, because the page renders
 * whatever it was given.
 *
 * Three properties are pinned:
 *
 *  1. **Completeness.** Every catalogue key appears exactly once, and the
 *     artefact invents no key. A schema change without a regeneration fails the
 *     build, which is the only moment anyone is looking at both files.
 *  2. **Every claim of a reader can name one.** An `enforced` row asserts that
 *     runtime code reads the key; the test looks for the key in the source that
 *     executes here. A bucket is a promise, so the promise is checked.
 *  3. **The dangerous bucket stays visible and stays writable.** `unenforced`
 *     exists because those controls render and save. If one stops being
 *     writable, the row is stale and the page overstates the risk; if the bucket
 *     empties, the page's leading callout disappears and the risk went with it.
 *
 * This is a coarse source scan on purpose. It cannot prove a reader does
 * something sensible with the value — only that the key is named where the
 * runtime can name it. That is the same limit `UnimplementedSettingsTest`
 * documents, and pretending otherwise would be the "negative result from the
 * wrong search" that Proposal 156 was written to correct.
 */
class AdminSettingsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_artefact_classifies_every_catalogue_key_exactly_once(): void
    {
        $catalogue = array_column(SettingsSchema::fields(), 'key');
        $classified = array_column(SettingsCoverage::rows(), 'key');

        sort($catalogue);
        sort($classified);

        $this->assertSame(
            $catalogue,
            $classified,
            'The coverage map and the settings catalogue disagree. Regenerate '
            .'resources/settings/coverage.json with the settings sweep — the map is '
            .'derived from the catalogue, so a difference in either direction means one '
            .'of them was edited by hand.'
        );
    }

    public function test_every_bucket_present_in_the_data_has_a_reason(): void
    {
        foreach (SettingsCoverage::presentBuckets() as $bucket) {
            $this->assertNotSame(
                '',
                SettingsCoverage::reason($bucket),
                sprintf('Bucket "%s" has keys but no stated reason; the page would render an empty explanation.', $bucket)
            );
        }
    }

    public function test_every_claim_of_a_reader_can_name_one(): void
    {
        $haystack = $this->runtimeSource();
        $offenders = array();

        foreach (SettingsCoverage::byBucket('enforced') as $row) {
            $key = $row['key'];

            // A key can be read under its storage name rather than its surface
            // name: the admin writes `google_api_key`, the adapter reads
            // `gemini_api_key`. Either name satisfies the claim, because either
            // one means a reader exists.
            $names = array_unique(array($key, (string) ($row['storage'] ?? $key)));
            $found = false;

            foreach ($names as $name) {
                if (str_contains($haystack, "'".$name."'")) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $offenders[] = $key;
            }
        }

        $this->assertSame(
            array(),
            $offenders,
            'These keys are classified "enforced", but the runtime source never names '
            .'them. Either the reader moved to a form the sweep cannot see (make it '
            .'visible, or reclassify), or the key is not read and the bucket overstates '
            .'coverage.'
        );
    }

    public function test_a_writable_security_control_is_never_left_unenforced(): void
    {
        // The admin-writable unenforced bucket must be empty: that is the goal of
        // §183–§187, and asserting it is the only way the milestone stays true.
        $this->assertSame(
            array(),
            SettingsCoverage::byBucket('unenforced'),
            'An admin-writable security control is enforced nowhere again — wire it or declare it.'
        );

        // The operator bucket is a closed list, not an empty one. The root-key pair
        // stays unenforced because §66 renders operator infrastructure and a secrets
        // test pins that the key is writable from /ops and masked (§187.3). Any
        // other key appearing here is a regression.
        $this->assertSame(
            array('enable_root_security_key', 'root_security_key'),
            array_column(SettingsCoverage::byBucket('unenforced-operator'), 'key'),
            'The operator-unenforced bucket gained a key — wire it, declare it, or record the decision.'
        );
    }

    /**
     * §183's finding, kept closed.
     *
     * Four admin-writable security controls were enforced nowhere when the sweep
     * shipped. Two were wired to the guard and two were declared inert. If one
     * drifts back to unenforced — a reader removed, a declaration dropped — this
     * fails before the page can quietly re-list it.
     */
    public function test_the_four_writable_security_controls_stay_accounted_for(): void
    {
        $buckets = array();

        foreach (SettingsCoverage::rows() as $row) {
            $buckets[ $row['key'] ] = $row['bucket'];
        }

        $watched = array(
            'allow_guest_access',
            'enable_2fa_requirement',
            'enable_prompt_injection_detector',
            'enable_pii_filter',
        );

        foreach ($watched as $key) {
            $this->assertContains(
                $buckets[ $key ] ?? '',
                array('enforced', 'inert'),
                sprintf(
                    '"%s" is no longer enforced or declared inert (§183.3). Either its '
                    .'reader was removed or its declaration was dropped — restore one '
                    .'before the admin re-exposed a control that does nothing.',
                    $key
                )
            );
        }
    }

    public function test_the_page_reports_the_map(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings-coverage')
            ->assertOk()
            ->assertSee('Settings coverage')
            ->assertSee('change behaviour here')
            // The two buckets an operator has to understand before the rest of
            // the page makes sense: the operator-scope controls that enforce
            // nothing, and the ported WordPress surface, which is not a defect.
            ->assertSee('Operator control, enforced nowhere')
            ->assertSee('WordPress settings');
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    public function test_the_page_counts_are_internally_consistent(): void
    {
        $counts = SettingsCoverage::counts();

        $this->assertSame(
            SettingsCoverage::total(),
            array_sum($counts),
            'The per-bucket counts do not add up to the row total, so the summary cards '
            .'disagree with the tables below them.'
        );

        $this->assertGreaterThan(
            0,
            $counts['enforced'] ?? 0,
            'Nothing is classified as enforced; a coverage report with no coverage is a bug.'
        );
    }

    /**
     * Every PHP file this runtime executes or renders from, concatenated.
     *
     * `lib/core` and `lib/laravel-adapter` are included because the tool and
     * provider layers live there, and a key read by a core tool is read by this
     * runtime. The settings subsystem itself is excluded: it names keys as
     * declarations, not as readers, and including it would make every claim
     * trivially true.
     */
    private function runtimeSource(): string
    {
        $roots = array(
            app_path(),
            config_path(),
            base_path('routes'),
            database_path(),
            resource_path('views'),
        );

        // `lib/` sits beside the app, not inside it. In the test container the
        // repo is mounted at /workspace/oos while the app is /app, so the repo
        // root is resolved by candidate rather than by relative path — a wrong
        // guess here would silently drop the core and adapter trees and make
        // every "enforced" claim look unsupported.
        foreach ($this->repoRootCandidates() as $candidate) {
            foreach (array('lib/core/src', 'lib/laravel-adapter/src') as $lib) {
                $path = $candidate.'/'.$lib;

                if (is_dir($path)) {
                    $roots[] = $path;
                }
            }

            if (count($roots) > 5) {
                break;
            }
        }

        $skip = array(
            realpath(app_path('Support/Settings/SettingsCoverage.php')) ?: '',
            realpath(app_path('Support/Settings/UnimplementedSettings.php')) ?: '',
            realpath(app_path('Support/Settings/InapplicableSettings.php')) ?: '',
            realpath(app_path('Support/SettingsSchema.php')) ?: '',
            realpath(app_path('Support/Settings/SettingsCatalogue.php')) ?: '',
        );

        $buffer = '';

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $path = $file->getPathname();

                if (in_array(realpath($path), $skip, true)) {
                    continue;
                }

                $buffer .= (string) file_get_contents($path)."\n";
            }
        }

        return $buffer;
    }

    /**
     * Where the repository root might be, nearest guess first.
     *
     * @return list<string>
     */
    private function repoRootCandidates(): array
    {
        $candidates = array();
        $env = getenv('NVOOS_REPO_ROOT');

        if (is_string($env) && '' !== $env) {
            $candidates[] = rtrim($env, '/\\');
        }

        $candidates[] = '/workspace/oos';
        $candidates[] = dirname(base_path(), 2);
        $candidates[] = dirname(base_path(), 3);

        return array_values(array_unique($candidates));
    }
}
