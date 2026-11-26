<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Contract\PdfMutatorInterface;
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\AddWatermarkToPdfTool;
use Nvoos\Core\Tool\ExtractPdfTextTool;
use Nvoos\Core\Tool\GenerateExcelTool;
use Nvoos\Core\Tool\GenerateWordTool;
use Nvoos\Core\Tool\MergePdfsTool;
use Nvoos\Laravel\Adapter\MediaWorkerOfficeWriter;
use Nvoos\Laravel\Adapter\MediaWorkerPdfMutator;
use Nvoos\Laravel\Adapter\MediaWorkerPdfTextExtractor;
use Tests\TestCase;

/**
 * The document family reaches the media worker — Proposal 164 §164.4.3.
 *
 * Five endpoints, two transports, and one thing that can silently drift: the
 * field names. `maxPages` is not `max_pages`, `printBackground` is not
 * `print_background`, and a merge's order lives in the multipart part index.
 * Those are the assertions that matter, because a wrong field name is accepted
 * by the worker and silently ignored.
 *
 * The worker is faked; its own bounds belong to `addons/media-worker`.
 */
final class PdfDocumentAdapterTest extends TestCase
{
    /** A minimal PDF header — the adapters never parse it. */
    private const PDF_B64 = 'JVBERi0xLjQKJcTl8uXrp/Og0MTGCg==';

    /** A minimal ZIP header, as a stand-in for an OOXML container. */
    private const DOCX_B64 = 'UEsDBBQAAAAAAAAAAAAAAAAAAAAAAAAAAA==';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-docs-'.bin2hex(random_bytes(5));

        mkdir($this->workDir.'/src', 0775, true);
        mkdir($this->workDir.'/out', 0775, true);

