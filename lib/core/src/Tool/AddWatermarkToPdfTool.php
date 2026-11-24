<?php

/**
 * Stamp a text watermark on every page of a stored PDF.
 *
 * Proposal 164 §164.4.3. The WordPress tool offers `opacity` and `position` and
 * spends them in its own `pdf-lib` path. The media worker's
 * `/api/pdf/watermark` route draws that same mark by default and honours the two
 * options when it advertises `pdf_watermark_style`.
 *
 * So the knobs are offered, and a caller who asks for a **non-default** style on
 * an engine that cannot honour it gets a named `watermark_style_unavailable`
 * error — not a silent default stamp reported as success. A caller who asks for
 * nothing gets exactly the bytes it always did.
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

class AddWatermarkToPdfTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface             $e     Error factory.
     * @param PdfMutatorInterface               $pdf   The bound PDF engine.
     * @param FileStoreInterface|null           $files Optional: resolve `file_id` to a path.
     * @param FileVisibilityStoreInterface|null $store Optional: persist the watermarked file.
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
        return 'add_watermark_to_pdf';
    }

    public function getName(): string
    {
        return 'Add Watermark to PDF';
    }

    public function getDescription(): string
    {
        return 'Stamp a text watermark on every page of a stored PDF, with optional opacity and position. Defaults to the classic diagonal mark; a non-default style needs an engine that supports it and is refused otherwise rather than silently ignored.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the PDF to watermark.',
                    'minimum' => 1,
                ),
                'text' => array(
                    'type' => 'string',
                    'description' => 'Watermark text, e.g. "CONFIDENTIAL" or "DRAFT".',
                ),
                'opacity' => array(
                    'type' => 'number',
                    'description' => 'Mark opacity, 0.0–1.0. Default 0.3.',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.3,
                ),
                'position' => array(
                    'type' => 'string',
                    'description' => 'Where to draw the mark. Default diagonal (rotated −45°); the others are horizontal.',
                    'enum' => array('diagonal', 'center', 'top', 'bottom'),
                    'default' => 'diagonal',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to the source name plus "-watermarked".',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('file_id', 'text'),
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
        $fileId = $this->intParam($arguments, 'file_id');
        $text = $this->stringParam($arguments, 'text');

        if ($fileId <= 0) {
            return $this->errors->validationFailed(
                'A stored file id is required.',
                array('file_id' => array('Give the id of a stored PDF.')),
            );
        }

        if ('' === $text) {
            return $this->errors->validationFailed(
                'Watermark text is required.',
                array('text' => array('Give the mark, e.g. CONFIDENTIAL.')),
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

        if (!$this->pdf->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF engine is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        $options = $this->style($arguments);

        // A non-default style on an engine that cannot honour it is refused:
        // stamping the default mark and reporting success would hand back a
        // different document than the one that was asked for.
        if ($this->isStyled($options) && !$this->pdf->watermarkStyleSupported()) {
            return $this->errors->create(
                'watermark_style_unavailable',
                'The bound PDF engine does not support watermark opacity or position. Omit them, or point this runtime at a media worker that advertises pdf_watermark_style.',
            );
        }

        try {
            $result = $this->pdf->watermark($path, $text, $options);

            $payload = array(
                'format' => 'pdf',
                'pages' => $result['pages'],
                'watermark' => $text,
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'source_file_id' => $fileId,
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

            return $this->success('Watermark added.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_watermark_failed', $e->getMessage());
        }
    }

    /**
     * The style the caller asked for, as the engine's option keys.
     *
     * Only supplied values are returned, so a caller who asks for nothing sends
     * nothing and the engine applies its historical default.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function style(array $arguments): array
    {
        $options = array();

        $opacity = $arguments['opacity'] ?? null;

        if (\is_numeric($opacity)) {
            $options['opacity'] = \max(0.0, \min(1.0, (float) $opacity));
        }

        $position = \strtolower($this->stringParam($arguments, 'position'));

        if (\in_array($position, array('diagonal', 'center', 'top', 'bottom'), true)) {
            $options['position'] = $position;
        }

        return $options;
    }

    /**
     * Whether the request differs from the historical default mark.
     *
     * @param array<string, mixed> $options
     */
    private function isStyled(array $options): bool
    {
        if (isset($options['opacity']) && \abs((float) $options['opacity'] - 0.3) > 0.0001) {
            return true;
        }

        return isset($options['position']) && 'diagonal' !== $options['position'];
    }

    /**
     * Filename for the stored file, always a lowercase hyphenated stem plus `.pdf`.
     *
     * @param array<string, mixed> $arguments
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                return $this->stem($value);
            }
        }

        return 'watermarked.pdf';
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return ('' !== $stem ? $stem : 'watermarked') . '.pdf';
    }
}
