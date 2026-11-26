<?php

/** Rotate Image. @package Nvoos\Core @since 2.0.0 @license MIT
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

class RotateImageTool extends AbstractTool
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
        return 'rotate_image';
    }
    public function getName(): string
    {
        return 'Rotate Image';
    }
    public function getDescription(): string
    {
        return 'Rotate an image by a specified angle in degrees.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array(
            'image_url' => array('type' => 'string','description' => 'URL or path of the source image. Give this or file_id.'),
            'file_id' => array('type' => 'integer','description' => 'Stored file id to transform. Preferred over image_url: the bytes are already on disk.','minimum' => 1),
            'angle' => array('type' => 'number','description' => 'Rotation angle in degrees (clockwise).','default' => 90),
            'background' => array('type' => 'string','description' => 'Background color hex for uncovered areas. Default: #ffffff.','default' => '#ffffff'),
        ) + GeneratedMediaPersister::parameters(),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source = $this->sourcePath($arguments);
        if (null === $source) {
            return $this->errors->validationFailed('image_url or file_id is required.', array('image_url' => array('Give image_url or file_id.')));
        }

        if (! $this->img->isAvailable()) {
            return $this->errors->create('no_library', 'No image processing library available.');
        }

        $save = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->store;

        try {
            $angle = (float)($arguments['angle'] ?? 90);
            $bg    = $this->stringParam($arguments, 'background', '#ffffff');
            $result = $this->img->rotate($source, $angle, $bg);

            $payload = array( 'angle' => $angle, 'width' => $result['width'], 'height' => $result['height'], 'mime_type' => $result['mime_type'], 'bytes' => $result['bytes'] );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'rotated-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success("Image rotated {$angle}°.", $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('rotate_failed', $e->getMessage());
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