        file_put_contents($this->workDir.'/src/a.pdf', '%PDF-1.4 a');
        file_put_contents($this->workDir.'/src/b.pdf', '%PDF-1.4 b');

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
            'oos.media_worker.pdf_timeout' => 5,
            'oos.media_worker.extract_timeout' => 5,
            'oos.media_worker.document_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/src/a.pdf');
        @unlink($this->workDir.'/src/b.pdf');
        @rmdir($this->workDir.'/src');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ─── Contract wiring ───────────────────────────────────────────────

    public function test_every_document_contract_resolves_to_a_worker_adapter(): void
    {
        $this->assertInstanceOf(PdfTextExtractorInterface::class, $this->app->make(PdfTextExtractorInterface::class));
        $this->assertInstanceOf(MediaWorkerPdfTextExtractor::class, $this->app->make(PdfTextExtractorInterface::class));
        $this->assertInstanceOf(MediaWorkerPdfMutator::class, $this->app->make(PdfMutatorInterface::class));
        $this->assertInstanceOf(MediaWorkerOfficeWriter::class, $this->app->make(OfficeWriterInterface::class));

        $this->assertTrue($this->app->make(PdfTextExtractorInterface::class)->isAvailable());
        $this->assertTrue($this->app->make(PdfMutatorInterface::class)->isAvailable());
        $this->assertTrue($this->app->make(OfficeWriterInterface::class)->isAvailable());
    }

    public function test_an_unconfigured_worker_reports_every_engine_unavailable(): void
    {
        config(['oos.media_worker.url' => '']);
        Http::fake();

        $this->assertFalse($this->app->make(PdfTextExtractorInterface::class)->isAvailable());
        $this->assertFalse($this->app->make(PdfMutatorInterface::class)->isAvailable());
        $this->assertFalse($this->app->make(OfficeWriterInterface::class)->isAvailable());

        Http::assertNothingSent();
    }

    // ─── extract ───────────────────────────────────────────────────────

    public function test_extract_sends_the_workers_page_limit_spelling(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = [
                'url' => $request->url(),
                'parts' => $request->data(),
            ];

            return Http::response(['success' => true, 'text' => 'Hello', 'pages' => 4, 'info' => ['title' => 'T']]);
        });

        $result = $this->app->make(PdfTextExtractorInterface::class)
            ->extract($this->workDir.'/src/a.pdf', 2);

        $this->assertSame('http://worker.test/api/pdf/extract', $captured['url']);
        $this->assertSame('2', (string) $this->part($captured, 'maxPages'));
        $this->assertSame('Hello', $result['text']);
        $this->assertSame(4, $result['pages']);
    }

    public function test_extract_refuses_a_missing_file_before_the_call(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->app->make(PdfTextExtractorInterface::class)->extract($this->workDir.'/src/nope.pdf');
        } finally {
            Http::assertNothingSent();
        }
    }

    // ─── merge ─────────────────────────────────────────────────────────

    public function test_merge_sends_both_files_in_index_order(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = [
                'url' => $request->url(),
                'parts' => $request->data(),
            ];

            return Http::response([
                'success' => true,
                'data_base64' => self::PDF_B64,
                'size' => 20,
                'pages' => 6,
            ]);
        });

        $result = $this->app->make(PdfMutatorInterface::class)
            ->merge([$this->workDir.'/src/a.pdf', $this->workDir.'/src/b.pdf']);

        $this->assertSame('http://worker.test/api/pdf/merge', $captured['url']);
        $names = array_map(static fn (array $part): string => (string) ($part['name'] ?? ''), $captured['parts']);
        $this->assertContains('files[0]', $names);
        $this->assertContains('files[1]', $names);

        $this->assertSame(6, $result['pages']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($result['path']));
    }

    public function test_merge_needs_two_files(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->app->make(PdfMutatorInterface::class)->merge([$this->workDir.'/src/a.pdf']);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ─── watermark ─────────────────────────────────────────────────────

    public function test_watermark_sends_the_text_and_writes_the_result(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = ['parts' => $request->data()];

            return Http::response([
                'success' => true,
                'data_base64' => self::PDF_B64,
                'size' => 20,
                'pages' => 3,
            ]);
        });

        $result = $this->app->make(PdfMutatorInterface::class)
            ->watermark($this->workDir.'/src/a.pdf', 'CONFIDENTIAL');

        $this->assertSame('CONFIDENTIAL', (string) $this->part($captured, 'watermark'));
        $this->assertSame(3, $result['pages']);
        $this->assertTrue(is_file($result['path']));
    }

    public function test_a_worker_error_becomes_an_exception(): void
    {
        Http::fake([
            'worker.test/*' => Http::response(['error' => 'pdf-lib refused'], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Media worker error: pdf-lib refused');

        $this->app->make(PdfMutatorInterface::class)->watermark($this->workDir.'/src/a.pdf', 'DRAFT');
    }

    // ─── word / excel ──────────────────────────────────────────────────

    public function test_word_posts_the_content_object(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = [
                'url' => $request->url(),
                'body' => json_decode($request->body(), true),
            ];

            return Http::response(['success' => true, 'data_base64' => self::DOCX_B64, 'size' => 24]);
        });

        $result = $this->app->make(OfficeWriterInterface::class)
            ->docx(['title' => 'Report', 'paragraphs' => ['Body']]);

        $this->assertSame('http://worker.test/api/document/word', $captured['url']);
        $this->assertSame(['title' => 'Report', 'paragraphs' => ['Body']], $captured['body']['content']);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $result['mime_type']
        );
        $this->assertTrue(is_file($result['path']));
    }

    public function test_excel_posts_sheets_and_only_the_options_the_route_reads(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = ['body' => json_decode($request->body(), true)];

            return Http::response(['success' => true, 'data_base64' => self::DOCX_B64, 'size' => 24]);
        });

        $this->app->make(OfficeWriterInterface::class)->xlsx(
            [['name' => 'Q1', 'rows' => [['x']]]],
            ['creator' => 'Acme', 'title' => 'Sales', 'ignored' => 'nope']
        );

        $this->assertSame([['name' => 'Q1', 'rows' => [['x']]]], $captured['body']['sheets']);
        $this->assertSame(['creator' => 'Acme', 'title' => 'Sales'], $captured['body']['options']);
    }

    // ─── End to end, through the container ─────────────────────────────

    public function test_extract_pdf_text_runs_end_to_end(): void
    {
        $this->bindRegistry([5 => $this->workDir.'/src/a.pdf']);

        Http::fake([
            'worker.test/*' => Http::response(['success' => true, 'text' => 'Invoice total 10', 'pages' => 1]),
        ]);

        $result = $this->app->make(ExtractPdfTextTool::class)->execute(['file_id' => 5]);

        $this->assertTrue($result['success']);
        $this->assertSame('Invoice total 10', $result['data']['text']);
        $this->assertSame(16, $result['data']['characters']);
    }

    public function test_merge_pdfs_runs_end_to_end_and_stores_the_file(): void
    {
        $registry = $this->bindRegistry([
            1 => $this->workDir.'/src/a.pdf',
            2 => $this->workDir.'/src/b.pdf',
        ]);

        Http::fake([
            'worker.test/*' => Http::response([
                'success' => true,
                'data_base64' => self::PDF_B64,
                'size' => 20,
                'pages' => 4,
            ]),
        ]);

        $result = $this->app->make(MergePdfsTool::class)->execute([
            'file_ids' => [1, 2],
            'title' => 'Combined',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['data']['pages']);
        $this->assertSame([1, 2], $result['data']['source_file_ids']);
        $this->assertCount(1, $registry->writes);
        $this->assertSame('combined.pdf', $registry->writes[0]['filename']);
        $this->assertSame(801, $result['data']['media'][0]['attachment_id']);
    }

    public function test_watermark_and_the_office_tools_run_end_to_end(): void
    {
        $this->bindRegistry([1 => $this->workDir.'/src/a.pdf']);

        Http::fake([
            'worker.test/*' => Http::response([
                'success' => true,
                'data_base64' => self::DOCX_B64,
                'size' => 24,
                'pages' => 2,
            ]),
        ]);

        $watermark = $this->app->make(AddWatermarkToPdfTool::class)
            ->execute(['file_id' => 1, 'text' => 'DRAFT']);
        $this->assertTrue($watermark['success']);
        $this->assertSame('DRAFT', $watermark['data']['watermark']);

        $word = $this->app->make(GenerateWordTool::class)->execute(['title' => 'Report']);
        $this->assertTrue($word['success']);
        $this->assertSame('docx', $word['data']['format']);

        $excel = $this->app->make(GenerateExcelTool::class)->execute([
            'sheets' => [['name' => 'Q1', 'rows' => [['x'], ['y']]]],
        ]);
        $this->assertTrue($excel['success']);
        $this->assertSame(2, $excel['data']['rows']);
    }

    /**
     * Read one multipart field from the captured request.
     *
     * `Request::data()` returns a list of `{ name, contents }` parts, not a map,
     * so a field is found by scanning rather than indexing.
     *
     * @param array<string, mixed>|null $captured
     */
    private function part(?array $captured, string $name): ?string
    {
        foreach ((array) ($captured['parts'] ?? []) as $part) {
            if (is_array($part) && $name === ($part['name'] ?? null)) {
                return is_resource($part['contents'] ?? null)
                    ? null
                    : (string) ($part['contents'] ?? '');
            }
        }

        return null;
    }

    /**
     * Bind a registry that answers ids and records writes.
     *
     * @param array<int, string> $paths
     */
    private function bindRegistry(array $paths): object
    {
        $registry = new class ($paths) implements FileStoreInterface, FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $writes = [];

            /**
             * @param array<int, string> $paths
             */
            public function __construct(private readonly array $paths)
            {
            }

            public function getPath(int $fileId): ?string
            {
                return $this->paths[$fileId] ?? null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return $this->storeWithVisibility($localPath, $filename, $mimeType, $userId, self::VISIBILITY_PUBLIC);
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return [];
            }

            public function delete(int $fileId): void
            {
            }

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $this->writes[] = [
                    'localPath' => $localPath,
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'visibility' => $visibility,
                    'tenantId' => $tenantId,
                ];

                return new StoredFile(
                    id: 801,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: (int) @filesize($localPath),
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'https://example.test/files/'.$filename,
                    visibility: $visibility,
                    slug: 'stored',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true);
            }
        };

        $this->app->instance(FileStoreInterface::class, $registry);
        $this->app->instance(FileVisibilityStoreInterface::class, $registry);

        return $registry;
    }
}
