<?php

/**
 * Turn a document descriptor into a stored PDF.
 *
 * One place does the three steps every PDF-writing tool needs — compose HTML
 * from content, render it with whatever engine the host bound, and hand the
 * bytes to the file registry — so `pro_pdf_document` and `generate_pdf` cannot
 * drift on page sizes, filenames or visibility.
 *
 * ## Why the engine is asked, not assumed
 *
 * {@see HtmlToPdfInterface::isAvailable()} is checked before the composition is
 * attempted. A host with no worker and no PHP renderer cannot produce a PDF, and
 * the honest answer is a named capability error, not an empty document
 * (§164.4.8). The tool that owns the editor decides the wording; this class only
 * reports the fact.
 *
 * ## Page sizes are resolved, not trusted
 *
 * `A4` and `a4` are the same paper. A size the renderer does not know is not a
 * reason to fail the call when the caller's intent — "normal paper" — is clear,
 * so an unknown size falls back to the renderer's first supported format. The
 * tool schemas publish the same list as an enum, which is what actually stops a
 * bad value at the edge.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Document;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Service\Document\HtmlFormatter;

final class PdfDocumentComposer
{
    public function __construct(
        private readonly HtmlFormatter $formatter,
        private readonly HtmlToPdfInterface $pdf,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
    }

    /**
     * Whether this host can render a PDF at all.
     */
    public function isAvailable(): bool
    {
        return $this->pdf->isAvailable();
    }

    /**
     * Page sizes the bound renderer accepts.
     *
     * @return list<string>
     */
    public function supportedPageSizes(): array
    {
        return \array_values($this->pdf->getSupportedPageFormats());
    }

    /**
     * Compose the HTML body for a document descriptor.
     *
     * `sections` wins over `content` when both are present, because a caller that
     * supplied a section list stated a structure and a flat body would discard
     * it. Formatting keys the caller omitted fall back to the formatter's
     * defaults rather than to `null`.
     *
     * @param array<string, mixed> $document {
     *     @type array<int, mixed> $sections   Sections with `heading`/`content`/`level`.
     *     @type string            $content    Flat body text.
     *     @type string            $title      Document title.
     *     @type string            $author     Document author.
     *     @type array<string, mixed> $formatting `font_family`, `font_size`, `color`, `line_height`.
     *     @type string            $page_size  Page size, used for the text column width.
     *     @type string            $orientation `portrait` or `landscape`.
     * }
     */
    public function compose(array $document): string
    {
        $sections = $document['sections'] ?? null;
        $content = $document['content'] ?? '';

        if (\is_array($sections) && array() !== $sections) {
            $body = $this->formatter->sectionsToHtml($sections);
        } elseif (\is_string($content) && '' !== \trim($content)) {
            $body = $this->formatter->textToHtml($content);
        } else {
            $body = '';
        }

        // Checked here rather than after wrapping: `createDocument()` always
        // emits a valid `<body>`, so an emptiness test on the finished string
        // would pass for a document with nothing in it.
        if ('' === \trim($body)) {
            throw new \InvalidArgumentException('The document is empty. Give content or at least one section.');
        }

        return $this->wrap($body, $document);
    }

    /**
     * Wrap an already-composed body in the document shell.
     *
     * `compose()` derives the body from prose, which cannot express a table. An
     * invoice is a table plus totals, so its tool builds the body and hands it
     * here — page width, orientation and typography still come from one place,
     * so an invoice cannot drift from the other PDFs this host writes.
     *
     * @param string               $body     Already-escaped body HTML.
     * @param array<string, mixed> $document Descriptor for {@see compose()}.
     */
    private function wrap(string $body, array $document): string
    {
        $formatting = \is_array($document['formatting'] ?? null) ? $document['formatting'] : array();

        $options = array(
            'title' => (string) ($document['title'] ?? 'Document'),
            'author' => (string) ($document['author'] ?? ''),
            'orientation' => $this->orientation($document['orientation'] ?? 'portrait'),
            'page_width' => HtmlFormatter::pageWidth($this->pageSize($document['page_size'] ?? '')),
        );

        if (isset($formatting['font_family'])) {
            $options['font_family'] = $formatting['font_family'];
        }

        if (isset($formatting['font_size'])) {
            $options['font_size'] = $formatting['font_size'];
        }

        if (isset($formatting['color'])) {
            $options['color'] = $formatting['color'];
        }

        if (isset($formatting['line_height'])) {
            $options['line_height'] = $formatting['line_height'];
        }

        return $this->formatter->createDocument($body, $options);
    }

    /**
     * Compose, render, and optionally store, returning the canonical payload.
     *
     * The payload deliberately mirrors what the WordPress tools report
     * (`page_size`, `orientation`, `title`, `mime_type`, `bytes`) so a caller
     * ported from there reads the same fields, with the registry row under
     * `media` instead of `attachment_id`.
     *
     * @param array<string, mixed> $document  Descriptor for {@see compose()}.
     * @param array<string, mixed> $arguments The originating tool arguments.
     * @param array<string, mixed> $context   Execution context.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When there is nothing to render.
     * @throws \RuntimeException         When the engine fails.
     */
    public function document(array $document, array $arguments, array $context): array
    {
        return $this->render($this->compose($document), $document, $arguments, $context);
    }

    /**
     * Render a body the caller composed itself.
     *
     * Everything after the body is shared with {@see document()}: page size
     * resolution, orientation, the registry row and the payload shape. An
     * invoice supplies its own table and gets identical treatment.
     *
     * @param string               $bodyHtml  Already-escaped body HTML.
     * @param array<string, mixed> $document  Descriptor for {@see compose()}.
     * @param array<string, mixed> $arguments The originating tool arguments.
     * @param array<string, mixed> $context   Execution context.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the body is empty.
     * @throws \RuntimeException         When the engine fails.
     */
    public function documentFromHtml(string $bodyHtml, array $document, array $arguments, array $context): array
    {
        if ('' === \trim($bodyHtml)) {
            throw new \InvalidArgumentException('The document body is empty.');
        }

        return $this->render($this->wrap($bodyHtml, $document), $document, $arguments, $context);
    }

    /**
     * Render finished HTML, persist it when asked, and build the payload.
     *
     * @param array<string, mixed> $document  Resolved descriptor.
     * @param array<string, mixed> $arguments The originating tool arguments.
     * @param array<string, mixed> $context   Execution context.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the engine fails.
     */
    private function render(string $html, array $document, array $arguments, array $context): array
    {
        $pageSize = $this->pageSize((string) ($document['page_size'] ?? ''));
        $landscape = 'landscape' === $this->orientation($document['orientation'] ?? 'portrait');

        $rendered = $this->pdf->render(
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
            'title' => (string) ($document['title'] ?? ''),
            'mime_type' => $rendered['mime_type'],
            'bytes' => $rendered['bytes'],
        );

        if ($this->shouldStore($arguments) && null !== $this->store) {
            $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                $payload,
                $rendered['path'],
                $context,
                $this->outputName($arguments, (string) ($document['title'] ?? '')),
                $this->visibility($arguments),
                $rendered['mime_type'],
            );
        }

        return $payload;
    }

    /**
     * Whether the registry row should be written.
     *
     * Two spellings are honoured: `save_to_media` (this runtime's) and `upload`
     * (the WordPress tool's). Either one may opt out; absence means yes, which
     * is what a caller expects from a tool whose output is a file.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function shouldStore(array $arguments): bool
    {
        foreach (array('save_to_media', 'upload') as $key) {
            if (\array_key_exists($key, $arguments)) {
                return (bool) $arguments[$key];
            }
        }

        return true;
    }

    /**
     * The requested visibility, defaulting to public.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function visibility(array $arguments): string
    {
        $requested = $arguments['visibility'] ?? FileVisibilityStoreInterface::VISIBILITY_PUBLIC;

        return \in_array(
            $requested,
            array(FileVisibilityStoreInterface::VISIBILITY_PUBLIC, FileVisibilityStoreInterface::VISIBILITY_PRIVATE),
            true,
        ) ? $requested : FileVisibilityStoreInterface::VISIBILITY_PUBLIC;
    }

    /**
     * Canonical page size, resolved against the bound renderer.
     */
    private function pageSize(string $requested): string
    {
        $supported = $this->supportedPageSizes();
        $default = array() === $supported ? 'A4' : $supported[0];

        if ('' !== \trim($requested)) {
            foreach ($supported as $candidate) {
                if (0 === \strcasecmp($candidate, $requested)) {
                    return $candidate;
                }
            }
        }

        return $default;
    }

    /**
     * `portrait` or `landscape`, case-insensitively, defaulting to portrait.
     */
    private function orientation(mixed $requested): string
    {
        return 'landscape' === \strtolower(\trim((string) $requested)) ? 'landscape' : 'portrait';
    }

    /**
     * The display name handed to the registry.
     *
     * `filename`, then `file_name`, then `title`, then the document's own title,
     * matching `HtmlToPdfTool` — so the same arguments name a file the same way
     * whichever PDF tool produced it.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function outputName(array $arguments, string $title): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $arguments[$key] ?? '';

            if (\is_string($value) && '' !== \trim($value)) {
                return $this->stem($value);
            }
        }

        if ('' !== \trim($title)) {
            return $this->stem($title);
        }

        return 'document';
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return '' === $stem ? 'document' : $stem;
    }
}
