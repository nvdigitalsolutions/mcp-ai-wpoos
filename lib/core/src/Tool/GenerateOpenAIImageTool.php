<?php

/** Generate OpenAI Image — DALL-E API wrapper. @package Nvoos\Core @since 2.0.0 @license MIT
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

class GenerateOpenAIImageTool extends AbstractTool
{
    private const SIZES = array('256x256','512x512','1024x1024','1792x1024','1024x1792');

    /**
     * `$files` is optional: a host with no file registry keeps the old
     * payload-only behaviour rather than failing to construct the tool. The
     * registrar resolves an unbound optional collaborator to its default.
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
        return 'generate_openai_image';
    }
    public function getName(): string
    {
        return 'Generate OpenAI Image';
    }
    public function getDescription(): string
    {
        return 'Generates images from text descriptions using OpenAI DALL-E.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array(
            'prompt' => array('type' => 'string','description' => 'Image description.'),
            'model' => array('type' => 'string','description' => 'DALL-E model.','enum' => array('dall-e-3','dall-e-2'),'default' => 'dall-e-3'),
            'size' => array('type' => 'string','description' => 'Image size.','enum' => self::SIZES,'default' => '1024x1024'),
            'quality' => array('type' => 'string','description' => 'Quality (dall-e-3 only).','enum' => array('standard','hd'),'default' => 'standard'),
            'n' => array('type' => 'integer','description' => 'Number of images (1-10).','minimum' => 1,'maximum' => 10,'default' => 1),
            'style' => array('type' => 'string','description' => 'Style (dall-e-3 only).','enum' => array('vivid','natural'),'default' => 'vivid'),
            'response_format' => array('type' => 'string','enum' => array('url','b64_json'),'default' => 'b64_json'),
        ) + GeneratedMediaPersister::parameters(),'required' => array('prompt'),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $prompt = $this->stringParam($arguments, 'prompt');
        if ('' === $prompt) {
            return $this->errors->validationFailed('A prompt is required.', array('prompt' => array('Required.')));
        }

        $key = $this->s->getApiKey('openai');
        if (null === $key || '' === $key) {
            return $this->errors->create('missing_key', 'No OpenAI API key configured.');
        }

        $model  = $this->stringParam($arguments, 'model', 'dall-e-3');
        $size   = $this->stringParam($arguments, 'size', '1024x1024');
        $n      = $this->intParam($arguments, 'n', 1);
        $fmt    = $this->stringParam($arguments, 'response_format', 'b64_json');
        $style  = $this->stringParam($arguments, 'style', 'vivid');
        $quality = $this->stringParam($arguments, 'quality', 'standard');
        $save    = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->files;

        $body = array( 'model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => $n, 'response_format' => $fmt );
        if ('dall-e-3' === $model) {
            $body['style'] = $style;
            $body['quality'] = $quality;
        }

        if ($save) {
            // Keeping the bytes is the point, and the provider's hosted URL is
            // temporary. Asking for base64 avoids a second fetch of a link the
            // caller does not control, and `media[].source_url` below replaces
            // it with a stable one.
            $body['response_format'] = 'b64_json';
        }

        try {
            $r = $this->h->send('POST', 'https://api.openai.com/v1/images/generations', array( 'Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json' ), $this->jsonBody($body));
            $d = \json_decode($r->body, true);
            if ($r->statusCode >= 400) {
                $err = $d['error']['message'] ?? 'DALL-E API error.';
                return $this->errors->create('dalle_error', $err);
            }

            $images = array();
            foreach ($d['data'] ?? array() as $img) {
                $images[] = array( 'url' => $img['url'] ?? null, 'b64_json' => $img['b64_json'] ?? null, 'revised_prompt' => $img['revised_prompt'] ?? null );
            }

            $payload = array( 'prompt' => $prompt, 'model' => $model, 'images' => $images, 'count' => \count($images) );

            if ($save) {
                $payload = (new GeneratedMediaPersister($this->files))->attach(
                    $payload,
                    $images,
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'openai-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                );
            }

            return $this->success('Image(s) generated successfully.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('request_failed', $e->getMessage());
        }
    }
}
