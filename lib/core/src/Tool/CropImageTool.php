<?php

/** Crop Image. @package Nvoos\Core @since 2.0.0 @license MIT
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

class CropImageTool extends AbstractTool
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
        return 'crop_image';
    }
    public function getName(): string
    {
        return 'Crop Image';
    }
    public function getDescription(): string
    {
        return 'Crop an image to a specific region by providing x, y, width, and height.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array(
            'image_url' => array('type' => 'string','description' => 'URL or path of the source image. Give this or file_id.'),
            'file_id' => array('type' => 'integer','description' => 'Stored file id to transform. Preferred over image_url: the bytes are already on disk.','minimum' => 1),
            'x' => array('type' => 'integer','description' => 'X offset from top-left.','minimum' => 0,'default' => 0),
            'y' => array('type' => 'integer','description' => 'Y offset from top-left.','minimum' => 0,'default' => 0),
            'width' => array('type' => 'integer','description' => 'Crop width in pixels.','minimum' => 1),
            'height' => array('type' => 'integer','description' => 'Crop height in pixels.','minimum' => 1),
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
            $x = $this->intParam($arguments, 'x', 0);
            $y = $this->intParam($arguments, 'y', 0);
            $result = $this->img->crop($source, $x, $y, $width, $height);

            $payload = array( 'x' => $x, 'y' => $y, 'width' => $result['width'], 'height' => $result['height'], 'mime_type' => $result['mime_type'], 'bytes' => $result['bytes'] );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'cropped-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success('Image cropped successfully.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('crop_failed', $e->getMessage());
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
