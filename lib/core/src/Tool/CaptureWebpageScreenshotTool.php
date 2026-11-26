<?php

/**
 * Capture one public page as a stored image — Proposal 164 §164.53.
 *
 * Ported from the Pro tool of the same slug, with one deliberate divergence and
 * two deliberate refusals.
 *
 * **Stored, not returned as a blob.** WordPress offers `save_to_media` because a
 * result that is not an attachment has nowhere to live there; here it does, and a
 * base64 payload of several megabytes is not a deliverable to an agent. The bytes
 * always land in the file registry (§162) and the payload names the stored file.
 * Passing `save_to_media: false` is refused rather than ignored, because obeying
 * it would mean returning nothing at all.
 *
 * **`width`/`height` are refused unless `viewport` is `custom`.** WordPress
 * accepts them and drops them for a preset. Accepting an argument and ignoring it
 * is the defect this programme keeps removing, so the schema says where they are
 * read and the tool enforces it.
 *
 * **`quality` is refused for PNG.** Puppeteer rejects the combination, and a
 * silent drop would look like a quality setting that did nothing.
 *
 * There is no mshots fallback. The WordPress tool degrades to a third-party
 * thumbnail service when its Playwright endpoint is missing; a different service
 * returning a different picture is not a fallback, so this one refuses with
 * `no_browser_engine` instead.
 *
 * @package Nvoos\Core
 * @since   2.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\BrowserCaptureInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class CaptureWebpageScreenshotTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The five presets the WordPress tool offers, at the same dimensions.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const VIEWPORTS = array(
        'desktop'          => array(1920, 1080),
        'laptop'           => array(1280, 800),
        'tablet'           => array(768, 1024),
        'mobile_portrait'  => array(375, 667),
        'mobile_landscape' => array(667, 375),
    );

    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 60;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly BrowserCaptureInterface $browser,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'capture_webpage_screenshot';
    }

    public function getName(): string
    {
        return 'Capture Webpage Screenshot';
    }

    public function getDescription(): string
    {
        return 'Capture a screenshot of any public web page at a preset or custom viewport, full-page or viewport-only, as PNG or JPEG. The image is stored in the file registry and the payload names the stored file. Only one-shot renders are supported: multi-step browsing is web_browser.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'url' => array(
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'The public URL of the page to capture. http and https only; the worker refuses private and loopback hosts.',
                ),
                'viewport' => array(
                    'type' => 'string',
                    'enum' => array('desktop', 'laptop', 'tablet', 'mobile_portrait', 'mobile_landscape', 'custom'),
                    'default' => 'desktop',
                    'description' => 'Viewport preset. Use "custom" together with width and height to set exact dimensions.',
                ),
                'width' => array(
                    'type' => 'integer',
                    'minimum' => 320,
                    'maximum' => 3840,
                    'description' => 'Viewport width in pixels. Read only when viewport is "custom"; supplied with a preset it is refused.',
                ),
                'height' => array(
                    'type' => 'integer',
                    'minimum' => 240,
                    'maximum' => 2160,
                    'description' => 'Viewport height in pixels. Read only when viewport is "custom"; supplied with a preset it is refused.',
                ),
                'full_page' => array(
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Capture the full scrollable page. When false, captures only the visible viewport.',
                ),
                'format' => array(
                    'type' => 'string',
                    'enum' => array('png', 'jpeg'),
                    'default' => 'png',
                    'description' => 'Image format for the screenshot.',
                ),
                'quality' => array(
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'JPEG quality, 1–100. Read only for JPEG output; supplied with PNG it is refused.',
                ),
                'timeout' => array(
                    'type' => 'integer',
                    'minimum' => self::MIN_TIMEOUT,
                    'maximum' => self::MAX_TIMEOUT,
                    'default' => 30,
                    'description' => 'Operation timeout in seconds (5–60).',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('url'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        // The WordPress tool asks for `edit_posts`; here the result is always a
        // stored file, so the capability is the one that guards storage.
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'external-api', 'network-dependent', 'may-timeout', 'non-deterministic');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $url = \trim($this->stringParam($arguments, 'url'));

        if ('' === $url) {
            return $this->errors->validationFailed(
                'A URL is required.',
                array('url' => array('Give the public page to capture, e.g. https://example.com.')),
            );
        }

        if (!\preg_match('#^https?://#i', $url) || false === \filter_var($url, \FILTER_VALIDATE_URL)) {
            return $this->errors->validationFailed(
                'The URL must be an absolute http or https address.',
                array('url' => array('Only http and https are accepted; the worker also refuses private and loopback hosts.')),
            );
        }

        $viewport = $this->stringParam($arguments, 'viewport', 'desktop');
        $custom   = 'custom' === $viewport;

        if (!$custom && !isset(self::VIEWPORTS[$viewport])) {
            return $this->errors->validationFailed(
                'Unknown viewport "' . $viewport . '".',
                array('viewport' => array('Use one of ' . \implode(', ', \array_keys(self::VIEWPORTS)) . ', or "custom".')),
            );
        }

        $hasWidth  = \array_key_exists('width', $arguments) && null !== $arguments['width'];
        $hasHeight = \array_key_exists('height', $arguments) && null !== $arguments['height'];

        if ($custom) {
            if (!$hasWidth || !$hasHeight) {
                return $this->errors->validationFailed(
                    'A custom viewport needs both width and height.',
                    array('width' => array('Give both dimensions when viewport is "custom".')),
                );
            }

            $width  = $this->intParam($arguments, 'width');
            $height = $this->intParam($arguments, 'height');

            if ($width < 320 || $width > 3840 || $height < 240 || $height > 2160) {
                return $this->errors->validationFailed(
                    'The custom viewport is out of range.',
                    array(
                        'width'  => array('320–3840 pixels.'),
                        'height' => array('240–2160 pixels.'),
                    ),
                );
            }
        } else {
            if ($hasWidth || $hasHeight) {
                return $this->errors->validationFailed(
                    'width and height are only read when viewport is "custom".',
                    array('viewport' => array('Pick a preset, or set viewport to "custom" and give both dimensions. A preset with dimensions is refused rather than silently overriding them.')),
                );
            }

            list($width, $height) = self::VIEWPORTS[$viewport];
        }

        $format     = $this->stringParam($arguments, 'format', 'png');
        $hasQuality = \array_key_exists('quality', $arguments) && null !== $arguments['quality'];

        if (!\in_array($format, array('png', 'jpeg'), true)) {
            return $this->errors->validationFailed(
                'Unknown format "' . $format . '".',
                array('format' => array('Use png or jpeg.')),
            );
        }

        if ($hasQuality && 'jpeg' !== $format) {
            return $this->errors->validationFailed(
                'quality is only read for JPEG output.',
                array('quality' => array('Omit it for PNG, or set format to jpeg.')),
            );
        }

        $quality = $hasQuality ? $this->intParam($arguments, 'quality') : 0;

        if ($hasQuality && ($quality < 1 || $quality > 100)) {
            return $this->errors->validationFailed(
                'quality must be between 1 and 100.',
                array('quality' => array('Use 1–100.')),
            );
        }

        $timeout = $this->intParam($arguments, 'timeout', 30);

        if ($timeout < self::MIN_TIMEOUT || $timeout > self::MAX_TIMEOUT) {
            return $this->errors->validationFailed(
                'timeout must be between ' . self::MIN_TIMEOUT . ' and ' . self::MAX_TIMEOUT . ' seconds.',
                array('timeout' => array('Use 5–60 seconds.')),
            );
        }

        // Refused rather than ignored: without storage the payload would carry a
        // multi-megabyte blob or nothing, and neither is a deliverable here.
        if (!$this->boolParam($arguments, 'save_to_media', true)) {
            return $this->errors->validationFailed(
                'A screenshot is always stored on this runtime.',
                array('save_to_media' => array('Leave it at the default: a result that exists only in the response has no id, slug or URL here.')),
            );
        }

        if (null === $this->store) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so the screenshot has nowhere to live.',
            );
        }

        if (!$this->browser->isAvailable()) {
            return $this->errors->create(
                'no_browser_engine',
                'No browser engine is bound on this host. Configure the media worker (MEDIA_WORKER_URL) with its /api/browser route.',
            );
        }

        $fullPage = $this->boolParam($arguments, 'full_page', true);

        $options = array(
            'width'     => $width,
            'height'    => $height,
            'full_page' => $fullPage,
            'format'    => $format,
            'timeout'   => $timeout,
        );

        if ($hasQuality) {
            $options['quality'] = $quality;
        }

        try {
            $shot = $this->browser->capture($url, $options);
        } catch (\Throwable $e) {
            return $this->errors->create('screenshot_failed', $e->getMessage());
        }

        $payload = array(
            'url'        => $url,
            'viewport'   => $viewport,
            'width'      => $width,
            'height'     => $height,
            'full_page'  => $fullPage,
            'format'     => $shot['format'],
            'mime_type'  => $shot['mime_type'],
            'size_bytes' => $shot['size_bytes'],
        );

        $persister = new GeneratedMediaPersister($this->store);
        $payload   = $persister->attach(
            $payload,
            array(array('b64_json' => $shot['b64'])),
            $context,
            GeneratedMediaPersister::displayName($arguments, $context, 'screenshot'),
            $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
            $shot['mime_type'],
        );

        $media = isset($payload['media']) && \is_array($payload['media']) ? $payload['media'] : array();

        if (array() === $media) {
            $failed = isset($payload['media_failed']) && \is_array($payload['media_failed'])
                ? \reset($payload['media_failed'])
                : false;

            return $this->errors->create(
                'screenshot_not_stored',
                'The page was captured but the screenshot could not be stored: '
                . (\is_string($failed) && '' !== $failed ? $failed : 'the store refused the bytes.'),
            );
        }

        return $this->success('Screenshot captured and stored.', $payload);
    }
}
