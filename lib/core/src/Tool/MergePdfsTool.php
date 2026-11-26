<?php

/**
 * Merge stored PDFs into one, in the order given.
 *
 * Proposal 164 §164.4.3. The WordPress tool takes `attachment_ids` or `urls`;
 * this port takes `file_ids`, because a stored file id is the only input that
 * resolves to bytes on this host with an authorisation check. The order is the
 * caller's, and the page count is returned so a caller can prove nothing was
 * dropped — a merge that silently lost a page is the failure worth catching.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\PdfMutatorInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class MergePdfsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface             $e     Error factory.
     * @param PdfMutatorInterface               $pdf   The bound PDF engine.
     * @param FileStoreInterface|null           $files Optional: resolve `file_ids` to paths.
     * @param FileVisibilityStoreInterface|null $store Optional: persist the merged file.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PdfMutatorInterface $pdf,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'merge_pdfs';
    }

    public function getName(): string
    {
        return 'Merge PDFs';
    }

    public function getDescription(): string
    {
        return 'Concatenate two or more stored PDFs into one document, in the order given, and return the merged file with its page count.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids of the PDFs, in the order they should appear. The WordPress tool calls these attachment_ids.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                    'minItems' => 2,
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Title for the merged document, used for the default filename.',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to the title, then "merged-document".',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('file_ids'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $ids = $this->intListParam($arguments, 'file_ids');

        if (\count($ids) < 2) {
            return $this->errors->validationFailed(
                'At least two stored PDFs are required to merge.',
                array('file_ids' => array('Give two or more stored file ids.')),
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so file ids cannot be resolved.',
            );
        }

        $paths = array();

        foreach ($ids as $id) {
            $path = $this->files->getPath($id);

            if (null === $path || '' === $path) {
                return $this->errors->notFound('There is no stored file with id ' . $id . '.');
            }

            $paths[] = $path;
        }

        if (!$this->pdf->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF engine is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        try {
            $result = $this->pdf->merge($paths);

            $payload = array(
                'format' => 'pdf',
                'pages' => $result['pages'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'source_file_ids' => $ids,
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success('PDFs merged.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_merge_failed', $e->getMessage());
        }
    }

    /**
     * Filename for the stored file, always a lowercase hyphenated stem plus `.pdf`.
     *
     * @param array<string, mixed> $arguments
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                return $this->stem($value);
            }
        }

        return 'merged-document.pdf';
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return ('' !== $stem ? $stem : 'merged-document') . '.pdf';
    }
}
