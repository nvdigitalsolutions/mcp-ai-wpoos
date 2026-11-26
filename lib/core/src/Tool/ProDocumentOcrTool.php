<?php

/**
 * Read a document's text, preferring its text layer and falling back to OCR.
 *
 * Port of `WP_MCP_AI_Tool_Pro_Document_OCR` under Proposal 164 §164.40.
 *
 * ## The ladder is the port
 *
 * The WordPress tool dispatches `auto` across OpenAI, Gemini, Anthropic,
 * Ollama, tesseract and two self-hosted vLLM models, with a circuit breaker and
 * a fallback chain. This host has exactly one of those — the tesseract route on
 * the media worker — so a provider chain would be six names that all resolve to
 * the seventh.
 *
 * What is worth porting is the part that is a *decision* rather than a
 * dispatch: **a PDF with a text layer should never be OCRed.** Reading the text
 * layer is effectively free and exact; rendering and recognising is seconds per
 * page and probabilistic. Published practice says to check for extractable text
 * first and reach for OCR only on scans, and this tool does that automatically
 * instead of making the caller choose between `extract_pdf_text` and
 * `ocr_pdf_text` and know which they need.
 *
 * `source` in the response says which rung answered, so a caller can tell an
 * exact read from a recognised one.
 *
 * ## Both engines live behind one ladder
 *
 * `openai`, `gemini`, `anthropic` and `ollama` are vision providers: the page is
 * rendered to an image and transcribed by the model, through `VisionPdfOcr`.
 * `tesseract` is the worker's OCR route. A named engine is honoured; if that
 * engine cannot run, the refusal says *which* thing is missing
 * (`no_page_renderer`, `no_vision_model`) rather than collapsing to a shrug.
 *
 * `auto` means "the cheapest engine that works": the text layer first, then
 * tesseract, then a vision model. That order is deliberate and differs from the
 * WordPress tool, which tries vision models first. Vision is billed per page and
 * tesseract is free and local, so a caller who did not name an engine should not
 * be spending money for it — the named providers exist for the caller who wants
 * the better model, and they are one argument away.
 *
 * `unlimited_ocr` and `deepseek_ocr` remain refused by name. They are
 * self-hosted vLLM instances this runtime has no address for; routing them to a
 * hosted provider would answer under a name that did not answer.
 *
 * ## OCR unavailability is only an error when OCR is needed
 *
 * A born-digital PDF on a host with no OCR engine still returns its text. The
 * `no_ocr_engine` refusal is raised only after the text layer has been tried and
 * found wanting.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\VisionPdfOcr;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ProDocumentOcrTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Rasterisation resolution: the published working default for OCR.
     */
    public const DEFAULT_DPI = 300;

    /**
     * Pages read when the caller does not say. Smaller than the route's ceiling
     * because OCR is seconds per page and this is the general-purpose reader.
     */
    public const DEFAULT_MAX_PAGES = 20;

    /**
     * The OCR route's ceiling (`clampPageBudget( value, 20, 50 )`).
     */
    public const MAX_PAGES = 50;

    /**
     * Characters per page below which a text layer is treated as absent.
     *
     * A scanned page typically carries a handful of characters at most — a
     * stamp, a header, the scanner's own watermark — while a born-digital page
     * carries hundreds. Thirty-two sits well between the two and is a constant
     * rather than a parameter so callers do not have to tune it per document.
     */
    public const MIN_TEXT_LAYER_CHARS_PER_PAGE = 32;

    /**
     * Provider names that mean "transcribe the page with this model".
     *
     * @var list<string>
     */
    private const VISION_PROVIDERS = array(
        'openai',
        'gemini',
        'anthropic',
        'ollama',
    );

    /**
     * Engines this host has no address for.
     *
     * @var list<string>
     */
    private const SELF_HOSTED_PROVIDERS = array(
        'unlimited_ocr',
        'deepseek_ocr',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfOcrInterface $ocr,
        private readonly PdfTextExtractorInterface $layers,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?PdfPageRendererInterface $renderer = null,
        private readonly ?ProviderRouter $providers = null,
    ) {
        parent::__construct($e);
    }

    /**
     * The vision engine, assembled here rather than injected.
     *
     * `VisionPdfOcr` is final and has dependencies of its own, so the tool takes
     * the two interfaces and builds it — the same seam `AnalyzeImageVisionTool`
     * uses for `ImageInput`. It also keeps this tool's constructor readable to
     * the test harness, which can double an interface but not a final class.
     */
    private function vision(): VisionPdfOcr
    {
        return new VisionPdfOcr($this->errors, $this->renderer, $this->providers);
    }

    public function getSlug(): string
    {
        return 'pro_document_ocr';
    }

    public function getName(): string
    {
        return 'Pro Document OCR';
    }

    public function getDescription(): string
    {
        return 'Read a stored PDF\'s text, using its text layer when it has one and falling back to OCR when it does not. "source" says which answered, so an exact read is distinguishable from a recognised one. Engines: "auto" (text layer, then tesseract, then a vision model), "tesseract" on the media worker, or a vision provider (openai, gemini, anthropic, ollama) that transcribes each rendered page. The self-hosted Unlimited-OCR and DeepSeek-OCR models are refused by name.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the PDF to read.',
                    'minimum' => 1,
                ),
                'provider' => array(
                    'type' => 'string',
                    'description' => '"auto" reads the text layer, then falls back to tesseract, then to a vision provider. Name an engine to force it: "tesseract" runs on the media worker; "openai", "gemini", "anthropic" and "ollama" render each page and transcribe it with that provider, which is billed per page. "unlimited_ocr" and "deepseek_ocr" are refused because this host has no address for them.',
                    'enum' => array(
                        'auto',
                        'tesseract',
                        'openai',
                        'gemini',
                        'anthropic',
                        'ollama',
                        'unlimited_ocr',
                        'deepseek_ocr',
                    ),
                    'default' => 'auto',
                ),
                'model' => array(
                    'type' => 'string',
                    'description' => 'Model id for a vision provider. Defaults to the provider\'s default.',
                    'maxLength' => 120,
                ),
                'prefer_text_layer' => array(
                    'type' => 'boolean',
                    'description' => 'Read the text layer when it has content, instead of OCRing a born-digital page. Default true; set false to force OCR.',
                    'default' => true,
                ),
                'dpi' => array(
                    'type' => 'integer',
                    'description' => 'Rasterisation resolution. Tesseract defaults to ' . self::DEFAULT_DPI . '; a vision provider defaults to ' . PdfPageRendererInterface::DEFAULT_DPI . ' because models downsample well below 300 DPI and every extra pixel is billed. Omit to take the engine default.',
                    'minimum' => 150,
                    'maximum' => 600,
                ),
                'max_pages' => array(
                    'type' => 'integer',
                    'description' => 'Pages to read. Default ' . self::DEFAULT_MAX_PAGES . '; the OCR route clamps to ' . self::MAX_PAGES . '. The response reports total_pages and truncated.',
                    'minimum' => 1,
                    'maximum' => self::MAX_PAGES,
                    'default' => self::DEFAULT_MAX_PAGES,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Tesseract traineddata code when OCR runs, e.g. "eng" or "deu". Default eng.',
                ),
            ),
            'required' => array('file_id'),
            'additionalProperties' => false,
        );
    }

    /**
     * Reading a stored document is a read.
     */
    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId <= 0) {
            return $this->errors->validationFailed(
                'A stored file id is required.',
                array('file_id' => array('Give the id of a stored PDF.')),
            );
        }

        $provider = strtolower($this->stringParam($arguments, 'provider', 'auto'));

        if (in_array($provider, self::SELF_HOSTED_PROVIDERS, true)) {
            return $this->errors->create(
                'ocr_provider_unavailable',
                sprintf(
                    'The "%s" provider is not available on this host: it needs a self-hosted vLLM instance this runtime has no address for. Ask for "tesseract", "auto", or a vision provider (openai, gemini, anthropic, ollama).',
                    $provider,
                ),
                array('provider' => $provider),
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so a file id cannot be resolved.',
            );
        }

        $path = $this->files->getPath($fileId);

        if (null === $path || '' === $path) {
            return $this->errors->notFound('There is no stored file with id ' . $fileId . '.');
        }

        $maxPages = min(self::MAX_PAGES, max(1, $this->intParam($arguments, 'max_pages', self::DEFAULT_MAX_PAGES)));

        if ($this->boolParam($arguments, 'prefer_text_layer', true)) {
            $layer = $this->readTextLayer($path, $maxPages);

            if (null !== $layer && $layer['sufficient']) {
                return $this->success(
                    sprintf(
                        'Read %d page(s) from the PDF text layer; OCR was not needed.',
                        $layer['pages_read'],
                    ),
                    array(
                        'file_id' => $fileId,
                        'source' => 'text_layer',
                        'text' => $layer['text'],
                        'characters' => strlen($layer['text']),
                        'pages_read' => $layer['pages_read'],
                        'total_pages' => $layer['total_pages'],
                        'truncated' => $layer['pages_read'] < $layer['total_pages'],
                        'ocr' => null,
                    ),
                );
            }
        }

        if (in_array($provider, self::VISION_PROVIDERS, true)) {
            return $this->recogniseWithVision($path, $fileId, $provider, $maxPages, $arguments);
        }

        if ($this->ocr->isAvailable()) {
            return $this->recogniseWithTesseract($path, $fileId, $maxPages, $arguments);
        }

        // `auto` widens one rung when tesseract is absent: a host with a page
        // renderer and a provider can still read the scan. A caller who named
        // `tesseract` does not, because naming an engine is asking for that
        // engine and the refusal below says so.
        if (
'auto' === $provider
            && null === $this->vision()->unavailableReason($provider)
        ) {
            return $this->recogniseWithVision($path, $fileId, $provider, $maxPages, $arguments);
        }

        return $this->errors->create(
            'no_ocr_engine',
            'The PDF text layer did not contain enough text to return, and no OCR engine is available on this host. Tesseract needs the media worker to advertise the pdf_ocr capability; a vision provider needs a usable PDF page renderer (pdf_render_inline) and a registered provider.',
        );
    }

    /**
     * Recognise the pages on the worker.
     *
     * @param array<string, mixed> $arguments
     */
    private function recogniseWithTesseract(string $path, int $fileId, int $maxPages, array $arguments): mixed
    {
        try {
            $result = $this->ocr->recognize(
                $path,
                array(
                    'dpi' => $this->intParam($arguments, 'dpi', self::DEFAULT_DPI),
                    'max_pages' => $maxPages,
                    'language' => $this->stringParam($arguments, 'language', 'eng'),
                ),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_ocr_failed', $e->getMessage());
        }

        $text = (string) ($result['text'] ?? '');

        return $this->success(
            'Recognised the PDF with OCR.',
            array(
                'file_id' => $fileId,
                'source' => 'ocr',
                'text' => $text,
                'characters' => strlen($text),
                'pages_read' => count((array) ($result['pages'] ?? array())),
                'total_pages' => (int) ($result['total_pages'] ?? 0),
                'truncated' => (bool) ($result['truncated'] ?? false),
                'ocr' => array(
                    'engine' => 'tesseract',
                    'dpi' => (int) ($result['dpi'] ?? self::DEFAULT_DPI),
                    'language' => (string) ($result['language'] ?? 'eng'),
                ),
            ),
        );
    }

    /**
     * Recognise the pages with a vision model.
     *
     * The two refusals are separate on purpose. A caller reading
     * `no_page_renderer` has a media worker to fix; a caller reading
     * `no_vision_model` has a provider to configure. One message covering both
     * would send half the callers to the wrong panel.
     *
     * @param array<string, mixed> $arguments
     */
    private function recogniseWithVision(
        string $path,
        int $fileId,
        string $provider,
        int $maxPages,
        array $arguments,
    ): mixed {
        $vision = $this->vision();
        $reason = $vision->unavailableReason($provider);

        if (VisionPdfOcr::NO_PAGE_RENDERER === $reason) {
            return $this->errors->create(
                $reason,
                'Vision-model OCR needs page images, and this host has no usable PDF page renderer. The media worker must be configured and advertise the pdf_render_inline capability.',
            );
        }

        if (null !== $reason) {
            $named = 'auto' === $provider ? '' : $provider;

            return $this->errors->create(
                $reason,
                '' === $named
                    ? 'No vision provider is configured on this host, so the scan cannot be recognised.'
                    : sprintf('No vision provider answers to "%s" on this host.', $named),
                '' === $named ? array() : array('provider' => $named),
            );
        }

        $options = array(
            'provider' => 'auto' === $provider ? '' : $provider,
            'max_pages' => $maxPages,
        );

        // Only forward what the caller named, so the renderer's own defaults
        // stand rather than being overwritten by the schema's convenience values.
        if (array_key_exists('model', $arguments)) {
            $options['model'] = mb_substr($this->stringParam($arguments, 'model'), 0, 120);
        }

        if (array_key_exists('dpi', $arguments)) {
            $options['dpi'] = $this->intParam($arguments, 'dpi', PdfPageRendererInterface::DEFAULT_DPI);
        }

        try {
            $result = $vision->recognize($path, $options);
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_ocr_failed', $e->getMessage());
        }

        $text = (string) ($result['text'] ?? '');

        return $this->success(
            sprintf('Recognised %d page(s) of the PDF with %s.', (int) ($result['count'] ?? 0), (string) ($result['provider'] ?? '')),
            array(
                'file_id' => $fileId,
                'source' => 'ocr',
                'text' => $text,
                'characters' => strlen($text),
                'pages_read' => (int) ($result['count'] ?? 0),
                'total_pages' => (int) ($result['total_pages'] ?? 0),
                'truncated' => (bool) ($result['truncated'] ?? false),
                'ocr' => array(
                    'engine' => 'vision',
                    'dpi' => (int) ($result['dpi'] ?? PdfPageRendererInterface::DEFAULT_DPI),
                    'provider' => (string) ($result['provider'] ?? ''),
                    'model' => (string) ($result['model'] ?? ''),
                    'usage' => (array) ($result['usage'] ?? array()),
                    'failed_pages' => (array) ($result['failed_pages'] ?? array()),
                ),
            ),
        );
    }

    /**
     * Read the text layer and judge whether it is enough to skip OCR.
     *
     * A failure here is not a failure of the tool: the ladder's next rung is
     * OCR, and a document whose text layer cannot be read is precisely the case
     * OCR exists for. So the exception is swallowed and `null` means "try the
     * next rung" — but an unavailable extractor is also `null`, which keeps the
     * two indistinguishable on purpose: in both cases the answer is to OCR.
     *
     * @return array{text: string, pages_read: int, total_pages: int, sufficient: bool}|null
     */
    private function readTextLayer(string $path, int $maxPages): ?array
    {
        if (!$this->layers->isAvailable()) {
            return null;
        }

        try {
            $layer = $this->layers->extract($path, $maxPages);
        } catch (\Throwable $e) {
            return null;
        }

        $text = (string) ($layer['text'] ?? '');
        $total = (int) ($layer['pages'] ?? 0);

        // `pages` is the document's page count, not pages read, so the pages
        // actually read are the smaller of the budget and the document.
        $read = $total > 0 ? min($maxPages, $total) : $maxPages;
        $read = max(1, $read);

        return array(
            'text' => $text,
            'pages_read' => $read,
            'total_pages' => $total > 0 ? $total : $read,
            'sufficient' => strlen($text) >= self::MIN_TEXT_LAYER_CHARS_PER_PAGE * $read,
        );
    }
}
