<?php

/**
 * Render text to a PDF.
 *
 * Port of `WP_MCP_AI_Tool_Generate_PDF` under Proposal 164 §164.31. Two fields,
 * `content` and `title`, exactly as the WordPress tool declares them.
 *
 * ## This is the tool the WordPress version could not be
 *
 * The original is four lines that delegate to `pro_pdf_document` with
 * `operation: generate` — and pass `content`. That operation requires
 * `description`, so every call returned `wp_mcp_ai_missing_description` and the
 * slug never produced a file. The delegation also made the tool untestable in
 * isolation: its behaviour was whatever the other tool did that day.
 *
 * This port composes directly, through the same `PdfDocumentComposer` the Pro
 * tool uses. Same output, no indirection, and a call with content actually
 * renders. The page-size and typography controls live on `pro_pdf_document`; this
 * stays the two-field tool its schema promises.
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

class GeneratePdfTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
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

        $this->composer = new PdfDocumentComposer(new HtmlFormatter(), $pdf, $store);
    }

    public function getSlug(): string
    {
        return 'generate_pdf';
    }

    public function getName(): string
    {
        return 'Generate PDF';
    }

    public function getDescription(): string
    {
        return 'Render text to a PDF with a title. For page size, orientation or typography use pro_pdf_document.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'content' => array(
                    'type' => 'string',
                    'description' => 'The document body, plain text. Blank lines separate paragraphs; single newlines become line breaks.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title, used for the heading, metadata and the default filename.',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('content'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Canonical flags, without the `requires-model` the WordPress tool declares.
     *
     * Nothing here calls a model: the bound engine renders HTML deterministically,
     * so the flag would send every call through a cost gate for a cost it never
     * incurs.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $content = $arguments['content'] ?? '';
        $content = is_string($content) ? $content : '';

        if ('' === trim($content)) {
            return $this->errors->validationFailed(
                'The content is required.',
                array('content' => array('Give the text to render.')),
            );
        }

        if (!$this->composer->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer.',
            );
        }

        $document = array(
            'content' => $content,
            'title' => $this->stringParam($arguments, 'title'),
        );

        try {
            $payload = $this->composer->document($document, $arguments, $context);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_failed', $e->getMessage());
        }

        unset($payload['format']);

        return $this->success('PDF generated.', $payload);
    }
}
