<?php

/**
 * The PDF tools: `extract_pdf_text`, `merge_pdfs`, `add_watermark_to_pdf`.
 *
 * Three properties are pinned here, because they are the ones that would make a
 * tool report work it did not do:
 *
 *  - **A missing engine is named, not degraded.** No bound engine answers
 *    `no_pdf_engine`, which 164.4.8 requires and which a blank document would
 *    violate.
 *  - **A file id that is not there is `not_found`, not a crash.** The registry is
 *    the authority; a tool that guessed a path would read the wrong bytes.
 *  - **The merge keeps the order the caller gave**, and reports the page count
 *    the engine returned so a dropped page is visible.
 *
 * The engine is a recording double: most assertions are about the request the
 * tool built, and a mock would assert the mock instead.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\PdfMutatorInterface;
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\AddWatermarkToPdfTool;
use Nvoos\Core\Tool\ExtractPdfTextTool;
use Nvoos\Core\Tool\MergePdfsTool;
use PHPUnit\Framework\TestCase;

final class PdfDocumentToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    // ─── extract_pdf_text ──────────────────────────────────────────────

    public function test_extract_requires_a_file_id(): void
    {
        $result = $this->extractorTool($this->extractor())->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_extract_without_a_registry_says_so(): void
    {
        $result = $this->extractorTool($this->extractor(), null)->execute(array('file_id' => 1));

        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_extract_names_an_unknown_file(): void
    {
        $result = $this->extractorTool($this->extractor(), $this->registry(array()))
            ->execute(array('file_id' => 999));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_extract_without_an_engine_is_a_named_capability_error(): void
    {
        $extractor = $this->extractor(available: false);
        $result = $this->extractorTool($extractor, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1));

        $this->assertSame('no_pdf_engine', $result['error']['code']);
        $this->assertSame(array(), $extractor->calls);
    }

    public function test_extract_returns_the_text_and_the_page_count(): void
    {
        $extractor = $this->extractor();
        $result = $this->extractorTool($extractor, $this->registry(array(7 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 7, 'max_pages' => 3));

        $this->assertTrue($result['success']);
        $this->assertSame('Page one', $result['data']['text']);
        $this->assertSame(3, $result['data']['pages']);
        $this->assertSame(8, $result['data']['characters']);
        $this->assertSame(7, $result['data']['file_id']);
        $this->assertSame(array(array('/tmp/a.pdf', 3)), $extractor->calls);
    }

    public function test_extract_wraps_an_engine_failure(): void
    {
        $extractor = $this->extractor(failure: 'pdf-parse blew up');
        $result = $this->extractorTool($extractor, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1));

        $this->assertSame('pdf_extract_failed', $result['error']['code']);
        $this->assertSame('pdf-parse blew up', $result['error']['message']);
    }

    public function test_extract_is_read_only_and_does_not_claim_local_only(): void
    {
        $flags = $this->extractorTool($this->extractor())->getCapabilityFlags();

        $this->assertContains('read-only', $flags);
        $this->assertNotContains('local-only', $flags, 'The worker is remote.');
    }

    // ─── merge_pdfs ────────────────────────────────────────────────────

    public function test_merge_needs_two_files(): void
    {
        $result = $this->mergerTool($this->mutator())->execute(array('file_ids' => array(1)));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_merge_names_an_unknown_file(): void
    {
        $result = $this->mergerTool($this->mutator(), $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_ids' => array(1, 2)));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_merge_without_an_engine_is_a_named_capability_error(): void
    {
        $mutator = $this->mutator(available: false);
        $result = $this->mergerTool($mutator, $this->registry(array(1 => '/tmp/a.pdf', 2 => '/tmp/b.pdf')))
            ->execute(array('file_ids' => array(1, 2)));

        $this->assertSame('no_pdf_engine', $result['error']['code']);
        $this->assertSame(array(), $mutator->merges);
    }

    public function test_merge_passes_the_paths_in_the_order_given(): void
    {
        $mutator = $this->mutator();
        $result = $this->mergerTool(
            $mutator,
            $this->registry(array(9 => '/tmp/z.pdf', 2 => '/tmp/a.pdf'))
        )->execute(array('file_ids' => array(9, 2), 'title' => 'Board Pack'));

        $this->assertTrue($result['success']);
        $this->assertSame(array('/tmp/z.pdf', '/tmp/a.pdf'), $mutator->merges);
        $this->assertSame(5, $result['data']['pages']);
        $this->assertSame(array(9, 2), $result['data']['source_file_ids']);
    }

    public function test_merge_stores_under_the_title_when_asked(): void
    {
        $store = $this->recordingStore();

        $result = $this->mergerTool(
            $this->mutator(),
            $this->registry(array(1 => '/tmp/a.pdf', 2 => '/tmp/b.pdf')),
            $store
        )->execute(array('file_ids' => array(1, 2), 'title' => 'Board Pack'));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->writes);
        $this->assertSame('board-pack.pdf', $store->writes[0]['filename']);
        $this->assertSame(500, $result['data']['media'][0]['attachment_id']);
    }

    public function test_merge_can_skip_storage(): void
    {
        $store = $this->recordingStore();

        $result = $this->mergerTool(
            $this->mutator(),
            $this->registry(array(1 => '/tmp/a.pdf', 2 => '/tmp/b.pdf')),
            $store
        )->execute(array('file_ids' => array(1, 2), 'save_to_media' => false));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $store->writes);
        $this->assertArrayNotHasKey('media', $result['data']);
    }

    public function test_merge_wraps_an_engine_failure(): void
    {
        $result = $this->mergerTool(
            $this->mutator(failure: 'pdf-lib refused'),
            $this->registry(array(1 => '/tmp/a.pdf', 2 => '/tmp/b.pdf'))
        )->execute(array('file_ids' => array(1, 2)));

        $this->assertSame('pdf_merge_failed', $result['error']['code']);
    }

    // ─── add_watermark_to_pdf ──────────────────────────────────────────

    public function test_watermark_requires_a_file_and_a_mark(): void
    {
        $tool = $this->watermarkTool($this->mutator());

        $this->assertSame('validation_failed', $tool->execute(array('text' => 'DRAFT'))['error']['code']);
        $this->assertSame('validation_failed', $tool->execute(array('file_id' => 1))['error']['code']);
    }

    public function test_watermark_names_an_unknown_file(): void
    {
        $result = $this->watermarkTool($this->mutator(), $this->registry(array()))
            ->execute(array('file_id' => 3, 'text' => 'DRAFT'));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_watermark_without_an_engine_is_a_named_capability_error(): void
    {
        $result = $this->watermarkTool($this->mutator(available: false), $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1, 'text' => 'DRAFT'));

        $this->assertSame('no_pdf_engine', $result['error']['code']);
    }

    public function test_watermark_echoes_the_mark_and_stores_the_file(): void
    {
        $mutator = $this->mutator();
        $store = $this->recordingStore();

        $result = $this->watermarkTool($mutator, $this->registry(array(4 => '/tmp/a.pdf')), $store)
            ->execute(array('file_id' => 4, 'text' => 'CONFIDENTIAL'));

        $this->assertTrue($result['success']);
        $this->assertSame('CONFIDENTIAL', $result['data']['watermark']);
        $this->assertSame(array(array('/tmp/a.pdf', 'CONFIDENTIAL', array())), $mutator->watermarks);
        $this->assertSame('watermarked.pdf', $store->writes[0]['filename']);
    }

    public function test_watermark_offers_the_style_knobs_the_engine_honours(): void
    {
        $schema = $this->watermarkTool($this->mutator())->getParametersSchema();

        $this->assertArrayHasKey('opacity', $schema['properties']);
        $this->assertArrayHasKey('position', $schema['properties']);
        $this->assertSame(
            array('diagonal', 'center', 'top', 'bottom'),
            $schema['properties']['position']['enum']
        );
    }

    public function test_a_requested_style_reaches_the_engine(): void
    {
        $mutator = $this->mutator();

        $result = $this->watermarkTool($mutator, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1, 'text' => 'DRAFT', 'opacity' => 0.75, 'position' => 'top'));

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(array('/tmp/a.pdf', 'DRAFT', array('opacity' => 0.75, 'position' => 'top'))),
            $mutator->watermarks
        );
    }

    public function test_an_engine_without_style_support_refuses_a_non_default_style(): void
    {
        $mutator = $this->mutator(styleSupported: false);

        $result = $this->watermarkTool($mutator, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1, 'text' => 'DRAFT', 'position' => 'top'));

        $this->assertSame('watermark_style_unavailable', $result['error']['code']);
        $this->assertSame(array(), $mutator->watermarks, 'Nothing is stamped under the wrong style.');
    }

    public function test_an_engine_without_style_support_still_stamps_the_default_mark(): void
    {
        $mutator = $this->mutator(styleSupported: false);

        $result = $this->watermarkTool($mutator, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1, 'text' => 'DRAFT', 'opacity' => 0.3, 'position' => 'diagonal'));

        $this->assertTrue($result['success'], 'The historical default needs no style support.');
        $this->assertCount(1, $mutator->watermarks);
    }

    // ─── Doubles ───────────────────────────────────────────────────────

    private function extractorTool(
        PdfTextExtractorInterface $extractor,
        ?FileStoreInterface $files = null
    ): ExtractPdfTextTool {
        return new ExtractPdfTextTool($this->errors, $extractor, $files);
    }

    private function mergerTool(
        PdfMutatorInterface $mutator,
        ?FileStoreInterface $files = null,
        ?FileVisibilityStoreInterface $store = null
    ): MergePdfsTool {
        return new MergePdfsTool($this->errors, $mutator, $files, $store);
    }

    private function watermarkTool(
        PdfMutatorInterface $mutator,
        ?FileStoreInterface $files = null,
        ?FileVisibilityStoreInterface $store = null
    ): AddWatermarkToPdfTool {
        return new AddWatermarkToPdfTool($this->errors, $mutator, $files, $store);
    }

    /**
     * A registry that answers the ids it was given and null for the rest.
     *
     * @param array<int, string> $paths
     */
    private function registry(array $paths): FileStoreInterface
    {
        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getPath')->willReturnCallback(
            static fn (int $id): ?string => $paths[$id] ?? null
        );

        return $files;
    }

    /**
     * A recording extractor.
     */
    private function extractor(bool $available = true, ?string $failure = null): PdfTextExtractorInterface
    {
        return new class ($available, $failure) implements PdfTextExtractorInterface {
            /** @var list<array{0: string, 1: int}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
            ) {
            }

            public function extract(string $localPath, int $maxPages = 0): array
            {
                $this->calls[] = array($localPath, $maxPages);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'text' => 'Page one',
                    'pages' => 3,
                    'info' => array('title' => 'A'),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * A real PDF-ish file.
     *
     * The registry bridge checks `is_file()` before it stores anything, so a
     * fake path would assert the mock rather than the wiring.
     */
    private function tempFile(string $name): string
    {
        $path = \sys_get_temp_dir() . '/nvoos-pdf-' . \bin2hex(\random_bytes(4)) . '-' . $name;
        \file_put_contents($path, '%PDF-1.4 test');
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * A recording mutator.
     */
    private function mutator(bool $available = true, ?string $failure = null, bool $styleSupported = true): PdfMutatorInterface
    {
        return new class ($available, $failure, $this->tempFile('merged.pdf'), $this->tempFile('watermarked.pdf'), $styleSupported) implements PdfMutatorInterface {
            /** @var list<array<int, string>> */
            public array $merges = array();

            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $watermarks = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly string $mergePath,
                private readonly string $watermarkPath,
                private readonly bool $styleSupported = true,
            ) {
            }

            public function merge(array $localPaths): array
            {
                $this->merges = array_values($localPaths);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'path' => $this->mergePath,
                    'mime_type' => 'application/pdf',
                    'bytes' => 2048,
                    'pages' => 5,
                );
            }

            public function watermark(string $localPath, string $text, array $options = array()): array
            {
                $this->watermarks[] = array($localPath, $text, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'path' => $this->watermarkPath,
                    'mime_type' => 'application/pdf',
                    'bytes' => 1024,
                    'pages' => 2,
                );
            }

            public function watermarkStyleSupported(): bool
            {
                return $this->styleSupported;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * A file store that records each write.
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
                );

                return new StoredFile(
                    id: 500,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 2048,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'https://example.test/files/' . $filename,
                    visibility: $visibility,
                    slug: 'stored',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array(
                    $visibility,
                    array(self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE),
                    true
                );
            }
        };
    }
}
