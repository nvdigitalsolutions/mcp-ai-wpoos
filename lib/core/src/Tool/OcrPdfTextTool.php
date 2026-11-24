<?php

/**
 * OCR a stored PDF page by page.
 *
 * Proposal 164 §164.7. Separate from `extract_pdf_text` because the cost and the
 * failure mode are different: extraction reads a text layer that may be absent
 * and is effectively free, while OCR rasterises every page at print resolution
 * and spends seconds per page. A scanned contract is exactly the file where the
 * distinction matters, and a caller should choose it deliberately.
 *
 * The page budget and DPI are exposed rather than fixed because they are the
 * two knobs that decide whether a call finishes: 300 DPI is the working default
 * (below ~200 accuracy falls off sharply, above 600 engines hit pixel caps), and
 * 20 pages is a request that returns rather than times out. A truncated answer
 * says so and reports the document's page count, so a caller can loop.
 *
 * @package Nvoos\Core
 * @since   2.4.0
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

class OcrPdfTextTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Pages per call before the answer is truncated.
     */
    public const DEFAULT_MAX_PAGES = 20;

    /**
     * Rasterisation resolution: the published working default for OCR.
     */
    public const DEFAULT_DPI = 300;

    /**
     * @param ErrorFactoryInterface $e     Error factory.
     * @param PdfOcrInterface       $ocr   The bound OCR engine.
     * @param FileStoreInterface|null $files Optional: resolve `file_id` to a path.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfOcrInterface $ocr,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'ocr_pdf_text';
    }

    public function getName(): string
    {
        return 'OCR PDF Text';
    }

    public function getDescription(): string
    {
        return 'Read the text of a scanned PDF by rendering its pages and running OCR on them. Slower and more expensive than extract_pdf_text; use it when the document has no text layer. Returns per-page text and confidences.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the PDF to OCR.',
                    'minimum' => 1,
                ),
                'dpi' => array(
                    'type' => 'integer',
                    'description' => 'Rasterisation resolution. Default 300; below 200 accuracy drops sharply, above 600 engines hit pixel caps.',
                    'minimum' => 150,
                    'maximum' => 600,
                    'default' => self::DEFAULT_DPI,
                ),
                'max_pages' => array(
                    'type' => 'integer',
                    'description' => 'Pages to read in this call. Default 20; the response reports total_pages and truncated.',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => self::DEFAULT_MAX_PAGES,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Tesseract traineddata code, e.g. "eng" or "deu". Default eng.',
                ),
                'pages' => array(
                    'type' => 'array',
                    'description' => 'Explicit 1-based page numbers to read. Omit for the first max_pages pages.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            'required' => array('file_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * Read-only, but deliberately **not** `local-only`: the pages leave the host
     * for the worker, and OCR may be a paid provider on some deployments.
     *
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
                'OCR is not available on this host. The media worker must be configured and advertise the pdf_ocr capability (older workers do not have the route).',
            );
        }

        try {
            $result = $this->ocr->recognize(
                $path,
                array(
                    'dpi' => $this->intParam($arguments, 'dpi', self::DEFAULT_DPI),
                    'max_pages' => $this->intParam($arguments, 'max_pages', self::DEFAULT_MAX_PAGES),
                    'language' => $this->stringParam($arguments, 'language', 'eng'),
                    'pages' => $this->intListParam($arguments, 'pages'),
                )
            );

            return $this->success(
                'PDF OCR complete.',
                array(
                    'file_id' => $fileId,
                    'text' => $result['text'],
                    'characters' => \strlen($result['text']),
                    'pages' => $result['pages'],
                    'count' => $result['count'],
                    'total_pages' => $result['total_pages'],
                    'truncated' => $result['truncated'],
                    'dpi' => $result['dpi'],
                    'language' => $result['language'],
                ),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_ocr_failed', $e->getMessage());
        }
    }
}
