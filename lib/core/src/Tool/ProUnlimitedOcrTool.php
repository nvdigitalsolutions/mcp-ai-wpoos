<?php

/**
 * Read a whole scanned PDF, one page window at a time.
 *
 * Port of `WP_MCP_AI_Tool_Pro_Unlimited_OCR` under Proposal 164 §164.40.
 *
 * ## "Unlimited" describes the document, not the call
 *
 * The WordPress tool runs Baidu Unlimited-OCR or DeepSeek-OCR on a self-hosted
 * vLLM box and parses dozens of pages in one forward pass. This host has no such
 * engine. What it does have is the tesseract ladder, and the worker caps it:
 * `clampPageBudget()` hard-limits a request to 50 pages, and OCR runs at seconds
 * per page behind a single request timeout.
 *
 * A tool that offered "500 pages in one call" would therefore be
 * accepted-then-ignored — the worker would silently read 50. So the ceiling is
 * the worker's real one, and the whole document is reached the only honest way:
 * read a window, report `next_page`, read the next.
 *
 * That is a genuinely different tool from `ocr_pdf_text`, which reads one slice
 * and returns every page it read. This one is shaped for the long document: it
 * reports where to continue and omits the per-page array unless asked, so a
 * 600-page scan does not return a payload of 600 records nobody reads.
 *
 * ## What is not offered
 *
 * No `model_type`, `image_mode`, `output_format`, `preserve_layout` or
 * `extract_tables`. Those describe the vLLM models' structured output — the
 * `<|det|>` block markers, gundam tiling, bounding boxes — and tesseract
 * produces none of them. Offering them would be the accepted-then-ignored
 * defect with a vendor's name on it.
 *
 * ## Why there is no `truncated` field
 *
 * The route's `truncated` means "this response is not the whole document",
 * which is true of *every* window of a long document, including the last one.
 * Beside `complete` it would read as a contradiction. So this tool reports
 * `next_page` and `complete` instead: one truth, in the caller's terms.
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
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ProUnlimitedOcrTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Rasterisation resolution: the published working default for OCR.
     */
    public const DEFAULT_DPI = 300;

    /**
     * Pages per call. This is the worker's ceiling, not a preference.
     *
     * `clampPageBudget( value, 20, 50 )` means a larger number is silently
     * reduced, so the schema says 50 rather than promising something the route
     * cannot honour.
     */
    public const MAX_PAGES_PER_CALL = 50;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfOcrInterface $ocr,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'pro_unlimited_ocr';
    }

    public function getName(): string
    {
        return 'Pro Unlimited OCR';
    }

    public function getDescription(): string
    {
        return 'Read a whole scanned PDF across successive calls: each call recognises a page window and returns the page to continue from. "Unlimited" describes the document, not one call — the OCR route reads at most ' . self::MAX_PAGES_PER_CALL . ' pages per request, so a longer document is finished by following next_page. Runs the tesseract ladder; the self-hosted Unlimited-OCR and DeepSeek-OCR models are not available on this host, so no provider choice is offered.';
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
                'start_page' => array(
                    'type' => 'integer',
                    'description' => 'First page of this window, 1-based. Pass the previous response\'s next_page to continue.',
                    'minimum' => 1,
                    'default' => 1,
                ),
                'max_pages' => array(
                    'type' => 'integer',
                    'description' => 'Pages in this call. Default and ceiling ' . self::MAX_PAGES_PER_CALL . ' — the OCR route clamps to it.',
                    'minimum' => 1,
                    'maximum' => self::MAX_PAGES_PER_CALL,
                    'default' => self::MAX_PAGES_PER_CALL,
                ),
                'dpi' => array(
                    'type' => 'integer',
                    'description' => 'Rasterisation resolution. Default ' . self::DEFAULT_DPI . '; below 200 accuracy drops sharply, above 600 engines hit pixel caps.',
                    'minimum' => 150,
                    'maximum' => 600,
                    'default' => self::DEFAULT_DPI,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Tesseract traineddata code, e.g. "eng" or "deu". Default eng.',
                ),
                'include_pages' => array(
                    'type' => 'boolean',
                    'description' => 'Also return the per-page array. Off by default: a long document\'s per-page records are usually read once and discarded.',
                    'default' => false,
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

        if (!$this->ocr->isAvailable()) {
            return $this->errors->create(
                'no_ocr_engine',
                'OCR is not available on this host. The media worker must be configured and advertise the pdf_ocr capability.',
            );
        }

        $startPage = max(1, $this->intParam($arguments, 'start_page', 1));
        $budget = $this->intParam($arguments, 'max_pages', self::MAX_PAGES_PER_CALL);
        $budget = min(self::MAX_PAGES_PER_CALL, max(1, $budget));

        $options = array(
            'dpi' => $this->intParam($arguments, 'dpi', self::DEFAULT_DPI),
            'max_pages' => $budget,
            'language' => $this->stringParam($arguments, 'language', 'eng'),
        );

        // Only the resumed case needs an explicit page list; from page one the
        // budget alone says the same thing and sends a smaller request.
        if ($startPage > 1) {
            $options['pages'] = range($startPage, $startPage + $budget - 1);
        }

        try {
            $result = $this->ocr->recognize($path, $options);
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_ocr_failed', $e->getMessage());
        }

        $read = (array) ($result['pages'] ?? array());
        $lastPage = 0;

        foreach ($read as $page) {
            $lastPage = max($lastPage, (int) ($page['page'] ?? 0));
        }

        $totalPages = (int) ($result['total_pages'] ?? 0);

        // Where the next window starts. Derived from what was actually read, not
        // from the budget: a failure part-way through a window must not tell the
        // caller to skip the pages it did not get.
        $nextPage = null;

        if ($totalPages > 0 && $lastPage > 0 && $lastPage < $totalPages) {
            $nextPage = $lastPage + 1;
        } elseif ($totalPages > 0 && 0 === $lastPage && $startPage <= $totalPages) {
            $nextPage = $startPage;
        }

        $payload = array(
            'file_id' => $fileId,
            'text' => (string) ($result['text'] ?? ''),
            'characters' => strlen((string) ($result['text'] ?? '')),
            'start_page' => $startPage,
            'pages_read' => count($read),
            'total_pages' => $totalPages,
            'next_page' => $nextPage,
            'complete' => null === $nextPage,
            'dpi' => (int) ($result['dpi'] ?? $options['dpi']),
            'language' => (string) ($result['language'] ?? $options['language']),
        );

        if ($this->boolParam($arguments, 'include_pages', false)) {
            $payload['pages'] = $read;
        }

        return $this->success($this->summarise($startPage, count($read), $totalPages, $nextPage), $payload);
    }

    /**
     * Say what happened in one line, including the empty and finished cases.
     */
    private function summarise(int $startPage, int $read, int $totalPages, ?int $nextPage): string
    {
        if ($totalPages > 0 && $startPage > $totalPages) {
            return sprintf(
                'The document has %d page(s); page %d is past its end, so nothing was read.',
                $totalPages,
                $startPage,
            );
        }

        if (0 === $read) {
            return 'No pages were recognised from page ' . $startPage . '.';
        }

        if (null === $nextPage) {
            return sprintf('Read %d page(s) from page %d; the document is complete.', $read, $startPage);
        }

        return sprintf(
            'Read %d page(s) from page %d of %d; continue from page %d.',
            $read,
            $startPage,
            $totalPages,
            $nextPage,
        );
    }
}
