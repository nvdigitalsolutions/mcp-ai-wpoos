<?php

/**
 * Build a PDF from structured content.
 *
 * Port of `WP_MCP_AI_Tool_Pro_PDF` under Proposal 164 §164.31. Three operations,
 * because the WordPress tool has three and a caller ported from there names one:
 *
 *  - `generate`  — a flat body of text, paragraphised.
 *  - `structure` — an ordered section list, each with a heading and a body.
 *  - `format`    — a flat body plus explicit typography.
 *
 * ## What changed, and why
 *
 * The WordPress `generate` operation **requires `description`** and hands it to a
 * model to write the document; the sibling `generate_pdf` tool passes `content`
 * instead, so it has never produced a PDF (§164.31). This port accepts `content`
 * for `generate`, because content is the thing the operation renders, and it
 * refuses a `description`-only call with a named error rather than silently
 * doing nothing.
 *
 * A `description`-only call cannot be honoured here: the bound engine is the
 * media worker's `pdf/generate` route — puppeteer rendering HTML — which is a
 * **deterministic formatter**. There is no model in this path, so the tool does
 * not declare `requires-model`, for the reason already recorded on
 * `GenerateWordTool`: claiming a gate for a cost that is never incurred routes
 * every call through it for nothing. The `model` parameter is therefore accepted
 * and ignored, so a caller porting a request verbatim is not rejected on a field
 * this runtime cannot use.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Document\PdfDocumentComposer;
use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Document\HtmlFormatter;

class ProPdfDocumentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Operations the tool accepts.
     *
     * @var list<string>
     */
    private const OPERATIONS = array('generate', 'structure', 'format');

    private readonly PdfDocumentComposer $composer;

    /**
     * @param ErrorFactoryInterface             $e     Error factory.
     * @param HtmlToPdfInterface                $pdf   The bound renderer.
     * @param FileVisibilityStoreInterface|null $store Optional: persist the result.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        HtmlToPdfInterface $pdf,
        ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);

        $this->composer = new PdfDocumentComposer(
            new HtmlFormatter(),
            $pdf,
            $store,
        );
    }

    public function getSlug(): string
    {
        return 'pro_pdf_document';
    }

    public function getName(): string
    {
        return 'Pro PDF Document';
    }

    public function getDescription(): string
    {
        return 'Create a PDF from text or a section list, with a title, author, page size, orientation and typography. Returns the stored file when a file registry is available.';
    }

    public function getParametersSchema(): array
    {
        $supported = $this->composer->supportedPageSizes();

        return array(
            'type' => 'object',
            'properties' => array(
                'operation' => array(
                    'type' => 'string',
                    'description' => '"generate" renders a flat body, "structure" renders an ordered section list, "format" renders a flat body with explicit typography.',
                    'enum' => self::OPERATIONS,
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The document body, plain text. Blank lines separate paragraphs; single newlines become line breaks. Markup is escaped, not interpreted.',
                ),
                'description' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity with the WordPress tool. This host has no content generator, so a description-only call is refused with no_content_generator — send content instead.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title, used for the heading, metadata and the default filename.',
                ),
                'author' => array(
                    'type' => 'string',
                    'description' => 'Document author, written to the document metadata.',
                ),
                'sections' => array(
                    'type' => 'array',
                    'description' => 'Ordered sections for the "structure" operation. Each item is {"heading": "...", "content": "...", "level": 2}. Level is clamped to 1-6.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'heading' => array(
                                'type' => 'string',
                            ),
                            'content' => array(
                                'type' => 'string',
                            ),
                            'level' => array(
                                'type' => 'integer',
                            ),
                        ),
                    ),
                ),
                'formatting' => array(
                    'type' => 'object',
                    'description' => 'Typography. All four are honoured; the WordPress tool accepted color and line_height but never applied them.',
                    'properties' => array(
                        'font_family' => array(
                            'type' => 'string',
                            'description' => 'CSS font stack. A value containing braces, semicolons or a url()/expression() is replaced with the default rather than repaired.',
                        ),
                        'font_size' => array(
                            'type' => 'number',
                            'description' => 'Base size in points. Default 12.',
                        ),
                        'color' => array(
                            'type' => 'string',
                            'description' => 'Body text colour, e.g. "#333333".',
                        ),
                        'line_height' => array(
                            'type' => 'number',
                            'description' => 'Unitless line height. Default 1.6.',
                        ),
                    ),
                ),
                'page_size' => array(
                    'type' => 'string',
                    'description' => 'Page size. Default A4. Case-insensitive, so the WordPress spelling "a4" also resolves.',
                    'enum' => array() === $supported ? array('A4') : $supported,
                    'default' => array() === $supported ? 'A4' : $supported[0],
                ),
                'orientation' => array(
                    'type' => 'string',
                    'description' => 'Page orientation. Default portrait.',
                    'enum' => array('portrait', 'landscape'),
                    'default' => 'portrait',
                ),
                'model' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity with the WordPress tool and ignored: this path has no model, the engine is a deterministic formatter.',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('operation'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Canonical flags, without `requires-model` or `consumes-tokens`.
     *
     * The WordPress tool declares both because `generate` and `format` can ask a
     * model to write the body. Nothing here calls a model: the worker renders
     * HTML. Declaring them would route every call through a cost gate for a cost
     * the call does not incur.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $operation = strtolower($this->stringParam($arguments, 'operation'));

        if (!in_array($operation, self::OPERATIONS, true)) {
            return $this->errors->validationFailed(
                'Choose an operation: ' . implode(', ', self::OPERATIONS) . '.',
                array('operation' => array('Unknown or missing operation.')),
            );
        }

        $document = array(
            'content' => $this->bodyParam($arguments, 'content'),
            'title' => $this->stringParam($arguments, 'title'),
            'author' => $this->stringParam($arguments, 'author'),
            'sections' => $this->objectListParam($arguments, 'sections'),
            'formatting' => $this->objectParam($arguments, 'formatting'),
            'page_size' => $this->stringParam($arguments, 'page_size'),
            'orientation' => $this->stringParam($arguments, 'orientation', 'portrait'),
        );

        $refusal = $this->refuseEmpty($operation, $document, $arguments);

        if (null !== $refusal) {
            return $refusal;
        }

        if (!$this->composer->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer.',
            );
        }

        try {
            $payload = $this->composer->document($document, $arguments, $context);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_failed', $e->getMessage());
        }

        unset($payload['format']);

        return $this->success(
            sprintf('Created a %s PDF.', strtoupper((string) $payload['page_size'])),
            array('operation' => $operation) + $payload,
        );
    }

    /**
     * The reason an operation cannot proceed, or null when it can.
     *
     * The three refusals are different failures and are reported as such:
     * `structure` without sections is a schema mistake, `generate` with neither
     * field is empty input, and a `description`-only call is a capability the
     * host does not have. Collapsing them into one message would tell a caller to
     * fix the wrong thing.
     *
     * @param array<string, mixed> $document  Resolved descriptor.
     * @param array<string, mixed> $arguments The originating arguments.
     */
    private function refuseEmpty(string $operation, array $document, array $arguments): mixed
    {
        $hasContent = '' !== trim((string) $document['content']);
        $hasSections = array() !== $document['sections'];
        $hasDescription = '' !== $this->bodyParam($arguments, 'description');

        if ('structure' === $operation && !$hasSections) {
            return $this->errors->validationFailed(
                'The structure operation needs at least one section.',
                array('sections' => array('Give an ordered list of {"heading", "content"} sections.')),
            );
        }

        // Sections satisfy every operation, not only `structure`: `compose()`
        // prefers them when present, so accepting them here means a caller who
        // supplied structure is never told there was nothing to render.
        if ($hasContent || $hasSections) {
            return null;
        }

        if ($hasDescription) {
            return $this->errors->create(
                'no_content_generator',
                'This host renders documents deterministically and has no model to expand a description. Send "content" (or "sections" with the structure operation), or bind a model writer.',
                array('missing' => array('content')),
            );
        }

        return $this->errors->validationFailed(
            'Nothing to render. Give content, or use the structure operation with sections.',
            array('content' => array('At least one of content or sections is required.')),
        );
    }

    /**
     * Read a body field without stripping markup characters.
     *
     * `stringParam()` applies `strip_tags()`, which is right for a filename and
     * wrong for prose: "a < b" would lose its comparison. The formatter escapes
     * every body value as text, so the value reaches the document as characters
     * rather than markup, and no tag can survive that step.
     *
     * @param array<string, mixed> $arguments The originating arguments.
     */
    private function bodyParam(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Read a list of plain objects, dropping anything that is not one.
     *
     * @param array<string, mixed> $arguments The originating arguments.
     *
     * @return array<int, mixed>
     */
    private function objectListParam(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;

        if (!is_array($value)) {
            return array();
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
