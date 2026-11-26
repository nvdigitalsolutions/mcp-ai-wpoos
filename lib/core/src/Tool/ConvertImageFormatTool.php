<?php

/** Convert Image Format. @package Nvoos\Core @since 2.0.0 @license MIT
 *
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
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class ConvertImageFormatTool extends AbstractTool
{
    /**
     * Both file collaborators are optional: a host with no registry can still
     * transform a path, it just cannot resolve a `file_id` or keep the result.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProcessingInterface $img,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }
    public function getSlug(): string
    {
        return 'convert_image_format';
    }
    public function getName(): string
    {
        return 'Convert Image Format';
    }
    public function getDescription(): string
    {
        return 'Convert an image to a different format (PNG, JPEG, WebP, GIF) with optional quality control.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array(
            'image_url' => array('type' => 'string','description' => 'URL or path of the source image. Give this or file_id.'),
            'file_id' => array('type' => 'integer','description' => 'Stored file id to transform. Preferred over image_url: the bytes are already on disk.','minimum' => 1),
            'format' => array('type' => 'string','description' => 'Target format.','enum' => array('png','jpeg','jpg','webp','gif')),
            'quality' => array('type' => 'integer','description' => 'Output quality (1-100).','minimum' => 1,'maximum' => 100,'default' => 90),
        ) + GeneratedMediaPersister::parameters(),'required' => array('format'),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source   = $this->sourcePath($arguments);
        $format   = \strtolower($this->stringParam($arguments, 'format'));
        if (null === $source || '' === $format) {
            return $this->errors->validationFailed('image_url or file_id, plus format, are required.', array('image_url' => array('Give image_url or file_id.'),'format' => array('Required.')));
        }
        if ('jpg' === $format) {
            $format = 'jpeg';
        }

        $quality = \max(1, \min(100, $this->intParam($arguments, 'quality', 90)));

        if (! $this->img->isAvailable()) {
            return $this->errors->create('no_library', 'No image processing library (GD or Imagick) is available.');
        }

        $save = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->store;

        try {
            $result = $this->img->convert($source, $format, $quality);

            $payload = array( 'format' => $format, 'quality' => $quality, 'width' => $result['width'], 'height' => $result['height'], 'mime_type' => $result['mime_type'], 'bytes' => $result['bytes'] );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'converted-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success("Image converted to {$format}.", $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('convert_failed', $e->getMessage());
        }
    }

    /**
     * Resolve the source to a local path.
     *
     * @param array<string, mixed> $arguments
     */
    private function sourcePath(array $arguments): ?string
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId > 0) {
            return $this->files?->getPath($fileId);
        }

        $url = $this->stringParam($arguments, 'image_url');

        return '' !== $url ? $url : null;
    }
}
