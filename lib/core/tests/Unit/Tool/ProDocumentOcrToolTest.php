<?php

/**
 * `pro_document_ocr`.
 *
 * The port's substance is the ladder: a born-digital page is read from its text
 * layer and never rendered, a scan falls through to OCR. What this file pins is
 * that decision — including the two cases that are easy to get wrong: a rich
 * layer on a host with no OCR engine still succeeds, and a thin layer on the
 * same host is a named refusal rather than an empty success.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\ProDocumentOcrTool;
use PHPUnit\Framework\TestCase;

final class ProDocumentOcrToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Resource not found.', array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('pro_document_ocr', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
    }

    // ─── The ladder ────────────────────────────────────────────────────────

    public function test_a_born_digital_pdf_is_read_from_its_text_layer_without_ocr(): void
    {
        $ocr = $this->ocr();
        $layers = $this->layer(true, str_repeat('Invoice line. ', 100), 3);

        $result = $this->tool($ocr, $layers, $this->registry(array(7 => '/srv/born.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('text_layer', $result['data']['source']);
        $this->assertSame(3, $result['data']['pages_read']);
        $this->assertSame(3, $result['data']['total_pages']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertNull($result['data']['ocr']);
        $this->assertSame(array(), $ocr->calls, 'OCR must not run for a page that has text');
    }

    public function test_a_scan_falls_through_to_ocr_and_names_the_source(): void
    {
        $ocr = $this->ocr();
        $layers = $this->layer(true, 'Scanned', 3);

        $result = $this->tool($ocr, $layers, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'dpi' => 400, 'language' => 'deu'));

        $this->assertTrue($result['success']);
        $this->assertSame('ocr', $result['data']['source']);
        $this->assertSame(400, $result['data']['ocr']['dpi']);
        $this->assertSame('deu', $result['data']['ocr']['language']);
        $this->assertCount(1, $ocr->calls);
        $this->assertSame(400, $ocr->calls[0][1]['dpi']);
    }

    public function test_prefer_text_layer_false_forces_ocr_even_when_the_layer_is_rich(): void
    {
        $ocr = $this->ocr();
        $layers = $this->layer(true, str_repeat('Rich text. ', 500), 1);

        $result = $this->tool($ocr, $layers, $this->registry(array(7 => '/srv/born.pdf')))
            ->execute(array('file_id' => 7, 'prefer_text_layer' => false));

        $this->assertSame('ocr', $result['data']['source']);
        $this->assertCount(1, $ocr->calls);
    }

    /**
     * OCR being absent is only a problem when OCR is needed. A host that can
     * extract but not recognise still serves every born-digital document.
     */
    public function test_a_rich_layer_succeeds_even_with_no_ocr_engine(): void
    {
        $layers = $this->layer(true, str_repeat('Text. ', 500), 1);

        $result = $this->tool($this->ocr(3, false), $layers, $this->registry(array(7 => '/srv/born.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('text_layer', $result['data']['source']);
    }

    public function test_a_thin_layer_with_no_ocr_engine_is_a_named_refusal(): void
    {
        $layers = $this->layer(true, 'Scanned', 3);

        $result = $this->tool($this->ocr(3, false), $layers, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('no_ocr_engine', $result['error']['code']);
        $this->assertStringContainsString('text layer', $result['error']['message']);
    }

    public function test_an_unavailable_extractor_falls_through_to_ocr(): void
    {
        $ocr = $this->ocr();
        $layers = $this->layer(false, str_repeat('unused ', 100), 1);

        $result = $this->tool($ocr, $layers, $this->registry(array(7 => '/srv/a.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertSame('ocr', $result['data']['source']);
        $this->assertCount(1, $ocr->calls);
    }

    public function test_a_failing_extractor_falls_through_to_ocr(): void
    {
        $ocr = $this->ocr();
        $layers = $this->layer(true, '', 1, 'the text layer is unreadable');

        $result = $this->tool($ocr, $layers, $this->registry(array(7 => '/srv/a.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertSame('ocr', $result['data']['source']);
        $this->assertCount(1, $ocr->calls);
    }

    public function test_a_long_document_reports_the_text_layer_as_truncated(): void
    {
        // 100 pages, a 20-page budget, comfortably above the per-page floor.
        $layers = $this->layer(true, str_repeat('Line of text. ', 200), 100);

        $result = $this->tool($this->ocr(100), $layers, $this->registry(array(7 => '/srv/long.pdf')))
            ->execute(array('file_id' => 7, 'max_pages' => 20));

        $this->assertSame('text_layer', $result['data']['source']);
        $this->assertSame(20, $result['data']['pages_read']);
        $this->assertSame(100, $result['data']['total_pages']);
        $this->assertTrue($result['data']['truncated']);
    }

    // ─── Providers ─────────────────────────────────────────────────────────

    public function test_auto_and_tesseract_both_read_the_document(): void
    {
        $files = $this->registry(array(7 => '/srv/scan.pdf'));

        foreach (array('auto', 'tesseract') as $provider) {
            $result = $this->tool($this->ocr(), $this->layer(true, 'Scan', 1), $files)
                ->execute(array('file_id' => 7, 'provider' => $provider));

            $this->assertTrue($result['success'], $provider);
            $this->assertSame('ocr', $result['data']['source'], $provider);
        }
    }

    /**
     * The vLLM names are still refused: this host has no address for them, and
     * answering under a name that did not answer is the defect this prevents.
     */
    public function test_the_self_hosted_vllm_models_are_refused_by_name(): void
    {
        $files = $this->registry(array(7 => '/srv/a.pdf'));

        foreach (array('unlimited_ocr', 'deepseek_ocr') as $provider) {
            $ocr = $this->ocr();
            $layers = $this->layer(true, str_repeat('Rich. ', 500), 1);

            $result = $this->tool($ocr, $layers, $files)->execute(
                array('file_id' => 7, 'provider' => $provider)
            );

            $this->assertFalse($result['success'], $provider);
            $this->assertSame('ocr_provider_unavailable', $result['error']['code'], $provider);
            $this->assertStringContainsString($provider, $result['error']['message'], $provider);
            $this->assertSame(array(), $ocr->calls, 'A refused provider must not reach the engine.');
        }
    }

    /**
     * A scan, a renderer and a provider: the page is transcribed, and the text
     * layer is still preferred over either engine.
     */
    public function test_a_named_vision_provider_transcribes_each_rendered_page(): void
    {
        $ocr = $this->ocr();
        $renderer = $this->renderer(2);
        $router = $this->router($this->client('openai', array('First page.', 'Second page.')));

        $result = $this->tool($ocr, $this->layer(true, 'Scan', 2), $this->registry(array(7 => '/srv/scan.pdf')), $renderer, $router)
            ->execute(array('file_id' => 7, 'provider' => 'openai'));

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('ocr', $result['data']['source']);
        $this->assertSame(2, $result['data']['pages_read']);
        $this->assertSame("First page.\n\nSecond page.", $result['data']['text']);
        $this->assertSame('vision', $result['data']['ocr']['engine']);
        $this->assertSame('openai', $result['data']['ocr']['provider']);
        $this->assertSame(array(), $result['data']['ocr']['failed_pages']);
        $this->assertSame(array(), $ocr->calls, 'A named vision provider must not fall back to tesseract.');
        $this->assertSame(150, $renderer->calls[0][1]['dpi'], 'Unnamed dpi takes the renderer default.');
    }

    public function test_a_named_vision_provider_honours_a_named_dpi_and_model(): void
    {
        $renderer = $this->renderer(1);
        $router = $this->router($this->client('gemini'));

        $this->tool($this->ocr(), $this->layer(true, 'Scan', 1), $this->registry(array(7 => '/srv/scan.pdf')), $renderer, $router)
            ->execute(array('file_id' => 7, 'provider' => 'gemini', 'dpi' => 400, 'model' => 'gemini-3-pro'));

        $this->assertSame(400, $renderer->calls[0][1]['dpi']);
    }

    /**
     * The two refusals an operator acts on differently, kept apart.
     */
    public function test_vision_without_a_page_renderer_is_a_named_refusal(): void
    {
        $result = $this->tool(
            $this->ocr(),
            $this->layer(true, 'Scan', 1),
            $this->registry(array(7 => '/srv/scan.pdf')),
            null,
            $this->router($this->client('openai'))
        )->execute(array('file_id' => 7, 'provider' => 'openai'));

        $this->assertFalse($result['success']);
        $this->assertSame('no_page_renderer', $result['error']['code']);
    }

    public function test_vision_without_a_model_is_a_named_refusal(): void
    {
        $result = $this->tool(
            $this->ocr(),
            $this->layer(true, 'Scan', 1),
            $this->registry(array(7 => '/srv/scan.pdf')),
            $this->renderer(1),
            $this->router(null)
        )->execute(array('file_id' => 7, 'provider' => 'openai'));

        $this->assertFalse($result['success']);
        $this->assertSame('no_vision_model', $result['error']['code']);
        $this->assertStringContainsString('openai', $result['error']['message']);
    }

    /**
     * `auto` names no engine, so it must not spend money on one: tesseract is
     * free and local, and the vision path is billed per page. This pins the
     * order, because reversing it would be invisible and expensive.
     */
    public function test_auto_keeps_tesseract_first_and_does_not_spend_on_vision(): void
    {
        $ocr = $this->ocr();
        $renderer = $this->renderer(1);
        $router = $this->router($this->client('openai'));

        $result = $this->tool($ocr, $this->layer(true, 'Scan', 1), $this->registry(array(7 => '/srv/scan.pdf')), $renderer, $router)
            ->execute(array('file_id' => 7));

        $this->assertSame('tesseract', $result['data']['ocr']['engine']);
        $this->assertCount(1, $ocr->calls);
        $this->assertSame(array(), $renderer->calls, 'auto must not render a page while a free engine works.');
    }

    public function test_auto_widens_to_vision_when_tesseract_is_absent(): void
    {
        $renderer = $this->renderer(1);
        $router = $this->router($this->client('anthropic'));

        $result = $this->tool(
            $this->ocr(3, false),
            $this->layer(true, 'Scan', 1),
            $this->registry(array(7 => '/srv/scan.pdf')),
            $renderer,
            $router
        )->execute(array('file_id' => 7));

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('vision', $result['data']['ocr']['engine']);
        $this->assertSame('anthropic', $result['data']['ocr']['provider']);
    }

    public function test_a_named_tesseract_does_not_widen_to_vision(): void
    {
        $renderer = $this->renderer(1);
        $router = $this->router($this->client('openai'));

        $result = $this->tool(
            $this->ocr(3, false),
            $this->layer(true, 'Scan', 1),
            $this->registry(array(7 => '/srv/scan.pdf')),
            $renderer,
            $router
        )->execute(array('file_id' => 7, 'provider' => 'tesseract'));

        $this->assertFalse($result['success']);
        $this->assertSame('no_ocr_engine', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls, 'Naming an engine must not silently use another.');
    }

    /**
     * One refused page must not discard the rest: a partial read that names the
     * gap is more useful than an all-or-nothing failure.
     */
    public function test_a_page_the_provider_refused_is_named_not_hidden(): void
    {
        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && isset($value['__refused'])
        );

        $this->errors->method('normalize')->willReturnCallback(
            static fn (mixed $value): array => array('message' => (string) ($value['message'] ?? ''))
        );

        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnOnConsecutiveCalls(
            array('__refused' => true, 'message' => 'quota exceeded'),
            array(
                'model' => 'gpt-4o-mini',
                'choices' => array(array('message' => array('content' => 'Second page.'))),
                'usage' => array('total_tokens' => 12),
            )
        );

        $router = $this->router($client);

        $result = $this->tool($this->ocr(), $this->layer(true, 'Scan', 2), $this->registry(array(7 => '/srv/scan.pdf')), $this->renderer(2), $router)
            ->execute(array('file_id' => 7, 'provider' => 'openai'));

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(array(1), $result['data']['ocr']['failed_pages']);
        $this->assertSame(1, $result['data']['pages_read']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertStringContainsString('Second page.', $result['data']['text']);
    }

    // ─── Gates and limits ──────────────────────────────────────────────────

    public function test_it_requires_a_file_id(): void
    {
        $result = $this->tool()->execute(array());

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_it_refuses_when_no_file_store_is_bound(): void
    {
        $result = $this->tool($this->ocr(), $this->layer(), null)->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_an_unknown_file_is_not_found(): void
    {
        $result = $this->tool($this->ocr(), $this->layer(), $this->registry(array()))
            ->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_budget_over_the_route_ceiling_is_clamped(): void
    {
        $ocr = $this->ocr(500);

        $this->tool($ocr, $this->layer(true, 'scan', 1), $this->registry(array(7 => '/srv/a.pdf')))
            ->execute(array('file_id' => 7, 'max_pages' => 200));

        $this->assertSame(50, $ocr->calls[0][1]['max_pages']);
    }

    public function test_an_engine_failure_is_reported_as_an_error(): void
    {
        $result = $this->tool(
            $this->ocr(3, true, 'the engine died'),
            $this->layer(true, 'scan', 1),
            $this->registry(array(7 => '/srv/a.pdf'))
        )->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('pdf_ocr_failed', $result['error']['code']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(
        ?PdfOcrInterface $ocr = null,
        ?PdfTextExtractorInterface $layers = null,
        ?FileStoreInterface $files = null,
        ?PdfPageRendererInterface $renderer = null,
        ?ProviderRouter $router = null,
    ): ProDocumentOcrTool {
        return new ProDocumentOcrTool(
            $this->errors,
            $ocr ?? $this->ocr(),
            $layers ?? $this->layer(),
            $files,
            $renderer,
            $router,
        );
    }

    /**
     * A router that answers with one client, or with none.
     *
     * The tool builds `VisionPdfOcr` itself, so a test doubles the interface the
     * tool takes rather than the final class it constructs — which also means the
     * page loop these tests exercise is the real one.
     */
    private function router(?AbstractProviderClient $client): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array('openai', 'gemini', 'anthropic', 'ollama'));

        return $router;
    }

    /**
     * A provider that answers each page in turn.
     *
     * @param list<string> $pages One answer per rendered page.
     */
    private function client(string $slug = 'openai', array $pages = array('Page text.')): AbstractProviderClient
    {
        $answers = array();

        foreach ($pages as $text) {
            $answers[] = array(
                'model' => 'model-for-' . $slug,
                'choices' => array(array('message' => array('content' => $text))),
                'usage' => array('prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14),
            );
        }

        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn($slug);
        $client->method('chat')->willReturnOnConsecutiveCalls(...$answers);

        return $client;
    }

    /**
     * A renderer that answers with `$pageCount` one-byte pages.
     */
    private function renderer(int $pageCount, bool $available = true): PdfPageRendererInterface
    {
        return new class ($pageCount, $available) implements PdfPageRendererInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly int $pageCount,
                private readonly bool $available,
            ) {
            }

            public function render(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                $pages = array();

                for ($page = 1; $page <= $this->pageCount; $page++) {
                    $pages[] = array(
                        'page' => $page,
                        'mime_type' => 'image/png',
                        'data' => base64_encode('pixel-' . $page),
                        'bytes' => 7,
                    );
                }

                return array(
                    'pages' => $pages,
                    'total_pages' => $this->pageCount,
                    'truncated' => false,
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
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
     * A text-layer reader that answers from a document shape.
     */
    private function layer(
        bool $available = true,
        string $text = '',
        int $totalPages = 1,
        ?string $failure = null
    ): PdfTextExtractorInterface {
        return new class ($available, $text, $totalPages, $failure) implements PdfTextExtractorInterface {
            /** @var list<array{0: string, 1: int}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly string $text,
                private readonly int $totalPages,
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
                    'text' => $this->text,
                    'pages' => $this->totalPages,
                    'info' => array(),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * An OCR engine that answers from a page count.
     */
    private function ocr(int $totalPages = 3, bool $available = true, ?string $failure = null): PdfOcrInterface
    {
        return new class ($totalPages, $available, $failure) implements PdfOcrInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly int $totalPages,
                private readonly bool $available,
                private readonly ?string $failure,
            ) {
            }

            public function recognize(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                $budget = max(1, (int) ($options['max_pages'] ?? 20));
                $pages = array();

                for ($page = 1; $page <= min($budget, $this->totalPages); $page++) {
                    $pages[] = array('page' => $page, 'text' => 'Page ' . $page, 'confidence' => 90.0);
                }

                return array(
                    'text' => implode("\n\n", array_column($pages, 'text')),
                    'pages' => $pages,
                    'count' => count($pages),
                    'total_pages' => $this->totalPages,
                    'truncated' => count($pages) < $this->totalPages,
                    'dpi' => (int) ($options['dpi'] ?? 300),
                    'language' => (string) ($options['language'] ?? 'eng'),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }
}
