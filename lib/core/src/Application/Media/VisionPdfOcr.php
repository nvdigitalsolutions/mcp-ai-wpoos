<?php

/**
 * Read a PDF's text by showing each page to a vision model.
 *
 * Proposal 164 §164.44. `MediaWorkerPdfOcr` runs tesseract on the worker; this
 * runs a hosted model over page images. Both answer the same question, and both
 * produce the same shape, because the caller should not have to care which one
 * ran — only what it cost and how good it was.
 *
 * ## Why this is not a `PdfOcrInterface` implementation
 *
 * It would fit the interface, and then the container could not tell the two
 * apart: `ProDocumentOcrTool` needs *both*, and two parameters of the same
 * interface type resolve to the same binding. A concrete class keeps the
 * injection unambiguous without a tagged registry for two engines.
 *
 * ## Render, then ask, per page
 *
 * The page images come from `PdfPageRendererInterface`, which is the only thing
 * that can turn a PDF page into bytes a model can see. Asking about the whole
 * document in one prompt is not an option — a 20-page PDF at 150 DPI is tens of
 * megabytes, past every provider's single-request image budget, and models
 * degrade on multi-page input long before that. Published OCR practice is to
 * recognise page by page and then assemble, which is also the only shape that
 * lets one bad page be reported instead of failing the document.
 *
 * There is deliberately **no synthesis pass**. A vision model asked to
 * "combine these transcriptions" invents connective prose; OCR means the words
 * as they were printed. Pages are joined with a blank line and the per-page
 * text is returned so a caller can re-assemble differently.
 *
 * ## Empty is a finding, not a failure
 *
 * A drawing, a cover, a signature page: the model correctly returns nothing.
 * That is a page with no text, and `''` records it. Only a provider *error* is
 * an error, and a document where every page errored is the only case that
 * throws — a partial read that says which pages failed is more useful than an
 * all-or-nothing refusal.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Application\Provider\CompletionRequest;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;

final class VisionPdfOcr
{
    /**
     * What the model is asked about every page.
     *
     * Transcription rather than description: `AnalyzeImageVisionTool`'s default
     * prompt asks for a description, and a description of a scanned invoice is
     * not its text. The negative clauses matter as much as the positive ones —
     * without "do not summarise" a model helpfully rewrites a table as a
     * sentence, and without "if no text return nothing" it invents a caption for
     * a blank page.
     */
    public const PROMPT = 'Transcribe every piece of text on this page exactly as it appears, in reading order. Preserve line breaks and the row and column structure of tables. Do not summarise, translate, explain, or add commentary. If the page contains no text, return nothing.';

    /**
     * Standing instruction for the whole call.
     */
    public const SYSTEM_PROMPT = 'You are a document transcription engine. Your output is the page text and nothing else.';

    /**
     * Answer budget per page.
     *
     * Generous because a dense table page runs long, bounded because one page
     * cannot need more: the cap is a runaway guard, not an expectation.
     */
    public const MAX_TOKENS_PER_PAGE = 4096;

    /**
     * The refusal when no page renderer is bound or usable.
     */
    public const NO_PAGE_RENDERER = 'no_page_renderer';

    /**
     * The refusal when no provider can answer.
     */
    public const NO_VISION_MODEL = 'no_vision_model';

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly ?PdfPageRendererInterface $renderer = null,
        private readonly ?ProviderRouter $providers = null,
    ) {
    }

    /**
     * Why this engine cannot run, or `null` when it can.
     *
     * The two reasons are kept apart because an operator fixes them differently:
     * one is a media worker that is down or older than the `inline` argument,
     * the other is a missing provider or model. Collapsing them into a single
     * "unavailable" is the dead end this codebase refuses elsewhere.
     *
     * @param string $provider Requested provider slug, or '' for the default.
     */
    public function unavailableReason(string $provider = ''): ?string
    {
        if (null === $this->renderer || !$this->renderer->isAvailable()) {
            return self::NO_PAGE_RENDERER;
        }

        if (null === $this->providers) {
            return self::NO_VISION_MODEL;
        }

        return null === $this->client($provider) ? self::NO_VISION_MODEL : null;
    }

    /**
     * Recognise the selected pages and return the text.
     *
     * @param string               $localPath Absolute path to the PDF.
     * @param array<string, mixed> $options {
     *     @type string $provider  Provider slug. '' uses the site default.
     *     @type string $model     Model id. '' uses the provider's default.
     *     @type int    $max_pages Page budget.
     *     @type int    $dpi       Rasterisation resolution; omitted means the renderer's default.
     *     @type string $format    'png' or 'jpeg'.
     * }
     *
     * @return array{
     *     text: string,
     *     pages: list<array{page: int, text: string, characters: int}>,
     *     count: int,
     *     total_pages: int,
     *     truncated: bool,
     *     failed_pages: list<int>,
     *     dpi: int,
     *     provider: string,
     *     model: string,
     *     usage: array<string, int>
     * }
     *
     * @throws \RuntimeException When every page failed, or nothing could be rendered.
     */
    public function recognize(string $localPath, array $options = array()): array
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        $client   = $this->client($provider);

        if (null === $client) {
            throw new \RuntimeException(
                'No vision model can answer. Registered providers: '
                . (array() === $this->registered() ? '(none)' : implode(', ', $this->registered())) . '.'
            );
        }

        $dpi = (int) ($options['dpi'] ?? PdfPageRendererInterface::DEFAULT_DPI);

        $rendered = $this->renderer->render($localPath, $this->renderOptions($options, $dpi));

        $model   = mb_substr(trim((string) ($options['model'] ?? '')), 0, 120);
        $request = array('max_tokens' => self::MAX_TOKENS_PER_PAGE);

        if ('' !== $model) {
            $request['model'] = $model;
        }

        $pages         = array();
        $failed        = array();
        $usage         = array();
        $answeredModel = '';
        $firstFailure  = '';

        foreach ($rendered['pages'] as $page) {
            $number = (int) $page['page'];
            $data   = (string) $page['data'];

            if ('' === $data) {
                $failed[] = $number;

                if ('' === $firstFailure) {
                    $firstFailure = 'The renderer returned no image for page ' . $number . '.';
                }

                continue;
            }

            $response = $client->chat(
                CompletionRequest::messages(
                    self::PROMPT,
                    VisionRequest::dataUriFromBase64($data, (string) $page['mime_type']),
                    self::SYSTEM_PROMPT,
                ),
                $request,
            );

            if ($this->errors->isError($response)) {
                $failed[] = $number;

                if ('' === $firstFailure) {
                    $firstFailure = $this->message($response);
                }

                continue;
            }

            $text = is_array($response) ? ($response['choices'][0]['message']['content'] ?? null) : null;

            if (!is_string($text)) {
                $text = '';
            }

            $pages[] = array(
                'page'       => $number,
                'text'       => trim($text),
                'characters' => mb_strlen(trim($text)),
            );

            if ('' === $answeredModel && is_array($response)) {
                $answeredModel = (string) ($response['model'] ?? '');
            }

            $usage = $this->addUsage($usage, is_array($response) ? (array) ($response['usage'] ?? array()) : array());
        }

        if (array() === $pages) {
            throw new \RuntimeException(
                '' === $firstFailure
                    ? 'The renderer produced no pages to recognise.'
                    : $firstFailure
            );
        }

        return array(
            'text'         => implode("\n\n", array_column($pages, 'text')),
            'pages'        => $pages,
            'count'        => count($pages),
            'total_pages'  => (int) $rendered['total_pages'],
            'truncated'    => (bool) $rendered['truncated'] || array() !== $failed,
            'failed_pages' => $failed,
            'dpi'          => $dpi,
            'provider'     => $client->getProviderSlug(),
            'model'        => '' !== $answeredModel ? $answeredModel : $model,
            'usage'        => $usage,
        );
    }

    /**
     * The renderer options this engine asks for.
     *
     * `dpi` is forwarded only when the caller named one. The tool's schema
     * defaults `dpi` to 300 for tesseract, and rendering a page at 300 DPI for a
     * model that downsamples to its own patch grid buys nothing and doubles the
     * bytes on the wire; the renderer's 150 is the working default for vision.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function renderOptions(array $options, int $dpi): array
    {
        $render = array(
            'dpi'       => $dpi,
            'max_pages' => max(1, (int) ($options['max_pages'] ?? 20)),
        );

        $format = strtolower(trim((string) ($options['format'] ?? '')));

        if ('jpeg' === $format || 'jpg' === $format) {
            $render['format'] = 'jpeg';
        }

        return $render;
    }

    /**
     * The client that will answer, or `null`.
     */
    private function client(string $provider): mixed
    {
        if (null === $this->providers) {
            return null;
        }

        return $this->providers->resolveForChat(
            '' === $provider ? array() : array('provider' => $provider)
        );
    }

    /**
     * @return list<string>
     */
    private function registered(): array
    {
        return null === $this->providers ? array() : $this->providers->getRegisteredSlugs();
    }

    /**
     * The provider's own words, bounded.
     *
     * @param mixed $response An error value from the provider.
     */
    private function message(mixed $response): string
    {
        $normalised = $this->errors->normalize($response);
        $message    = is_array($normalised) ? (string) ($normalised['message'] ?? '') : '';

        return '' === trim($message)
            ? 'The provider refused the page.'
            : mb_substr(trim($message), 0, 500);
    }

    /**
     * Sum the token counts across pages.
     *
     * @param array<string, int>   $total
     * @param array<string, mixed> $usage
     * @return array<string, int>
     */
    private function addUsage(array $total, array $usage): array
    {
        foreach ($usage as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $total[(string) $key] = (int) ($total[(string) $key] ?? 0) + (int) $value;
            }
        }

        return $total;
    }
}
