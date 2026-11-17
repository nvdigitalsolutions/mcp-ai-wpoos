<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Reports;
use App\Models\AdminAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Reports\ComplianceReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §34 — compliance reports.
 *
 * Two properties matter beyond the page rendering:
 *
 *  - the serialisers are pure, so their output is pinned directly rather than
 *    through a download. A sectioned CSV with a comma in a name is the case that
 *    breaks a naive implementation.
 *  - an export of a security register leaves a trace. The audit row records the
 *    format and the counts, never the content.
 */
class AdminComplianceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        Carbon::setTestNow('2026-11-17 09:30:00');

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_renders_the_report(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/reports')
            ->assertOk()
            ->assertSee('Compliance reports')
            ->assertSee('Report metadata')
            ->assertSee('Download CSV')
            ->assertSee('Download JSON')
            ->assertSee('deliberately not offered');
    }

    public function test_the_report_has_the_two_sections_the_runtime_can_evidence(): void
    {
        $report = $this->buildReport();

        $this->assertSame('assets, audit', (string) $report['metadata']['sections']);
        $this->assertArrayHasKey('total_assets', $report['summary']);
        $this->assertNotEmpty($report['assets']);

        // Sections the Pro compliance database would supply are absent, not empty.
        $this->assertArrayNotHasKey('controls', $report);
        $this->assertArrayNotHasKey('risks', $report);
        $this->assertArrayNotHasKey('frameworks', $report);
    }

    public function test_the_csv_is_sectioned_like_the_wordpress_export(): void
    {
        $csv = ComplianceReport::toCsv($this->syntheticReport());

        $this->assertStringContainsString("Metadata\n", $csv);
        $this->assertStringContainsString("Summary\n", $csv);
        $this->assertStringContainsString("Assets\n", $csv);
        $this->assertStringContainsString('ID,Name,Type,Classification,Owner,Location,Detail', $csv);
        // `fputcsv()` quotes any field containing a space, so a multi-word
        // heading is enclosed. Assert the bytes that are actually written.
        $this->assertStringContainsString('"Audit trail (newest 1000)"', $csv);
        $this->assertStringContainsString('asset_1', $csv);

        // Section order is the reading order an auditor expects.
        $this->assertLessThan(strpos($csv, 'Assets'), strpos($csv, 'Metadata'));
        $this->assertLessThan(strpos($csv, 'Audit trail'), strpos($csv, 'Assets'));
    }

    /**
     * A comma or a quote in a value must not shift the columns.
     */
    public function test_the_csv_quotes_values_that_contain_separators(): void
    {
        $csv = ComplianceReport::toCsv($this->syntheticReport());

        $this->assertStringContainsString('"Assets, settings and keys"', $csv);
        $this->assertStringContainsString('"Said ""hello"""', $csv);
    }

    public function test_the_json_round_trips(): void
    {
        $decoded = json_decode(ComplianceReport::toJson($this->syntheticReport()), true);

        $this->assertIsArray($decoded);
        $this->assertSame('full', $decoded['metadata']['scope']);
        $this->assertSame(1, $decoded['summary']['total_assets']);
        $this->assertSame('restricted', $decoded['assets'][0]['classification']);
    }

    public function test_the_filename_sorts_by_time_and_carries_the_format(): void
    {
        $stamp = Carbon::parse('2026-11-17 09:30:00', 'UTC')->getTimestamp();

        $this->assertSame('compliance-report-2026-11-17-093000.csv', ComplianceReport::filename('csv', $stamp));
        $this->assertSame('compliance-report-2026-11-17-093000.json', ComplianceReport::filename('json', $stamp));
    }

    public function test_the_content_type_is_explicit_and_falls_back_to_text(): void
    {
        $this->assertStringStartsWith('text/csv', ComplianceReport::contentType('csv'));
        $this->assertStringStartsWith('application/json', ComplianceReport::contentType('json'));
        $this->assertStringStartsWith('text/plain', ComplianceReport::contentType('pdf'));
    }

    public function test_the_csv_download_is_named_and_logged(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Reports::class)
            ->call('downloadCsv')
            ->assertFileDownloaded('compliance-report-2026-11-17-093000.csv');

        $row = AdminAudit::query()
            ->where('resource', 'reports')
            ->where('action', 'generate')
            ->first();

        $this->assertNotNull($row, 'An export must leave an audit row.');
        $this->assertSame('compliance-report-2026-11-17-093000.csv', (string) $row->record_id);
        $this->assertSame('csv', (string) $row->after['format']);
        $this->assertArrayHasKey('assets', $row->after);
        $this->assertArrayHasKey('audit_rows', $row->after);

        // The row records counts, never the report itself.
        $this->assertArrayNotHasKey('content', $row->after);
    }

    public function test_the_json_download_is_named_and_logged(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Reports::class)
            ->call('downloadJson')
            ->assertFileDownloaded('compliance-report-2026-11-17-093000.json');

        $row = AdminAudit::query()
            ->where('resource', 'reports')
            ->where('action', 'generate')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('json', (string) $row->after['format']);
    }

    /**
     * An empty register still produces a parseable file with every heading.
     */
    public function test_an_empty_report_still_produces_its_sections(): void
    {
        $csv = ComplianceReport::toCsv(array(
            'metadata' => array('scope' => 'full'),
            'summary' => array('total_assets' => 0, 'highest' => '', 'by_classification' => array(), 'by_type' => array()),
            'assets' => array(),
            'audit' => array(),
        ));

        $this->assertStringContainsString("Metadata\n", $csv);
        $this->assertStringContainsString("Assets\n", $csv);
        $this->assertStringContainsString('ID,Name,Type,Classification,Owner,Location,Detail', $csv);
    }

    public function test_a_customer_admin_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/reports')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(): array
    {
        return ComplianceReport::build(
            $this->app->make(SettingsStoreInterface::class),
            'Test Operator'
        );
    }

    /**
     * A report with values that break naive CSV writers.
     *
     * @return array<string, mixed>
     */
    private function syntheticReport(): array
    {
        return array(
            'metadata' => array(
                'report_date' => '2026-11-17T09:30:00+00:00',
                'generated_by' => 'Test Operator',
                'scope' => 'full',
            ),
            'summary' => array(
                'total_assets' => 1,
                'highest' => 'restricted',
                'by_classification' => array('restricted' => 1),
                'by_type' => array('configuration' => 1),
            ),
            'assets' => array(
                array(
                    'id' => 'asset_1',
                    'name' => 'Assets, settings and keys',
                    'type' => 'configuration',
                    'classification' => 'restricted',
                    'owner' => 'Said "hello"',
                    'location' => 'nvoos_settings',
                    'description' => 'Line one',
                ),
            ),
            'audit' => array(
                array(
                    'id' => 1,
                    'at' => '2026-11-17T09:00:00+00:00',
                    'estate' => '',
                    'panel' => 'ops',
                    'kind' => 'record',
                    'actor' => 'Test Operator',
                    'label' => 'settings update',
                    'status' => 200,
                ),
            ),
        );
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
