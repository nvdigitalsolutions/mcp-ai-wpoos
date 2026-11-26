<?php

/**
 * Extract the text of a stored PDF.
 *
 * Proposal 164 §164.4.3. The WordPress tool accepts an attachment id, a provider
 * file id and a URL, and falls back to OCR for scanned documents. This port
 * accepts a **stored file id** only: it is the one entry point that resolves to
 * bytes on this host through an authorisation check (`FileStoreInterface`), and
 * the OCR fallback is its own slice with its own providers and cost (§164.7).
 *
 * A scanned PDF therefore returns its (empty) text layer rather than silently
 * becoming a paid vision call — the honest failure is an empty string and a page
 * count, not a bill.
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
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ExtractPdfTextTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface    $e         Error factory.
     * @param PdfTextExtractorInterface $extractor The bound extractor.
     * @param FileStoreInterface|null  $files     Optional: resolve `file_id` to a path.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfTextExtractorInterface $extractor,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'extract_pdf_text';
    }

    public function getName(): string
    {
        return 'Extract PDF Text';
    }

    public function getDescription(): string
    {
        return 'Read the text layer of a stored PDF and return it with the page count. Scanned documents with no text layer return an empty string rather than triggering OCR.';
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
                'max_pages' => array(
                    'type' => 'integer',
                    'description' => 'Stop after this many pages. Omit to read the whole document.',
                    'minimum' => 1,
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
     * Read-only, and deliberately without `local-only`: when the worker answers,
     * the document leaves this host.
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

        if (!$this->extractor->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF engine is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        try {
            $result = $this->extractor->extract($path, $this->intParam($arguments, 'max_pages'));

            return $this->success(
                'Text extracted.',
                array(
                    'file_id' => $fileId,
                    'text' => $result['text'],
                    'pages' => $result['pages'],
                    'characters' => \strlen($result['text']),
                    'info' => $result['info'],
                ),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_extract_failed', $e->getMessage());
        }
    }
}
