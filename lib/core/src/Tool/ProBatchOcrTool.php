<?php

/**
 * OCR several stored documents in one call.
 *
 * Port of `WP_MCP_AI_Tool_Pro_Batch_OCR` under Proposal 164 §164.39.
 *
 * ## No worker change was needed
 *
 * §164.38 said the OCR family was "worker first". Reading the contract showed that
 * was wrong: `PdfOcrInterface::recognize()` already takes `dpi`, `max_pages`,
 * `language` and `pages`, and the worker's `/api/pdf/ocr` route is the
 * extract → render → recognise ladder with a page budget. A batch is a loop over
 * an engine that is already there, so the loop belongs in the tool and the worker
 * stays as it is.
 *
 * ## A failing file does not lose the batch
 *
 * `continue_on_error` defaults to **true**. A batch of twenty documents that
 * aborts on the third has cost the caller nineteen OCR runs and returned nothing,
 * and OCR is the expensive part of this toolkit. Each result therefore carries its
 * own `ok` and `error`, and the summary counts what actually happened.
 *
 * `continue_on_error: false` is available for a caller that would rather stop —
 * for example one treating the batch as one unit of work.
 *
 * ## The vendor providers are named, not silently substituted
 *
 * The WordPress tool offers `unlimited_ocr` and `deepseek_ocr`. Neither exists
 * here. Rather than quietly running tesseract under a vendor's name — which would
 * let a caller believe it got a service it did not — this refuses those names with
 * an error that says what the host actually runs.
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

class ProBatchOcrTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The largest batch accepted in one call.
     *
     * OCR is the slowest operation in the toolkit, so a batch cap is a timeout
     * guard as much as a courtesy.
     */
    public const MAX_FILES = 25;

    /**
     * Rasterisation resolution: the published working default for OCR.
     *
     * Mirrors `OcrPdfTextTool::DEFAULT_DPI` rather than sharing a constant, so
     * either tool can be read on its own; the value itself is the one
     * `PdfOcrInterface` documents.
     */
    public const DEFAULT_DPI = 300;

    /**
     * Pages read per file before the engine truncates.
     */
    public const DEFAULT_MAX_PAGES = 20;

    /**
     * Providers the WordPress tool names that this host does not run.
     *
     * Refused by name rather than remapped: a caller that asked for DeepSeek-OCR
     * and silently received tesseract has been told something untrue about its own
     * records.
     *
     * @var list<string>
     */
    private const UNAVAILABLE_PROVIDERS = array('unlimited_ocr', 'deepseek_ocr');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfOcrInterface $ocr,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'pro_batch_ocr';
    }

    public function getName(): string
    {
        return 'Pro Batch OCR';
    }

    public function getDescription(): string
    {
        return 'Run OCR over several stored PDFs in one call, returning per-file text and page counts. A failing file does not abort the batch unless asked to.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids of the PDFs to OCR. Up to ' . self::MAX_FILES . ' per call.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                    'minItems' => 1,
                    'maxItems' => self::MAX_FILES,
                ),
                'provider' => array(
                    'type' => 'string',
                    'description' => 'OCR engine. "tesseract" runs on this host; "unlimited_ocr" and "deepseek_ocr" are refused because this host does not run them.',
                    'enum' => array('tesseract', 'unlimited_ocr', 'deepseek_ocr'),
                ),
                'dpi' => array(
                    'type' => 'integer',
                    'description' => 'Rasterisation resolution for every file. Default ' . self::DEFAULT_DPI . '; below 200 or above 600 engines hit pixel caps.',
                    'minimum' => 150,
                    'maximum' => 600,
                ),
                'max_pages' => array(
                    'type' => 'integer',
                    'description' => 'Pages per file. Default ' . self::DEFAULT_MAX_PAGES . '. The result reports total_pages and whether it truncated.',
                    'minimum' => 1,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Tesseract language pack, e.g. "eng" or "deu".',
                ),
                'continue_on_error' => array(
                    'type' => 'boolean',
                    'description' => 'When true (the default) a failing file is reported and the batch continues.',
                    'default' => true,
                ),
            ),
            'required' => array('file_ids'),
            'additionalProperties' => false,
        );
    }

    /**
     * OCR reads stored documents and writes nothing.
     *
     * `read` matches `ocr_pdf_text`: the batch is several reads, not a write.
     */
    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * Read-only, but expensive: OCR consumes CPU on the worker.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $fileIds = $this->intListParam($arguments, 'file_ids');

        if (array() === $fileIds) {
            return $this->errors->validationFailed(
                'At least one stored file id is required.',
                array('file_ids' => array('Give one or more ids of stored PDFs.')),
            );
        }

        if (count($fileIds) > self::MAX_FILES) {
            return $this->errors->validationFailed(
                sprintf('At most %d files can be OCRed in one call.', self::MAX_FILES),
                array('file_ids' => array('Split the batch.')),
            );
        }

        $provider = strtolower($this->stringParam($arguments, 'provider', 'tesseract'));

        if (in_array($provider, self::UNAVAILABLE_PROVIDERS, true)) {
            return $this->errors->create(
                'ocr_provider_unavailable',
                sprintf('The "%s" provider is not available on this host; it runs tesseract only. Ask for "tesseract", or configure the provider you need.', $provider),
                array('provider' => $provider),
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so file ids cannot be resolved.',
            );
        }

        if (!$this->ocr->isAvailable()) {
            return $this->errors->create(
                'no_ocr_engine',
                'OCR is not available on this host. The media worker must be configured and advertise the pdf_ocr capability.',
            );
        }

        $continue = $this->boolParam($arguments, 'continue_on_error', true);
        $options = array(
            'dpi' => $this->intParam($arguments, 'dpi', self::DEFAULT_DPI),
            'max_pages' => $this->intParam($arguments, 'max_pages', self::DEFAULT_MAX_PAGES),
            'language' => $this->stringParam($arguments, 'language', 'eng'),
        );

        $results = array();
        $failed = 0;
        $pages = 0;

        foreach ($fileIds as $fileId) {
            try {
                $path = $this->files->getPath($fileId);

                if (null === $path || '' === $path) {
                    throw new \RuntimeException('There is no stored file with id ' . $fileId . '.');
                }

                $result = $this->ocr->recognize($path, $options);

                $pages += (int) ($result['total_pages'] ?? count((array) ($result['pages'] ?? array())));

                $results[] = array(
                    'file_id' => $fileId,
                    'ok' => true,
                    'text' => (string) ($result['text'] ?? ''),
                    'text_length' => strlen((string) ($result['text'] ?? '')),
                    'pages' => count((array) ($result['pages'] ?? array())),
                    'total_pages' => (int) ($result['total_pages'] ?? 0),
                    'truncated' => (bool) ($result['truncated'] ?? false),
                );
            } catch (\Throwable $e) {
                $failed++;
                $results[] = array(
                    'file_id' => $fileId,
                    'ok' => false,
                    'error' => $e->getMessage(),
                );

                if (!$continue) {
                    break;
                }
            }
        }

        $ocr = count($results) - $failed;

        return $this->success(
            sprintf(
                'OCRed %d of %d file(s)%s.',
                $ocr,
                count($fileIds),
                $failed > 0 ? sprintf('; %d failed', $failed) : '',
            ),
            array(
                'provider' => 'tesseract',
                'results' => $results,
                'processed' => $ocr,
                'failed' => $failed,
                'requested' => count($fileIds),
                'total_pages' => $pages,
                'stopped_early' => !$continue && $failed > 0,
            ),
        );
    }
}
