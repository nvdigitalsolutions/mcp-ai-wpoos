<?php

/**
 * The report path: R-01…R-14, CSV/PDF export, and drafts.
 *
 * The builder's output is pinned by its user-visible lines rather than by its
 * internals: a report is a set of headings and sentences, and those are the
 * contract a caller reads. The fixtures are the shared {@see FnbFixture}, so the
 * figures agree with the metric engine's own suite by construction.
 *
 * The export and draft tests use fakes that record what they were handed, and a
 * real {@see JsonPaperStore} in a temporary directory — the store is cheap
 * enough to exercise for real, and a fake would hide its id and title rules.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use Nvoos\Core\Tool\Fnb\FnbDraftWriter;
use Nvoos\Core\Tool\Fnb\FnbExportReportTool;
use Nvoos\Core\Tool\Fnb\FnbGenerateReportTool;
use Nvoos\Core\Tool\Fnb\FnbListDraftsTool;
use Nvoos\Core\Tool\Fnb\FnbMetrics;
use Nvoos\Core\Tool\Fnb\FnbReportBuilder;
use Nvoos\Core\Tool\Fnb\FnbReportExporter;
use Nvoos\Core\Tool\Fnb\FnbSaveDraftTool;
use Nvoos\Core\Tool\Fnb\FnbSettings;
use Nvoos\Core\Tool\Fnb\FnbTableSource;
use PHPUnit\Framework\TestCase;

final class FnbReportToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $tempDirs = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = array();
    }

    // ─── The report library ────────────────────────────────────────────────

    public function test_the_library_holds_fourteen_reports(): void
    {
        $this->assertCount(14, FnbReportBuilder::REPORTS);
        $this->assertSame('Daily trading snapshot', FnbReportBuilder::REPORTS['R-01']['title']);
        $this->assertSame('Monthly sales report', FnbReportBuilder::REPORTS['R-14']['title']);
    }

    public function test_an_unknown_report_is_a_typed_error(): void
    {
        $result = $this->builder()->build('R-99');

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('unknown_report', $result['error']['code']);
    }

    public function test_the_draft_header_names_the_period_and_approver(): void
    {
        $monthly = $this->builder()->build('R-05', array('month' => '2026-09'));
        $daily   = $this->builder()->build('R-01', array('date' => '2026-09-01'));

        $this->assertSame(
            '[R-05] Waste and variance report — 2026-09 — Draft — for Head chef',
            $monthly['draft']['doc_title']
        );
        $this->assertSame(
            '[R-01] Daily trading snapshot — 2026-09-01 — Draft — for Restaurant manager',
            $daily['draft']['doc_title']
        );
        $this->assertSame('Draft', $monthly['draft']['status']);
        $this->assertSame('Head chef', $monthly['draft']['needs_approval']);
    }

    // ─── The reports ───────────────────────────────────────────────────────

    public function test_r_01_reads_one_day_of_covers_revenue_and_waste(): void
    {
        $report   = $this->builder()->build('R-01', array('date' => '2026-09-01'));
        $sections = $report['sections'];

        $this->assertSame('Covers: 40 guests', $sections[0]['lines'][0]);
        $this->assertSame('Revenue: $ 230.00 (excl. service charge)', $sections[1]['lines'][0]);
        $this->assertSame(array('IT-1: $ 200.00', 'IT-2: $ 30.00'), $sections[2]['lines']);
        // Fewer than five items, so top and bottom overlap — as in WordPress.
        $this->assertSame($sections[2]['lines'], $sections[3]['lines']);
        $this->assertSame('Waste value: $ 0.00', $sections[4]['lines'][0]);
    }

    public function test_r_01_defaults_to_the_configured_as_of_date(): void
    {
        // The fixture's as-of date is 2026-09-30, which has no sales rows.
        $report = $this->builder()->build('R-01');

        $this->assertSame('Covers: 0 guests', $report['sections'][0]['lines'][0]);
        $this->assertSame(array('No sales lines.'), $report['sections'][2]['lines']);
    }

    public function test_r_02_ranks_budget_overruns_against_waste(): void
    {
        $lines = $this->builder()->build('R-02', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('1. Rent over budget — + $ 200.00 vs budget.', $lines[0]);
        $this->assertStringContainsString('2. Payroll over budget — + $ 100.00 vs budget.', $lines[1]);
        $this->assertStringContainsString('3. Waste logged this month', $lines[2]);
    }

    public function test_r_02_says_so_when_nothing_is_wrong(): void
    {
        $lines = $this->builder()->build('R-02', array('month' => '2026-01'))['sections'][0]['lines'];

        $this->assertSame(array('No issues above threshold this week.'), $lines);
    }

    public function test_r_03_covers_three_days_and_the_shortfall(): void
    {
        $sections = $this->builder()->build('R-03', array('date' => '2026-09-29'))['sections'];

        $this->assertCount(4, $sections);
        $this->assertSame('2026-09-29', $sections[0]['heading']);
        $this->assertStringStartsWith('Expected covers: ', $sections[0]['lines'][0]);
        $this->assertStringContainsString('20 guests', $sections[0]['lines'][1]);
        $this->assertStringContainsString('10 guests', $sections[0]['lines'][2]);
        $this->assertSame('2026-09-30', $sections[1]['heading']);
        $this->assertSame('2026-10-01', $sections[2]['heading']);

        $this->assertSame('Stock shortfall vs expected portions', $sections[3]['heading']);
        $this->assertCount(2, $sections[3]['lines']);
        $this->assertStringContainsString('IT-1 — expected', $sections[3]['lines'][0]);
        $this->assertStringContainsString('ING-1: need ~', $sections[3]['lines'][0]);
    }

    public function test_r_04_prices_the_requested_quantity(): void
    {
        $lines = $this->builder()->build('R-04', array(
            'date'          => '2026-09-30',
            'supplier_id'   => 'SUP-1',
            'ingredient_id' => 'ING-1',
            'quantity'      => 5,
        ))['sections'][0]['lines'];

        $this->assertSame('Item: ING-1', $lines[0]);
        $this->assertSame('Quantity: 5', $lines[1]);
        $this->assertSame('Supplier: Acme Foods (SUP-1)', $lines[2]);
        $this->assertSame('Unit price: $ 3.00', $lines[3]);
        $this->assertSame('Total: $ 15.00', $lines[4]);
        $this->assertSame('Order by: 14:00', $lines[5]);
        $this->assertSame('Needed by: 2026-09-30', $lines[6]);
        $this->assertSame('Status: Draft', $lines[7]);
    }

    public function test_r_05_reports_waste_percent_and_reasons(): void
    {
        $lines = $this->builder()->build('R-05', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertSame('Waste % (logged waste ÷ stock used): 8.82%', $lines[0]);
        $this->assertSame('Spoilage: $ 10.00', $lines[1]);
        $this->assertSame('Prep trim: $ 5.00', $lines[2]);
    }

    public function test_r_06_names_the_price_rise_and_its_impact(): void
    {
        $lines = $this->builder()->build('R-06', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertSame('Price-change impact this month: $ 10.00', $lines[0]);
        $this->assertSame(
            'ING-2: 0.80 → 1.00/unit effective 2026-09-10; qty bought 50; impact $ 10.00',
            $lines[1]
        );
    }

    public function test_r_07_prints_each_dish_with_its_quadrant(): void
    {
        $lines = $this->builder()->build('R-07', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertCount(2, $lines);
        // Popularity is a share within the item's own type, so both dishes are
        // at 100% here; IT-2 is popular but below the average margin.
        $this->assertSame(
            'IT-1 Burger — margin $ 6.00 (cost 40%), share 100% — Star: Protect and promote',
            $lines[0]
        );
        $this->assertSame(
            'IT-2 Soda — margin $ 2.00 (cost 33.3%), share 100% — Plowhorse: Re-price or re-engineer',
            $lines[1]
        );
    }

    public function test_r_08_compares_this_month_with_last(): void
    {
        $sections = $this->builder()->build('R-08', array('month' => '2026-09'))['sections'];

        $this->assertSame(
            'Total operating expenses: $ 4,030.00 (prev month $ -20.00, change $ 4,050.00)',
            $sections[0]['lines'][0]
        );
        $this->assertStringContainsString('Volume effect: ', $sections[0]['lines'][1]);
        $this->assertContains('Rent: actual $ 2,000.00 vs budget $ 1,800.00 ($ 200.00)', $sections[1]['lines']);
        $this->assertContains('Cleaning: actual $ 100.00 vs budget $ 100.00 ($ 0.00)', $sections[1]['lines']);
    }

    public function test_r_09_reports_payroll_and_its_rates(): void
    {
        $lines = $this->builder()->build('R-09', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertSame('Total payroll: $ 1,500.00', $lines[0]);
        $this->assertSame('Overtime hours: 15 (per 100 covers: 37.5)', $lines[1]);
        $this->assertSame('Casual shifts: 3', $lines[2]);
        $this->assertSame('Labour cost %: 652.17%', $lines[3]);
        $this->assertSame('Kitchen: $ 1,000.00', $lines[4]);
        $this->assertSame('Front: $ 500.00', $lines[5]);
    }

    public function test_r_10_reports_meters_and_costs(): void
    {
        $lines = $this->builder()->build('R-10', array('month' => '2026-09'))['sections'][0]['lines'];

        $this->assertSame('Electricity: 100 kWh (2.5 per cover) — $ 150.00', $lines[0]);
        $this->assertSame('Water: 10 m³ — $ 10.00', $lines[1]);
        $this->assertSame('LPG: 2 cylinders — $ 100.00', $lines[2]);
    }

    public function test_r_11_flags_the_ledger_and_states_what_it_cannot_read(): void
    {
        $sections = $this->builder()->build('R-11')['sections'];

        $this->assertSame('Unpaid invoices', $sections[0]['heading']);
        // The fixture's ledger has no vendor/invoice/status columns, so every
        // row is unpaid with blank party fields — the WordPress shape exactly.
        $this->assertCount(3, $sections[0]['lines']);
        $this->assertStringContainsString('$ 2,000.00', $sections[0]['lines'][0]);
        $this->assertSame(array('None flagged.'), $sections[1]['lines']);
    }

    public function test_r_12_states_the_month_as_a_narrative(): void
    {
        $sections = $this->builder()->build('R-12', array('month' => '2026-09'))['sections'];

        $this->assertSame(
            'Revenue for 2026-09 was $ 230.00 across 40 covers (average spend $ 5.75 per cover).',
            $sections[0]['lines'][0]
        );
        $this->assertSame(
            'Food cost was 85% of food revenue and beverage cost 0% of beverage revenue, valued on stock used.',
            $sections[0]['lines'][2]
        );
        $this->assertContains('Payroll exceeded budget by $ 100.00.', $sections[1]['lines']);
        $this->assertContains('Rent exceeded budget by $ 200.00.', $sections[1]['lines']);
    }

    public function test_r_13_lists_quiet_days_stock_and_subject_usage(): void
    {
        $lines = $this->builder()->build('R-13', array('date' => '2026-09-25'))['sections'][0]['lines'];

        $this->assertStringStartsWith('Quiet days this week: ', $lines[0]);
        $this->assertStringStartsWith('Overstocked items to promote: ', $lines[1]);
        // The fixture's asset table has no `deity` column.
        $this->assertSame('Content-subject usage: none logged', $lines[2]);
    }

    public function test_r_14_is_the_operators_monthly_sales_report(): void
    {
        $sections = $this->builder()->build('R-14', array('month' => '2026-09'))['sections'];

        $this->assertSame('Revenue: $ 230.00', $sections[0]['lines'][0]);
        $this->assertSame('Covers: 40', $sections[0]['lines'][1]);
        $this->assertSame('Average spend: $ 5.75 per cover', $sections[0]['lines'][2]);
        $this->assertSame(array('Food: $ 200.00', 'Beverage: $ 30.00'), $sections[1]['lines']);
        $this->assertSame(array('IT-1: $ 200.00', 'IT-2: $ 30.00'), $sections[2]['lines']);
    }

    public function test_a_defaulted_r_14_leaves_the_period_blank_in_its_title(): void
    {
        // The month default is applied inside the report body, not in build(),
        // so the draft header still prints the empty argument — as WordPress does.
        $report = $this->builder()->build('R-14');

        $this->assertSame(
            '[R-14] Monthly sales report —  — Draft — for Owner',
            $report['draft']['doc_title']
        );
    }

    // ─── Export ────────────────────────────────────────────────────────────

    public function test_csv_export_resolves_a_client_alias_and_stores_the_rows(): void
    {
        $store    = new FnbRecordingFileStore();
        $exporter = new FnbReportExporter($this->errors, $this->builder(), null, $store);

        $payload = $exporter->toCsv('client-r02', array('month' => '2026-09'), self::CONTEXT);

        $this->assertSame('R-05', $payload['report_id']);
        $this->assertSame('csv', $payload['mode']);
        $this->assertSame('waste-and-variance-report-2026-09.csv', $payload['filename']);
        $this->assertSame('text/csv', $payload['mime_type']);
        $this->assertSame(77, $payload['attachment_id']);

        $this->assertStringStartsWith("section,key,value\n", $store->bytes);
        $this->assertStringContainsString('Waste by reason (as logged)', $store->bytes);
        $this->assertStringContainsString('8.82%', $store->bytes);
    }

    public function test_csv_export_without_a_file_registry_refuses_by_name(): void
    {
        $exporter = new FnbReportExporter($this->errors, $this->builder());
        $result   = $exporter->toCsv('R-05', array('month' => '2026-09'), self::CONTEXT);

        $this->assertSame('file_store_unavailable', $result['error']['code']);
    }

    public function test_csv_export_passes_a_report_error_through(): void
    {
        $exporter = new FnbReportExporter($this->errors, $this->builder(), null, new FnbRecordingFileStore());
        $result   = $exporter->toCsv('R-99', array(), self::CONTEXT);

        $this->assertSame('unknown_report', $result['error']['code']);
    }

    public function test_pdf_export_without_a_renderer_names_the_engine(): void
    {
        $exporter = new FnbReportExporter($this->errors, $this->builder(), null, new FnbRecordingFileStore());
        $result   = $exporter->toPdf('R-05', array('month' => '2026-09'), self::CONTEXT);

        $this->assertSame('no_pdf_engine', $result['error']['code']);
        $this->assertStringContainsString('CSV', $result['error']['message']);
    }

    public function test_pdf_export_renders_html_and_stores_the_file(): void
    {
        $pdf      = new FnbRecordingPdfRenderer();
        $store    = new FnbRecordingFileStore();
        $exporter = new FnbReportExporter($this->errors, $this->builder(), $pdf, $store);

        $payload = $exporter->toPdf('R-05', array('month' => '2026-09'), self::CONTEXT);

        $this->assertSame('R-05', $payload['report_id']);
        $this->assertSame('pdf', $payload['mode']);
        $this->assertSame('waste-and-variance-report.pdf', $payload['filename']);
        $this->assertSame('application/pdf', $payload['mime_type']);

        $this->assertTrue($pdf->called);
        $this->assertStringContainsString('<h1>Waste and variance report</h1>', $pdf->html);
        $this->assertStringContainsString('<h2>Waste by reason (as logged)</h2>', $pdf->html);
        $this->assertStringContainsString('<td>Waste % (logged waste ÷ stock used): 8.82%</td>', $pdf->html);
    }

    // ─── Drafts ────────────────────────────────────────────────────────────

    public function test_a_draft_is_saved_with_draft_status_and_markup_stripped(): void
    {
        $writer = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));

        $saved = $writer->save(
            '[R-05] Waste and variance report — 2026-09 — Draft — for Head chef',
            'Hello <b>world</b><script>bad()</script>'
        );

        $this->assertSame('r-05-waste-and-variance-report-2026-09-draft-for-head-chef', $saved['id']);
        $this->assertSame('fnb-drafts/r-05-waste-and-variance-report-2026-09-draft-for-head-chef', $saved['location']);
        $this->assertSame('paper_store', $saved['mode']);
        $this->assertSame('Draft', $saved['status']);
    }

    public function test_listing_returns_name_path_and_size(): void
    {
        $writer = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));
        $writer->save('A draft', 'Hello <b>world</b><script>bad()</script>');

        $list = $writer->list();

        $this->assertSame(1, $list['count']);
        $this->assertSame(1, $list['total']);
        $this->assertSame('a-draft.md', $list['drafts'][0]['name']);
        $this->assertSame('fnb-drafts/a-draft', $list['drafts'][0]['path']);
        $this->assertSame(strlen('Hello world'), $list['drafts'][0]['bytes']);
        $this->assertSame('A draft', $list['drafts'][0]['title']);
    }

    public function test_saving_the_same_title_replaces_the_draft(): void
    {
        $writer = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));

        $writer->save('A draft', 'first');
        $writer->save('A draft', 'second');

        $list = $writer->list();

        $this->assertSame(1, $list['count']);
        $this->assertSame(6, $list['drafts'][0]['bytes']);
    }

    public function test_a_draft_needs_a_title_with_usable_characters(): void
    {
        $writer = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));

        $this->assertSame('validation_failed', $writer->save('', 'body')['error']['code']);
        $this->assertSame('validation_failed', $writer->save('!!!', 'body')['error']['code']);
    }

    // ─── The four tools ────────────────────────────────────────────────────

    public function test_generate_report_tool_returns_the_report(): void
    {
        $tool   = new FnbGenerateReportTool($this->errors, $this->builder());
        $result = $tool->execute(array('report_id' => 'R-05', 'month' => '2026-09'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('R-05', $result['data']['report_id']);
        $this->assertSame('Waste and variance report generated.', $result['message']);
        $this->assertCount(1, $result['data']['sections']);
    }

    public function test_generate_report_tool_passes_an_unknown_id_through(): void
    {
        $tool   = new FnbGenerateReportTool($this->errors, $this->builder());
        $result = $tool->execute(array('report_id' => 'R-99'), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown_report', $result['error']['code']);
    }

    public function test_export_report_tool_wraps_the_export(): void
    {
        $exporter = new FnbReportExporter($this->errors, $this->builder(), null, new FnbRecordingFileStore());
        $tool     = new FnbExportReportTool($this->errors, $exporter);

        $result = $tool->execute(array('report_id' => 'R-05', 'format' => 'csv', 'month' => '2026-09'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('Report exported as CSV.', $result['message']);
        $this->assertSame('csv', $result['data']['mode']);
    }

    public function test_save_and_list_draft_tools_share_a_collection(): void
    {
        $writer = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));

        $saved = (new FnbSaveDraftTool($this->errors, $writer))->execute(
            array('title' => 'A draft', 'content' => 'Body'),
            self::CONTEXT
        );
        $listed = (new FnbListDraftsTool($this->errors, $writer))->execute(array(), self::CONTEXT);

        $this->assertTrue($saved['success']);
        $this->assertSame('Draft "A draft" saved.', $saved['message']);
        $this->assertTrue($listed['success']);
        $this->assertSame(1, $listed['data']['count']);
        $this->assertSame('1 draft(s).', $listed['message']);
    }

    public function test_the_writing_tools_require_a_user(): void
    {
        $writer   = new FnbDraftWriter($this->errors, new JsonPaperStore($this->tempDir()));
        $exporter = new FnbReportExporter($this->errors, $this->builder(), null, new FnbRecordingFileStore());

        foreach (
            array(
                new FnbGenerateReportTool($this->errors, $this->builder()),
                new FnbExportReportTool($this->errors, $exporter),
                new FnbSaveDraftTool($this->errors, $writer),
            ) as $tool
        ) {
            try {
                $tool->execute(array('report_id' => 'R-05', 'format' => 'csv', 'title' => 'x', 'content' => 'y'), array());
                $this->fail('Expected AccessDeniedException from ' . $tool->getSlug());
            } catch (AccessDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function builder(): FnbReportBuilder
    {
        $settings = $this->settings();

        return new FnbReportBuilder(
            $this->errors,
            new FnbMetrics($this->errors, $this->source(), $settings),
            $settings,
            $this->source()
        );
    }

    private function source(): FnbTableSource
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => FnbSettings::OPTION === $key
                ? array('table_map' => FnbFixture::map())
                : $default
        );

        return new FnbTableSource($this->errors, FnbFixture::reader(), $store);
    }

    private function settings(): FnbSettings
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => FnbSettings::OPTION === $key
                ? array('currency' => 'USD', 'as_of_date' => '2026-09-30')
                : $default
        );

        return new FnbSettings($store);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nvoos-fnb-drafts-' . bin2hex(random_bytes(6));

        mkdir($dir, 0775, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
