<?php

/**
 * Photograph a product in a scene that does not exist.
 *
 * Proposal 164 §164.130. The WordPress original (`addons/pro/.../class-wp-mcp-ai-pro-tool-product-actualization.php`)
 * does this in one of two ways: `ai` mode sends the product to an image editor
 * with a "build a scene around this" prompt, and `composite` mode generates a
 * background and overlays the product with GD or Imagick.
 *
 * ## Two model turns, because the worker has two primitives
 *
 * This runtime owns no image library — GD is not assumed, and blending is not
 * in the port. What it has is the worker's text-to-image route and its
 * two-image composition route, so the port is: generate the scene from
 * `scene_prompt`, then compose the product into it. That is one extra turn
 * compared with the original's `ai` mode, and it is the difference between a
 * faithful result and a tool that cannot run at all.
 *
 * ## What is refused, and why it says so
 *
 *  - `integration_mode: composite` — there is no local blend. The refusal names
 *    `ai`, which is the mode that actually works here.
 *  - `mode: video` — the worker can submit and poll a video job, but the
 *    artifact lifetime is unresolved, so the contract is not bound yet.
 *  - `background_mode: remove` — the worker exposes no removal route. `auto` is
 *    therefore treated as `preserve` and the payload reports
 *    `background_removed: false` rather than implying a cut-out happened.
 *
 * Refusals carry a next step, because a "no" without one is how a model retries
 * the same call five times.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Application\Media\VideoGenerationPollHandler;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\VideoGenerationInterface;
use Nvoos\Core\Tool\AbstractTool;

class ProductActualizationTool extends AbstractTool
{
    /**
     * The closed set the composition route accepts.
     *
     * Replicate is absent on purpose: its style models take one image, so it
     * can edit but cannot compose.
     */
    private const PROVIDERS = array('auto', 'gemini', 'openai');

    /**
     * The ratios the original exposed. The adapter rounds each to a canvas the
     * provider accepts.
     */
    private const ASPECT_RATIOS = array('1:1', '4:5', '16:9', '9:16', '3:2', '2:3', 'auto');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProductionInterface $images,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
        private readonly ?VideoGenerationInterface $videos = null,
        private readonly ?QueueClientInterface $queue = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'product_actualization';
    }

    public function getName(): string
    {
        return 'Product Actualization';
    }

    public function getDescription(): string
    {
        return 'Photograph a product in a scene that does not exist. Generates the scene from '
            . 'scene_prompt, then integrates the product photo into it with matching lighting, '
            . 'shadows and perspective, so a catalog image looks shot on location. Needs a Gemini '
            . 'or OpenAI key on the media worker. mode "video" animates the composed still with '
            . 'image-to-video and returns a queue job id: poll that job for the stored clip, which '
            . 'is about two seconds. Composite mode (blend the product onto a background locally) '
            . 'is not available in this runtime and is refused by name. Supply the product as file_id.';
    }

    /**
     * A caller may visualize a product it could have uploaded, and no others.
     */
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'state-changing',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'model-dependent',
            'rate-limited',
            'requires-capability',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Id of the product image in this runtime\'s file registry. The image is sent to the model, not altered in place.',
                    'minimum' => 1,
                ),
                'scene_prompt' => array(
                    'type' => 'string',
                    'description' => 'The scene to photograph the product in, e.g. "bright kitchen counter, morning light, shallow depth of field".',
                    'minLength' => 1,
                    'maxLength' => 1000,
                ),
                'mode' => array(
                    'type' => 'string',
                    'description' => '"image" renders and stores a still. "video" composes that still, animates it with image-to-video, and answers a queue job id; poll the job for the stored clip.',
                    'enum' => array('image', 'video'),
                    'default' => 'image',
                ),
                'aspect_ratio' => array(
                    'type' => 'string',
                    'description' => 'Canvas for the scene. Each ratio is rounded to the nearest size the provider accepts.',
                    'enum' => self::ASPECT_RATIOS,
                    'default' => '3:2',
                ),
                'background_mode' => array(
                    'type' => 'string',
                    'description' => '"preserve" uses the product photo as supplied. "auto" behaves as preserve here because no background-removal capability is configured, and the result reports background_removed: false. "remove" is refused.',
                    'enum' => array('auto', 'remove', 'preserve'),
                    'default' => 'auto',
                ),
                'placement_hint' => array(
                    'type' => 'string',
                    'description' => 'Where the product should sit, e.g. "centre on the table" or "bottom-right on a shelf". Added to the integration prompt.',
                    'maxLength' => 300,
                ),
                'scale_factor' => array(
                    'type' => 'number',
                    'description' => 'Only used by composite mode, which this runtime refuses. Accepted so a WordPress-shaped call does not fail validation.',
                    'minimum' => 0.1,
                    'maximum' => 2.0,
                    'default' => 1.0,
                ),
                'integration_mode' => array(
                    'type' => 'string',
                    'description' => '"ai" embeds the product in one model turn. "composite" needs a local image blend this runtime does not ship, and is refused by name.',
                    'enum' => array('ai', 'composite'),
                    'default' => 'ai',
                ),
                'duration_seconds' => array(
                    'type' => 'integer',
                    'minimum' => 4,
                    'maximum' => 10,
                    'default' => 6,
                    'description' => 'Accepted for WordPress shape compatibility. The image-to-video model renders 14 frames at 6 fps, about two seconds, so this does not change the length.',
                ),
                'provider' => array(
                    'type' => 'string',
                    'description' => 'Used by the image turns: "auto" prefers Gemini and falls back to OpenAI, resolved by the worker. Video generation uses Replicate and needs REPLICATE_API_KEY on the worker.',
                    'enum' => self::PROVIDERS,
                    'default' => 'auto',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('file_id', 'scene_prompt'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fileId = $this->intParam($arguments, 'file_id');
        $scenePrompt = \trim($this->stringParam($arguments, 'scene_prompt'));

        if ($fileId <= 0) {
            return $this->errors->validationFailed(
                'file_id is required: the product image must be in this runtime\'s file registry.',
                array('file_id' => array('Give the product image as file_id.'))
            );
        }

        if ('' === $scenePrompt) {
            return $this->errors->validationFailed(
                'scene_prompt is required to generate the scene.',
                array('scene_prompt' => array('Describe the scene to photograph the product in.'))
            );
        }

        $mode = \strtolower($this->stringParam($arguments, 'mode', 'image'));

        if (! \in_array($mode, array('image', 'video'), true)) {
            $mode = 'image';
        }

        $integration = \strtolower($this->stringParam($arguments, 'integration_mode', 'ai'));

        if ('composite' === $integration) {
            return $this->errors->create(
                'composite_not_available',
                'Composite mode (generate a background, then overlay the product with a local image library) needs an image blend this runtime does not ship. Use integration_mode "ai", which places the product in one model turn.'
            );
        }

        $backgroundMode = \strtolower($this->stringParam($arguments, 'background_mode', 'auto'));

        if (! \in_array($backgroundMode, array('auto', 'preserve'), true)) {
            if ('remove' === $backgroundMode) {
                return $this->errors->create(
                    'background_removal_unavailable',
                    'background_mode "remove" needs a background-removal capability the media worker does not expose. Use "auto" or "preserve": the integration prompt tells the model to keep the product unchanged, so a busy original background is handled in the render rather than pre-cut.'
                );
            }

            $backgroundMode = 'auto';
        }

        $product = $this->product($fileId, $context);

        if ($this->errors->isError($product)) {
            return $product;
        }

        $provider = \strtolower($this->stringParam($arguments, 'provider', 'auto'));

        if (! \in_array($provider, self::PROVIDERS, true)) {
            $provider = 'auto';
        }

        $ratio = $this->stringParam($arguments, 'aspect_ratio', '3:2');

        if (! \in_array($ratio, self::ASPECT_RATIOS, true)) {
            $ratio = '3:2';
        }

        if (! $this->images->supports('generate')) {
            return $this->errors->create(
                'no_image_generation_provider',
                'The media worker does not declare image generation, or no provider key resolves for this site. Set GEMINI_API_KEY or OPENAI_API_KEY on the worker.'
            );
        }

        if (! $this->images->supports('compose')) {
            return $this->errors->create(
                'no_image_composition_provider',
                'The media worker does not declare image composition. Composition needs a provider that accepts two images in one turn (Gemini or OpenAI); a Replicate-only host can edit but cannot compose.'
            );
        }

        if ('video' === $mode) {
            return $this->video($scenePrompt, (string) $product['path'], $provider, $ratio, $backgroundMode, $context, $arguments);
        }

        $scene = null;

        try {
            $scene = $this->images->generate(
                $scenePrompt,
                array(
                    'aspect_ratio' => $ratio,
                    'provider' => $provider,
                )
            );

            $result = $this->images->compose(
                (string) $scene['path'],
                $product['path'],
                array(
                    'prompt' => $this->integrationPrompt($scenePrompt, $this->stringParam($arguments, 'placement_hint')),
                    'provider' => $provider,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('product_actualization_failed', $e->getMessage());
        } finally {
            if (\is_array($scene) && \is_string($scene['path'] ?? null) && \is_file($scene['path'])) {
                // The scene is an intermediate: the composition is the artifact.
                @\unlink($scene['path']);
            }
        }

        $payload = array(
            'mode' => 'image',
            'width' => $result['width'],
            'height' => $result['height'],
            'format' => $result['format'],
            'mime_type' => $result['mime_type'],
            'bytes' => $result['bytes'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'integration_mode' => 'ai',
            'aspect_ratio' => $ratio,
            'background_mode' => $backgroundMode,
            'background_removed' => false,
            'scene_prompt' => $scenePrompt,
            'scene_provider' => $scene['provider'],
            'scene_model' => $scene['model'],
            'scene_dimensions' => array('width' => $scene['width'], 'height' => $scene['height']),
        );

        if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
            $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                $payload,
                (string) $result['path'],
                $context,
                GeneratedMediaPersister::displayName($arguments, $context, 'product-actualization'),
                $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                (string) $result['mime_type'],
            );
        }

        return $this->success(
            'Product placed in a generated scene with ' . $result['provider'] . '.',
            $payload
        );
    }

    /**
     * Compose the still, animate it, and hand the waiting to a queue job.
     *
     * The still is the reference frame, which is why the image half runs first:
     * a text prompt cannot describe a specific garment, a frame can. The clip is
     * submitted, not awaited — the job polls and stores, and the caller reads the
     * result from the queue status. Both scratch images are deleted here because
     * the worker already has the bytes and the registry will own the video.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $arguments
     */
    private function video(
        string $scenePrompt,
        string $productPath,
        string $provider,
        string $ratio,
        string $backgroundMode,
        array $context,
        array $arguments
    ): mixed {
        if (null === $this->videos || null === $this->queue) {
            return $this->errors->create(
                'video_generation_unavailable',
                'No video-generation contract is bound on this host. Bind VideoGenerationInterface and QueueClientInterface to use mode "video"; mode "image" needs neither.'
            );
        }

        if (! $this->videos->supports('generate')) {
            return $this->errors->create(
                'no_video_generation_provider',
                'The media worker does not declare video generation, or no Replicate key resolves for this site. Set REPLICATE_API_KEY on the worker.'
            );
        }

        $scene = null;
        $still = null;

        try {
            $scene = $this->images->generate($scenePrompt, array('aspect_ratio' => $ratio, 'provider' => $provider));

            $still = $this->images->compose(
                (string) $scene['path'],
                $productPath,
                array(
                    'prompt' => $this->integrationPrompt($scenePrompt, $this->stringParam($arguments, 'placement_hint')),
                    'provider' => $provider,
                )
            );

            $job = $this->videos->submit(
                'Smooth camera motion around the product. Keep the product, its colours and its materials identical in every frame.',
                array(
                    'reference_path' => (string) $still['path'],
                    'model' => 'stable-video-diffusion',
                )
            );

            $jobId = $this->queue->enqueue(
                VideoGenerationPollHandler::class,
                array(
                    'prediction_id' => (string) $job['prediction_id'],
                    'user_id' => (int) ($context['user_id'] ?? $context['owner_id'] ?? 0),
                    'visibility' => $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    'file_name' => GeneratedMediaPersister::displayName($arguments, $context, 'product-video'),
                ),
                array(
                    // A render takes minutes, so the budget is many short attempts:
                    // one status call every fifteen seconds for twenty minutes.
                    'tries' => 60,
                    'backoff' => array(15),
                    'timeout' => 120,
                    'retry_until_minutes' => 20,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('product_actualization_failed', $e->getMessage());
        } finally {
            foreach (array($still, $scene) as $artifact) {
                if (\is_array($artifact) && \is_string($artifact['path'] ?? null) && \is_file($artifact['path'])) {
                    @\unlink($artifact['path']);
                }
            }
        }

        return $this->success(
            'Video queued with ' . $job['provider'] . '. Poll the job for the stored result.',
            array(
                'status' => 'queued',
                'job_id' => $jobId,
                'prediction_id' => $job['prediction_id'],
                'provider' => $job['provider'],
                'model' => $job['model'],
                'mode' => 'video',
                'integration_mode' => 'ai',
                'aspect_ratio' => $ratio,
                'background_mode' => $backgroundMode,
                'background_removed' => false,
                'scene_prompt' => $scenePrompt,
                'reference_dimensions' => array('width' => $still['width'] ?? null, 'height' => $still['height'] ?? null),
                'note' => 'The image-to-video model renders about two seconds; duration_seconds is accepted for shape compatibility.',
            )
        );
    }

    /**
     * Resolve the product image to a local path, or the refusal to return.
     *
     * `getMetadata()` + `userCanAccess()` mirror `ImageInput`: a caller that
     * cannot download a file must not be able to send it to a model, and a
     * missing row and a forbidden row answer the same way.
     *
     * @param array<string, mixed> $context
     *
     * @return array{path: string}|mixed
     */
    private function product(int $fileId, array $context): mixed
    {
        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'This host has no file registry, so file_id cannot be read. Store the product image first.'
            );
        }

        $stored = $this->files->getMetadata($fileId);

        if (null === $stored) {
            return $this->errors->notFound('No file with that id exists.');
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        if ($userId > 0 && ! $this->files->userCanAccess($fileId, $userId)) {
            return $this->errors->notFound('No file with that id exists.');
        }

        if (! $stored->isImage()) {
            return $this->errors->validationFailed(
                'That file is not an image.',
                array('file_id' => array('The product must be an image.'))
            );
        }

        if (! \is_file($stored->localPath)) {
            return $this->errors->create(
                'file_unreadable',
                'That file could not be read from storage.'
            );
        }

        return array('path' => $stored->localPath);
    }

    /**
     * The instruction the composition turn receives.
     *
     * The scene image is generated from the same sentence, so repeating it
     * keeps the lighting and mood the caller asked for rather than whatever the
     * scene happened to render. The placement hint is appended rather than
     * dropped: a caller that names a position expects it to be used.
     */
    private function integrationPrompt(string $scenePrompt, string $placement): string
    {
        $prompt = 'Place the product shown in the second image into the scene in the first image. '
            . 'The scene is: ' . $scenePrompt . ' '
            . 'Match the scene perspective, scale and lighting; keep the product shape, colour and '
            . 'details unchanged; add a natural contact shadow.';

        $placement = \trim($placement);

        if ('' !== $placement) {
            $prompt .= ' Position the product as follows: ' . $placement . '.';
        }

        return $prompt;
    }
}
