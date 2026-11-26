<?php

/**
 * Optimise many images in one call, with a dry run that is the default.
 *
 * Proposal 164 §164.6 phase 2. The WordPress tool defaults `dry_run` to **true**,
 * and that default is the most valuable thing about it: a bulk rewrite of a
 * media library is not something an orchestrator should do because it inferred
 * intent. This port keeps it, and keeps it meaning what it says — a dry run
 * makes no engine call at all and reports only what the registry already knows.
 *
 * ## Two source arguments, one list
 *
 * `file_ids` is the port's own vocabulary; `attachment_ids` is the WordPress
 * name, kept as an alias so a caller migrating a prompt does not have to change
 * it. `image_urls` covers images that are not in the registry. The three are
 * concatenated in that order and de-duplicated by value.
 *
 * ## A batch that partly fails is not a failed batch
 *
 * Per-file errors come back in `errors` beside `results`, because aborting the
 * whole run on one corrupt file would leave the library half-optimised with no
 * record of where it stopped.
 *
 * @package Nvoos\Core
 * @since   2.5.0
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
use Nvoos\Core\Domain\Contract\ImageProductionInterface;

class OptimiseImagesBatchTool extends AbstractTool
{
    /**
     * A hard ceiling on one call. Beyond this the request outlives any sane
     * HTTP timeout and the honest answer is "split it".
     */
    private const MAX_IMAGES = 50;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProductionInterface $images,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'optimise_images_batch';
    }

    public function getName(): string
    {
        return 'Optimise Images (Batch)';
    }

    public function getDescription(): string
    {
        return 'Optimise several images in one call, optionally converting them to WebP. Dry run is the default: it reports what would change without touching the library. Pass dry_run=false to apply.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids to optimise.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                ),
                'attachment_ids' => array(
                    'type' => 'array',
                    'description' => 'Alias of file_ids, kept for callers that learned the WordPress argument name.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                ),
                'image_urls' => array(
                    'type' => 'array',
                    'description' => 'Paths or URLs of images that are not in the registry.',
                    'items' => array('type' => 'string'),
                ),
                'quality' => array(
                    'type' => 'integer',
                    'description' => 'Encoder quality (1-100). 82 is a good size/quality balance.',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 82,
                ),
                'convert_to_webp' => array(
                    'type' => 'boolean',
                    'description' => 'Encode to WebP instead of keeping the source format. Default: true.',
                    'default' => true,
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'Report what would change without writing anything. Default: true, because a bulk rewrite should be asked for twice.',
                    'default' => true,
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array(),
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
        return array('requires-capability', 'state-changing', 'write', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $ids = $this->mergeIds($arguments);

        $urls = array();

        foreach ($this->stringListParam($arguments, 'image_urls') as $url) {
            $url = \trim($url);

            if ('' !== $url) {
                $urls[] = $url;
            }
        }

        if (array() === $ids && array() === $urls) {
            return $this->errors->validationFailed(
                'At least one image is required.',
                array('file_ids' => array('Give file_ids, attachment_ids or image_urls.')),
            );
        }

        if (\count($ids) + \count($urls) > self::MAX_IMAGES) {
            return $this->errors->validationFailed(
                \sprintf('At most %d images per call.', self::MAX_IMAGES),
                array('file_ids' => array('Split the run into smaller batches.')),
            );
        }

        $sources = $this->resolve($ids, $urls);

        if (array() === $sources) {
            return $this->errors->notFound('None of the given images could be resolved to a readable file.');
        }

        $dryRun = $this->boolParam($arguments, 'dry_run', true);
        $quality = \max(1, \min(100, $this->intParam($arguments, 'quality', 82)));
        $webp = $this->boolParam($arguments, 'convert_to_webp', true);

        if ($dryRun) {
            return $this->success(
                \sprintf('%d image(s) would be optimised. Nothing was written.', \count($sources)),
                array(
                    'dry_run' => true,
                    'would_process' => \count($sources),
                    'target_format' => $webp ? 'webp' : 'source',
                    'quality' => $quality,
                    'images' => $sources,
                ),
            );
        }

        if (! $this->images->supports('batch')) {
            return $this->errors->create(
                'image_pipeline_unavailable',
                'The bound image engine cannot run batches. Configure a media worker that advertises image_batch.',
            );
        }

        $paths = \array_column($sources, 'path');

        try {
            $result = $this->images->batch($paths, array(
                'format' => $webp ? 'webp' : 'original',
                'quality' => $quality,
            ));

            $payload = array(
                'dry_run' => false,
                'processed' => $result['processed'],
                'failed' => $result['failed'],
                'total_bytes_saved' => $result['total_bytes_saved'],
                'results' => $result['results'],
                'errors' => $result['errors'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = $this->persist($payload, $result['results'], $arguments, $context);
            }

            return $this->success(
                \sprintf('%d optimised, %d failed, %d bytes saved.', $result['processed'], $result['failed'], $result['total_bytes_saved']),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('batch_optimise_failed', $e->getMessage());
        }
    }

    /**
     * Merge the two id arguments into one ordered, de-duplicated list.
     *
     * @param array<string, mixed> $arguments
     * @return list<int>
     */
    private function mergeIds(array $arguments): array
    {
        $ids = array();

        foreach (array('file_ids', 'attachment_ids') as $key) {
            foreach ($this->intListParam($arguments, $key) as $id) {
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return \array_values($ids);
    }

    /**
     * Resolve ids and URLs into a list of describable sources.
     *
     * A dry run reports what the registry knows, which is why `size_bytes` is
     * present for ids and absent for URLs — inventing a number for a URL would
     * make the estimate look more certain than it is.
     *
     * @param list<int>    $ids
     * @param list<string> $urls
     * @return list<array<string, mixed>>
     */
    private function resolve(array $ids, array $urls): array
    {
        $sources = array();

        foreach ($ids as $id) {
            $path = $this->files?->getPath($id);

            if (null === $path || '' === $path) {
                continue;
            }

            $file = $this->files?->getMetadata($id);

            $sources[] = array(
                'file_id' => $id,
                'path' => $path,
                'filename' => $file?->filename ?? \basename($path),
                'mime_type' => $file?->mimeType ?? '',
                'size_bytes' => $file?->sizeBytes,
            );
        }

        foreach ($urls as $url) {
            if (! \is_file($url)) {
                continue;
            }

            $sources[] = array(
                'file_id' => null,
                'path' => $url,
                'filename' => \basename($url),
                'mime_type' => '',
                'size_bytes' => \filesize($url) ?: null,
            );
        }

        return $sources;
    }

    /**
     * Store every produced file and report per-file store failures beside the
     * optimise results, never instead of them.
     *
     * @param array<string, mixed>              $payload
     * @param list<array<string, mixed>>        $results
     * @param array<string, mixed>              $arguments
     * @param array<string, mixed>              $context
     * @return array<string, mixed>
     */
    private function persist(array $payload, array $results, array $arguments, array $context): array
    {
        $persister = new GeneratedMediaPersister($this->store);
        $media = array();
        $failed = array();

        $visibility = $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC);

        foreach ($results as $index => $row) {
            $path = (string) ($row['path'] ?? '');

            if ('' === $path) {
                continue;
            }

            $outcome = $persister->attachFile(
                array(),
                $path,
                $context,
                GeneratedMediaPersister::displayName($arguments, $context, 'optimised-' . $index),
                $visibility,
                (string) ($row['mime_type'] ?? 'image/webp'),
            );

            if (isset($outcome['media'][0])) {
                $payload['results'][$index]['media'] = $outcome['media'][0];
                $media[] = $outcome['media'][0];
            }

            if (isset($outcome['media_failed'])) {
                $failed[] = $outcome['media_failed'];
            }
        }

        if (array() !== $media) {
            $payload['media'] = $media;
        }

        if (array() !== $failed) {
            $payload['media_failed'] = $failed;
        }

        return $payload;
    }
}
