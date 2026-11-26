<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;
use Nvoos\Laravel\Adapter\MediaWorkerPdfPageRenderer;
use Nvoos\Laravel\Adapter\MediaWorkerRefusedException;
use Tests\TestCase;

/**
 * Page images over the media worker — Proposal 164 §164.44.
 *
 * The render route has always been able to rasterise a PDF; what it could not do
 * is hand the images to a *different* host, because it answered with paths on its
 * own filesystem. These tests are about the argument that changes that, and about
 * the narrower capability key that makes an older worker a named refusal rather
 * than a 200 full of paths nobody can open.
 */
final class PdfPageRendererAdapterTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-render-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->workDir.'/scan.pdf', '%PDF-1.4 scan');

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.render_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->workDir.'/scan.pdf');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ─── Wiring ────────────────────────────────────────────────────────

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $this->assertInstanceOf(
            MediaWorkerPdfPageRenderer::class,
            $this->app->make(PdfPageRendererInterface::class),
        );
    }

    /**
     * `pdf_rendering` is true on every worker that has the route. The argument is
     * newer, so a worker advertising only the older key must not claim to be able
     * to answer — otherwise `render()` succeeds and returns paths this host
     * cannot read.
     */
    public function test_a_worker_without_the_inline_argument_is_not_available(): void
    {
        $this->fakeWorker(['pdf_rendering' => true]);

        $this->assertFalse($this->app->make(PdfPageRendererInterface::class)->isAvailable());
    }

    public function test_a_worker_with_the_inline_argument_is_available(): void
    {
        $this->fakeWorker(['pdf_rendering' => true, 'pdf_render_inline' => true]);

        $this->assertTrue($this->app->make(PdfPageRendererInterface::class)->isAvailable());
    }

    // ─── Rendering ─────────────────────────────────────────────────────

    public function test_it_posts_the_workers_argument_spellings(): void
    {
        $captured = null;

        $this->fakeWorker(
            ['pdf_render_inline' => true],
            function (ClientRequest $request) use (&$captured) {
                $captured = ['url' => $request->url(), 'parts' => $request->data()];

                return Http::response([
                    'success' => true,
                    'pages' => [
                        ['page' => 3, 'mime_type' => 'image/jpeg', 'image' => 'QUJD', 'bytes' => 3],
                    ],
                    'count' => 1,
                    'total_pages' => 9,
                    'truncated' => true,
                    'mime_type' => 'image/jpeg',
                ]);
            },
        );

        $this->app->make(PdfPageRendererInterface::class)->render(
            $this->workDir.'/scan.pdf',
            ['dpi' => 200, 'max_pages' => 5, 'pages' => [3], 'format' => 'jpeg', 'quality' => 70],
        );

        $this->assertSame('http://worker.test/api/pdf/render', $captured['url']);
        $this->assertSame('1', $this->part($captured, 'inline'));
        $this->assertSame('200', $this->part($captured, 'dpi'));
        $this->assertSame('5', $this->part($captured, 'maxPages'));
        $this->assertSame('3', $this->part($captured, 'pages'));
        $this->assertSame('jpeg', $this->part($captured, 'format'));
        $this->assertSame('70', $this->part($captured, 'quality'));
    }

    public function test_it_reads_the_pages_back_as_base64_with_their_type(): void
    {
        $this->fakeWorker(['pdf_render_inline' => true], fn () => Http::response([
            'success' => true,
            'pages' => [
                ['page' => 1, 'mime_type' => 'image/png', 'image' => 'QUJD', 'bytes' => 3],
                ['page' => 2, 'mime_type' => 'image/png', 'image' => 'REVG', 'bytes' => 3],
            ],
            'count' => 2,
            'total_pages' => 7,
            'truncated' => true,
        ]));

        $result = $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/scan.pdf');

        $this->assertSame(7, $result['total_pages']);
        $this->assertTrue($result['truncated']);
        $this->assertCount(2, $result['pages']);
        $this->assertSame(1, $result['pages'][0]['page']);
        $this->assertSame('image/png', $result['pages'][0]['mime_type']);
        $this->assertSame('QUJD', $result['pages'][0]['data']);
        $this->assertSame(3, $result['pages'][0]['bytes']);
        $this->assertSame('DEF', base64_decode($result['pages'][1]['data'], true));
    }

    /**
     * Vision models downsample to their own patch grid, so the default is lower
     * than the OCR contract's 300.
     */
    public function test_the_default_dpi_comes_from_the_contract(): void
    {
        $captured = null;

        $this->fakeWorker(
            ['pdf_render_inline' => true],
            function (ClientRequest $request) use (&$captured) {
                $captured = ['parts' => $request->data()];

                return Http::response([
                    'success' => true,
                    'pages' => [['page' => 1, 'mime_type' => 'image/png', 'image' => 'QQ==', 'bytes' => 1]],
                    'total_pages' => 1,
                    'truncated' => false,
                ]);
            },
        );

        $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/scan.pdf');

        $this->assertSame(
            (string) PdfPageRendererInterface::DEFAULT_DPI,
            $this->part($captured, 'dpi'),
        );
    }

    public function test_page_entries_that_are_not_objects_are_skipped(): void
    {
        $this->fakeWorker(['pdf_render_inline' => true], fn () => Http::response([
            'success' => true,
            'pages' => ['not-an-object', ['page' => 2, 'mime_type' => 'image/png', 'image' => 'QQ==', 'bytes' => 1]],
            'count' => 1,
            'total_pages' => 2,
            'truncated' => false,
        ]));

        $result = $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/scan.pdf');

        $this->assertCount(1, $result['pages']);
        $this->assertSame(2, $result['pages'][0]['page']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────

    public function test_a_missing_file_is_refused_before_any_request(): void
    {
        $this->fakeWorker(['pdf_render_inline' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/absent.pdf');
    }

    /**
     * A payload the route will not fit is the caller's to fix — fewer pages, a
     * lower dpi, jpeg — not a server fault, so it arrives as the narrower
     * exception the runtime already reports as a bad request.
     */
    public function test_a_too_large_inline_payload_is_a_refusal_not_a_crash(): void
    {
        $this->fakeWorker(
            ['pdf_render_inline' => true],
            fn () => Http::response(
                ['code' => 'payload_too_large', 'error' => 'Inline rendering passed 25165824 bytes at page 40; ask for fewer pages, a lower dpi, or format "jpeg".'],
                413,
            ),
        );

        $this->expectException(MediaWorkerRefusedException::class);
        $this->expectExceptionMessage('fewer pages');

        $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/scan.pdf');
    }

    public function test_an_empty_page_set_is_an_error_rather_than_an_empty_success(): void
    {
        $this->fakeWorker(['pdf_render_inline' => true], fn () => Http::response([
            'success' => true,
            'pages' => [],
            'count' => 0,
            'total_pages' => 9,
            'truncated' => true,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no page images');

        $this->app->make(PdfPageRendererInterface::class)->render($this->workDir.'/scan.pdf');
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Fake the health matrix, and the render call when one is expected.
     *
     * @param array<string, bool>          $capabilities
     * @param callable|null               $render       Answers `/api/pdf/render`.
     */
    private function fakeWorker(array $capabilities, ?callable $render = null): void
    {
        Http::fake(function (ClientRequest $request) use ($capabilities, $render) {
            if (str_contains($request->url(), '/api/health')) {
                return Http::response([
                    'status' => 'ok',
                    'version' => '3.6.0',
                    'capabilities' => $capabilities,
                ]);
            }

            if (null !== $render) {
                return $render($request);
            }

            return Http::response(['success' => false, 'error' => 'unexpected request'], 500);
        });
    }

    /**
     * Read one multipart field from a captured request.
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
}
