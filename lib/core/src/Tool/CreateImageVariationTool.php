<?php

/** Create Image Variation — DALL-E variation API. @package Nvoos\Core @since 2.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class CreateImageVariationTool extends AbstractTool
{
    /**
     * `$files` is optional: a host with no registry keeps the payload-only
     * behaviour rather than failing to construct the tool.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SettingsStoreInterface $s,
        private readonly HttpClientInterface $h,
        private readonly ?FileVisibilityStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }
    public function getSlug(): string
    {
        return 'create_image_variation';
    }
    public function getName(): string
    {
        return 'Create Image Variation';
    }
    public function getDescription(): string
    {
        return 'Creates variations of an existing image using OpenAI DALL-E.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array( 'image_url' => array('type' => 'string','description' => 'URL of the source image.'), 'n' => array('type' => 'integer','description' => 'Number of variations (1-10).','minimum' => 1,'maximum' => 10,'default' => 1), 'size' => array('type' => 'string','enum' => array('256x256','512x512','1024x1024'),'default' => '1024x1024') ) + GeneratedMediaPersister::parameters(),'required' => array('image_url'),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $imageUrl = $this->stringParam($arguments, 'image_url');
        if ('' === $imageUrl) {
            return $this->errors->validationFailed('An image URL is required.', array('image_url' => array('Required.')));
        }

        $key = $this->s->getApiKey('openai');
        if (null === $key || '' === $key) {
            return $this->errors->create('missing_key', 'No OpenAI API key configured.');
        }

        $n    = $this->intParam($arguments, 'n', 1);
        $size = $this->stringParam($arguments, 'size', '1024x1024');
        $save = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->files;

        try {
            $r = $this->h->send('POST', 'https://api.openai.com/v1/images/variations', array( 'Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json' ), $this->jsonBody(array( 'image' => $imageUrl, 'n' => $n, 'size' => $size, 'response_format' => 'b64_json' )));
            $d = \json_decode($r->body, true);
            if ($r->statusCode >= 400) {
                $err = $d['error']['message'] ?? 'DALL-E variation error.';
                return $this->errors->create('dalle_error', $err);
            }

            $images = array();
            foreach ($d['data'] ?? array() as $img) {
                $images[] = array( 'b64_json' => $img['b64_json'] ?? null );
            }

            $payload = array( 'images' => $images, 'count' => \count($images) );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->files))->attach(
                    $payload,
                    $images,
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'openai-variation'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                );
            }

            return $this->success('Variation(s) generated successfully.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('request_failed', $e->getMessage());
        }
    }
}
