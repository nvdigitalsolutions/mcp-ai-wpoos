<?php

/**
 * HTML to PDF.
 *
 * Proposal 164 §164.4.3 — the first ported document-generation tool, and the
 * proof that the worker ladder in 164.4.2 is real: the engine is whatever the
 * host bound behind {@see HtmlToPdfInterface}, and this tool never assumes one.
 *
 * The WordPress tool it ports takes `html`, `title`, `filename`, `page_size`
 * and `orientation`. The same five are accepted here, with `page_size` resolved
 * against the renderer's own list so an unsupported size is a validation error
 * rather than a silently wrong page.
 *
 * @package Nvoos\Core
 * @since   2.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class HtmlToPdfTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface             $e     Error factory.
     * @param HtmlToPdfInterface                $pdf   The bound renderer.
     * @param FileVisibilityStoreInterface|null $store Optional: persist the result in the file registry.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly HtmlToPdfInterface $pdf,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'html_to_pdf';
    }

    public function getName(): string
    {
        return 'HTML to PDF';
    }

    public function getDescription(): string
    {
        return 'Render an HTML document to a PDF, with CSS, images and page breaks honoured by the bound renderer. Returns the stored file when a file registry is available.';
    }

    public function getParametersSchema(): array
    {
        $supported = $this->pdf->getSupportedPageFormats();

        return array(
            'type' => 'object',
            'properties' => array(
                'html' => array(
                    'type' => 'string',
                    'description' => 'HTML to convert. A full document or a fragment; CSS may be inline or in a <style> block.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title, used for metadata and the default filename.',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to file_name, then the title, then "document".',
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
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('html'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Canonical flags, minus the `local-only` the WordPress tool declares.
     *
     * `local-only` is true there because DomPDF and wkhtmltopdf both run in the
     * WordPress process. Here the default tier is the media worker, so the HTML
     * leaves the host; declaring `local-only` would tell the read-only profile
     * and the necessity gate that nothing left the estate when something did.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $html = $this->htmlParam($arguments);

        if ('' === \trim($html)) {
            return $this->errors->validationFailed(
                'The HTML body is required.',
                array('html' => array('Give the HTML to render.')),
            );
        }

        if (!$this->pdf->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer.',
            );
        }

        $pageSize = $this->pageSize($this->stringParam($arguments, 'page_size'));
        $landscape = 'landscape' === \strtolower($this->stringParam($arguments, 'orientation', 'portrait'));

        try {
            $result = $this->pdf->render(
                $html,
                array(
                    'format' => $pageSize,
                    'landscape' => $landscape,
                    'print_background' => true,
                ),
            );

            $payload = array(
                'format' => 'pdf',
                'page_size' => $pageSize,
                'orientation' => $landscape ? 'landscape' : 'portrait',
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if (null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success('PDF generated.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('pdf_failed', $e->getMessage());
        }
    }

    /**
     * Canonical page size, or the renderer's first supported size when none was given.
     *
     * An unknown size falls back rather than failing: the schema's enum already
     * rejects it at the edge, and a direct call that bypasses the schema should
     * still produce a document.
     */
    private function pageSize(string $requested): string
    {
        $supported = $this->pdf->getSupportedPageFormats();
        $default = array() === $supported ? 'A4' : $supported[0];

        foreach ($supported as $candidate) {
            if (0 === \strcasecmp($candidate, $requested)) {
                return $candidate;
            }
        }

        return $default;
    }

    /**
     * The raw HTML argument.
     *
     * Deliberately not `stringParam()`: that helper applies `strip_tags()`, which
     * would delete every element this tool exists to render. `strip_html` reads
     * its input the same way for the same reason. The trust boundary is the
     * renderer, not this argument — see `HtmlToPdfInterface` on why the engine is
     * chosen by the host rather than by the caller.
     *
     * @param array<string, mixed> $arguments
     */
    private function htmlParam(array $arguments): string
    {
        $value = $arguments['html'] ?? '';

        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * Filename for the stored file, always a lowercase hyphenated stem plus `.pdf`.
     *
     * `filename` is the WordPress arg and wins; `file_name` is the name the
     * generation-tool family uses and is honoured as a fallback, because
     * `GeneratedMediaPersister::parameters()` advertises it in this schema and a
     * parameter that is advertised but ignored is the defect this project keeps
     * finding. The stem is slugified for the same reason the persister slugifies
     * its own: the store treats the name as a display name, and a name with
     * spaces and slashes is a name someone will eventually join to a path.
     *
     * @param array<string, mixed> $arguments
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                return $this->stem($value);
            }
        }

        return 'document.pdf';
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return ('' !== $stem ? $stem : 'document') . '.pdf';
    }
}
