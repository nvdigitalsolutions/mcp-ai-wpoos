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
 * ## The hosted providers are named, not collapsed
 *
 * `openai`, `gemini`, `anthropic`, `ollama`, `unlimited_ocr` and `deepseek_ocr`
 * are refused by name with a reason. Silently treating them as tesseract would
 * return plausible text from the wrong engine, and vision-model OCR of a PDF
 * needs a page renderer this runtime does not expose — which is a future slice,
 * not a rename.
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

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
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
     * Providers the WordPress tool offers that this host does not run.
     *
     * @var list<string>
     */
    private const UNAVAILABLE_PROVIDERS = array(
        'openai',
        'gemini',
        'anthropic',
        'ollama',
        'unlimited_ocr',
        'deepseek_ocr',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfOcrInterface $ocr,
        private readonly PdfTextExtractorInterface $layers,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
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
        return 'Read a stored PDF\'s text, using its text layer when it has one and falling back to OCR when it does not. "source" says which answered, so an exact read is distinguishable from a recognised one. Tesseract is the only engine on this host: the hosted vision providers and the self-hosted Unlimited-OCR and DeepSeek-OCR models are refused by name.';
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
                    'description' => 'OCR engine. "auto" and "tesseract" are the same on this host, which runs tesseract only; the other names are accepted so a migrating caller gets a reason rather than a schema error, and are refused.',
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
                'prefer_text_layer' => array(
                    'type' => 'boolean',
                    'description' => 'Read the text layer when it has content, instead of OCRing a born-digital page. Default true; set false to force OCR.',
                    'default' => true,
                ),
                'dpi' => array(
                    'type' => 'integer',
                    'description' => 'Rasterisation resolution when OCR runs. Default ' . self::DEFAULT_DPI . '.',
                    'minimum' => 150,
                    'maximum' => 600,
                    'default' => self::DEFAULT_DPI,
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

        if (in_array($provider, self::UNAVAILABLE_PROVIDERS, true)) {
            return $this->errors->create(
                'ocr_provider_unavailable',
                sprintf(
                    'The "%s" provider is not available on this host; it runs tesseract only. Vision-model OCR needs a PDF page renderer this runtime does not expose, and the self-hosted vLLM models are absent. Ask for "tesseract" or "auto".',
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

        if (!$this->ocr->isAvailable()) {
            return $this->errors->create(
                'no_ocr_engine',
                'The PDF text layer did not contain enough text to return, and OCR is not available on this host. The media worker must be configured and advertise the pdf_ocr capability.',
            );
        }

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
                    'dpi' => (int) ($result['dpi'] ?? self::DEFAULT_DPI),
                    'language' => (string) ($result['language'] ?? 'eng'),
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
