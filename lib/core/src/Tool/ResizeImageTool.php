<?php

/** Resize Image. @package Nvoos\Core @since 2.0.0 @license MIT
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

class ResizeImageTool extends AbstractTool
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
        return 'resize_image';
    }
    public function getName(): string
    {
        return 'Resize Image';
    }
    public function getDescription(): string
    {
        return 'Resize an image to specified dimensions with optional cropping.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array(
            'image_url' => array('type' => 'string','description' => 'URL or path of the source image. Give this or file_id.'),
            'file_id' => array('type' => 'integer','description' => 'Stored file id to transform. Preferred over image_url: the bytes are already on disk, so a generation can be transformed without a re-upload.','minimum' => 1),
            'width' => array('type' => 'integer','description' => 'Target width in pixels.','minimum' => 1),
            'height' => array('type' => 'integer','description' => 'Target height in pixels.','minimum' => 1),
            'crop' => array('type' => 'boolean','description' => 'Whether to crop to exact dimensions. Default: true.','default' => true),
            'quality' => array('type' => 'integer','description' => 'Output quality (1-100).','minimum' => 1,'maximum' => 100),
        ) + GeneratedMediaPersister::parameters(),'required' => array('width','height'),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source   = $this->sourcePath($arguments);
        $width    = $this->intParam($arguments, 'width');
        $height   = $this->intParam($arguments, 'height');
        if (null === $source || $width <= 0 || $height <= 0) {
            return $this->errors->validationFailed('image_url or file_id, plus width and height, are required.', array('image_url' => array('Give image_url or file_id.'),'width' => array('Required.'),'height' => array('Required.')));
        }

        if (! $this->img->isAvailable()) {
            return $this->errors->create('no_library', 'No image processing library available.');
        }

        $save = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->store;

        try {
            $crop = !isset($arguments['crop']) || (bool)$arguments['crop'];
            $quality = $arguments['quality'] ?? null;
            $result = $this->img->resize($source, $width, $height, array( 'crop' => $crop, 'quality' => $quality ));

            $payload = array( 'width' => $result['width'], 'height' => $result['height'], 'mime_type' => $result['mime_type'], 'bytes' => $result['bytes'] );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'resized-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success('Image resized successfully.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('resize_failed', $e->getMessage());
        }
    }

    /**
     * Resolve the source to a local path.
     *
     * `file_id` is the chain primitive: a stored file already has bytes on
     * disk, so a transform can follow a generation without a re-upload.
     * `image_url` is kept for parity, but the processor takes a *path* —
     * `wp_get_image_editor()` and the media worker agree on that, so a URL
     * fails the same way in both runtimes rather than changing meaning here.
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
