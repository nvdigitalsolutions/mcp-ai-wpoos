<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\HtmlToPdfTool;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerHtmlToPdf;
use Tests\TestCase;

/**
 * `html_to_pdf` reaches the media worker — Proposal 164 §164.4.2/§164.4.3.
 *
 * Phase 1 does not build a PDF renderer; it binds the endpoint the worker
 * already exposes. Two things can silently drift and both are pinned here:
 *
 *  - **The request shape.** `/api/pdf/generate` takes `{ html, options }` with
 *    `format`, `landscape` and `printBackground` — the exact keys the WordPress
 *    `pro_pdf` caller sends. Diverging would make one runtime render differently
 *    from the other.
 *  - **The output.** The worker answers base64 and the adapter must leave real
 *    bytes on disk, because the file registry's contract is a local path.
 *
 * The worker itself is faked; its own bounds belong to its own test suite.
 */
final class HtmlToPdfAdapterTest extends TestCase
{
    /**
     * A minimal PDF header — enough for the adapter, which never parses it.
     */
    private const PDF_B64 = 'JVBERi0xLjQKJcTl8uXrp/Og0MTGCg==';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-pdf-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
            'oos.media_worker.render_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $this->assertInstanceOf(
            MediaWorkerHtmlToPdf::class,
            $this->app->make(HtmlToPdfInterface::class)
        );
        $this->assertTrue($this->app->make(HtmlToPdfInterface::class)->isAvailable());
    }

    public function test_a_render_posts_the_worker_request_and_writes_the_bytes(): void
    {
        $captured = null;
        $this->fakeRender($captured);

        $result = $this->adapter()->render(
            '<h1>Invoice</h1>',
            array( 'format' => 'A4', 'landscape' => false, 'print_background' => true )
        );

        $this->assertSame('http://worker.test/api/pdf/generate', $captured['url']);
        $this->assertSame('<h1>Invoice</h1>', $captured['body']['html']);
        $this->assertSame(
            array( 'format' => 'A4', 'landscape' => false, 'printBackground' => true ),
            $captured['body']['options']
        );

        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertTrue(is_file($result['path']), 'The adapter must leave real bytes on disk.');
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($result['path']));
        $this->assertSame(strlen((string) base64_decode(self::PDF_B64, true)), $result['bytes']);

        // The auth headers are the worker's own contract, shared with WordPress.
        $this->assertSame('http://localhost', $captured['headers']['X-Site-Url'][0] ?? null);
    }

    public function test_the_same_document_rewrites_one_content_addressed_file(): void
    {
        $captured = null;
        $this->fakeRender($captured);

        $adapter = $this->adapter();

        $first = $adapter->render('<p>same</p>');
        $second = $adapter->render('<p>same</p>');
        $third = $adapter->render('<p>different</p>');

        $this->assertSame($first['path'], $second['path'], 'An identical render must not accumulate copies.');
        $this->assertNotSame($first['path'], $third['path']);
        $this->assertCount(2, glob($this->workDir.'/out/*.pdf') ?: array());
    }

    public function test_the_page_size_is_canonicalised(): void
    {
        $captured = null;
        $this->fakeRender($captured);

        $adapter = $this->adapter();

        $adapter->render('<p>x</p>', array( 'format' => 'a4' ));
        $this->assertSame('A4', $captured['body']['options']['format']);

        $adapter->render('<p>y</p>', array( 'format' => 'A2' ));
        $this->assertSame('A4', $captured['body']['options']['format'], 'An unknown size falls back to A4.');
    }

    public function test_an_empty_document_is_refused_before_the_call(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->adapter()->render("  \n ");
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_worker_error_becomes_an_exception(): void
    {
        Http::fake(array(
            'worker.test/*' => Http::response(array( 'error' => 'chromium exited with code 1' ), 500),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Media worker error: chromium exited with code 1');

        $this->adapter()->render('<p>x</p>');
    }

    public function test_a_response_with_no_payload_is_refused(): void
    {
        Http::fake(array(
            'worker.test/*' => Http::response(array( 'success' => true ), 200),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('returned no PDF payload');

        $this->adapter()->render('<p>x</p>');
    }

    public function test_an_unconfigured_worker_reports_unavailable_and_refuses_to_render(): void
    {
        config(array( 'oos.media_worker.url' => '' ));
        Http::fake();

        $adapter = $this->adapter();

        $this->assertFalse($adapter->isAvailable());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The media worker is not configured.');

        try {
            $adapter->render('<p>x</p>');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_tool_runs_end_to_end_and_stores_the_file(): void
    {
        $captured = null;
        $this->fakeRender($captured);

        $store = $this->recordingStore();
        $this->app->instance(FileVisibilityStoreInterface::class, $store);

        $result = $this->app->make(HtmlToPdfTool::class)->execute(
            array( 'html' => '<p>hello</p>', 'title' => 'Weekly Summary' ),
            array( 'user_id' => 3 )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pdf', $result['data']['format']);
        $this->assertSame('A4', $result['data']['page_size']);
        $this->assertSame('portrait', $result['data']['orientation']);

        $this->assertCount(1, $store->writes);
        $this->assertSame('weekly-summary.pdf', $store->writes[0]['filename']);
        $this->assertSame('application/pdf', $store->writes[0]['mimeType']);
        $this->assertSame(900, $result['data']['media'][0]['attachment_id']);
    }

    private function adapter(): HtmlToPdfInterface
    {
        return new MediaWorkerHtmlToPdf(new MediaWorkerClient());
    }

    /**
     * Fake `/api/pdf/generate` and capture the request it received.
     *
     * @param array<string, mixed>|null $captured Receives `url`, `body`, `headers`.
     */
    private function fakeRender(?array &$captured): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = array(
                'url' => $request->url(),
                'body' => json_decode($request->body(), true),
                'headers' => $request->headers(),
            );

            return Http::response(array( 'data_base64' => self::PDF_B64 ));
        });
    }

    /**
     * A file store that records each write and answers with a plausible row.
     */
    private function recordingStore(): FileVisibilityStoreInterface
    {
        return new class implements FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $writes = array();

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $this->writes[] = array(
                    'localPath' => $localPath,
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'userId' => $userId,
                    'visibility' => $visibility,
                    'tenantId' => $tenantId,
                    'existed' => is_file($localPath),
                );

                return new StoredFile(
                    id: 900,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: (int) @filesize($localPath),
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'https://example.test/files/'.$filename,
                    visibility: $visibility,
                    slug: 'weekly-summary',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array(
                    $visibility,
                    array( self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE ),
                    true
                );
            }
        };
    }
}
