<?php

/**
 * Build a Word document from content, sections, styled runs or a template.
 *
 * Port of `WP_MCP_AI_Tool_Pro_Word` under Proposal 164 §164.33. Four operations,
 * because the WordPress tool has four and a caller ported from there names one.
 *
 * ## What it adds over `generate_word`
 *
 * `generate_word` takes a fixed shape — title, paragraphs, one table, header,
 * footer — and emits it. This adds three things that shape cannot express: an
 * ordered section list with heading levels, a body of styled **runs** (bold,
 * italic, colour, size, font), and a **template** scaffold. The first two are
 * pure structure; the third is five headings arrangements an author fills in.
 *
 * ## What it deliberately does not declare
 *
 * The WordPress schema offers `orientation`, `page_margins` and a `model`. The
 * bound writer emits a single default-geometry section and calls no model, so
 * declaring any of the three would repeat the defect this cluster keeps finding:
 * a field that is accepted and then ignored. `model` is still accepted (and
 * ignored) so a request ported verbatim is not rejected on it; the other two are
 * simply absent, and `additionalProperties => false` makes their absence a loud
 * validation error rather than a silent no-op.
 *
 * A `description`-only call is refused with `no_content_generator`, exactly as
 * `pro_pdf_document` does, because there is no model to expand it.
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
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ProWordDocumentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Operations the tool accepts.
     *
     * @var list<string>
     */
    private const OPERATIONS = array('generate', 'structure', 'format', 'template');

    /**
     * Heading scaffolds the `template` operation supplies.
     *
     * A template is structure, not prose: it names the sections a document of
     * that kind carries and leaves the caller's content to fill them. Inventing
     * sentences would be a model's job, and there is no model here.
     *
     * @var array<string, array{title: string, headings: list<string>}>
     */
    private const TEMPLATES = array(
        'business_letter' => array(
            'title' => 'Business Letter',
            'headings' => array('Recipient', 'Subject', 'Body', 'Closing'),
        ),
        'report' => array(
            'title' => 'Report',
            'headings' => array('Summary', 'Background', 'Findings', 'Analysis', 'Recommendations'),
        ),
        'resume' => array(
            'title' => 'Resume',
            'headings' => array('Profile', 'Experience', 'Education', 'Skills'),
        ),
        'memo' => array(
            'title' => 'Memorandum',
            'headings' => array('To', 'From', 'Date', 'Subject', 'Body'),
        ),
        'proposal' => array(
            'title' => 'Proposal',
            'headings' => array('Overview', 'Scope', 'Approach', 'Timeline', 'Investment'),
        ),
    );

    /**
     * @param ErrorFactoryInterface             $e      Error factory.
     * @param OfficeWriterInterface             $writer The bound office writer.
     * @param FileVisibilityStoreInterface|null $store  Optional: persist the document.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly OfficeWriterInterface $writer,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'pro_word_document';
    }

    public function getName(): string
    {
        return 'Pro Word Document';
    }

    public function getDescription(): string
    {
        return 'Build a .docx from text, an ordered section list with heading levels, styled runs, or one of five template scaffolds. Returns the stored file when a file registry is available.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'operation' => array(
                    'type' => 'string',
                    'description' => '"generate" renders a flat body, "structure" renders an ordered section list, "format" renders a body as styled runs, "template" lays out one of the five scaffolds.',
                    'enum' => self::OPERATIONS,
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The document body, plain text. Blank lines separate paragraphs.',
                ),
                'description' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity with the WordPress tool. This host has no content generator, so a description-only call is refused with no_content_generator.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title, rendered as a centred title paragraph and used for the default filename.',
                ),
                'author' => array(
                    'type' => 'string',
                    'description' => 'Document author. Recorded in the payload; the bound writer has no metadata field.',
                ),
                'sections' => array(
                    'type' => 'array',
                    'description' => 'Ordered sections for the "structure" operation. Each item is {"heading": "...", "content": "...", "level": 2}. Level is clamped to 1-6.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'heading' => array('type' => 'string'),
                            'content' => array('type' => 'string'),
                            'level' => array('type' => 'integer'),
                        ),
                    ),
                ),
                'formatting' => array(
                    'type' => 'object',
                    'description' => 'Applied to the body runs by the "format" operation.',
                    'properties' => array(
                        'bold' => array('type' => 'boolean'),
                        'italic' => array('type' => 'boolean'),
                        'color' => array('type' => 'string'),
                        'font_size' => array('type' => 'number'),
                        'font_family' => array('type' => 'string'),
                    ),
                ),
                'template' => array(
                    'type' => 'string',
                    'description' => 'Scaffold for the "template" operation. It supplies headings only — fill them with content or sections.',
                    'enum' => array_keys(self::TEMPLATES),
                ),
                'header' => array(
                    'type' => 'string',
                    'description' => 'Text for the page header, right-aligned.',
                ),
                'footer' => array(
                    'type' => 'string',
                    'description' => 'Text for the page footer, centred, followed by the page number.',
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

        $content = $this->bodyParam($arguments, 'content');
        $sections = $this->objectListParam($arguments, 'sections');
        $template = $this->stringParam($arguments, 'template');

        $refusal = $this->refuseEmpty($operation, $content, $sections, $template, $arguments);

        if (null !== $refusal) {
            return $refusal;
        }

        if (!$this->writer->isAvailable()) {
            return $this->errors->create(
                'no_document_engine',
                'No office writer is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        $title = $this->stringParam($arguments, 'title');

        $document = array(
            'title' => '' !== $title ? $title : $this->templateTitle($template, $title),
            'paragraphs' => $this->paragraphs($operation, $content, $sections, $template, $arguments),
        );

        $header = $this->stringParam($arguments, 'header');
        if ('' !== $header) {
            $document['header'] = $header;
        }

        $footer = $this->stringParam($arguments, 'footer');
        if ('' !== $footer) {
            $document['footer'] = $footer;
        }

        try {
            $result = $this->writer->docx($document);

            $payload = array(
                'format' => 'docx',
                'operation' => $operation,
                'paragraphs' => \count($document['paragraphs']),
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if ($this->shouldStore($arguments) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments, (string) $document['title']),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success(
                sprintf('Built a Word document with %d paragraph(s).', \count($document['paragraphs'])),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('word_failed', $e->getMessage());
        }
    }

    /**
     * The reason an operation cannot proceed, or null when it can.
     *
     * @param string               $content      Body text.
     * @param array<int, mixed>    $sections     Section list.
     * @param array<string, mixed> $arguments    The originating arguments.
     */
    private function refuseEmpty(
        string $operation,
        string $content,
        array $sections,
        string $template,
        array $arguments,
    ): mixed {
        if ('template' === $operation && '' === $template) {
            return $this->errors->validationFailed(
                'The template operation needs a template.',
                array('template' => array('Choose one of: ' . implode(', ', array_keys(self::TEMPLATES)) . '.')),
            );
        }

        if ('structure' === $operation && array() === $sections) {
            return $this->errors->validationFailed(
                'The structure operation needs at least one section.',
                array('sections' => array('Give an ordered list of {"heading", "content"} sections.')),
            );
        }

        // A template scaffold is content of its own: the headings are the
        // artefact an author fills in, so it needs no body.
        if ('template' === $operation) {
            return null;
        }

        if ('' !== trim($content) || array() !== $sections) {
            return null;
        }

        if ('' !== $this->bodyParam($arguments, 'description')) {
            return $this->errors->create(
                'no_content_generator',
                'This host renders documents deterministically and has no model to expand a description. Send "content" (or "sections"), or bind a model writer.',
                array('missing' => array('content')),
            );
        }

        return $this->errors->validationFailed(
            'Nothing to render. Give content, or use the structure operation with sections.',
            array('content' => array('At least one of content or sections is required.')),
        );
    }

    /**
     * The paragraph list the writer consumes.
     *
     * @param string               $content   Body text.
     * @param array<int, mixed>    $sections  Section list.
     * @param string               $template  Template name.
     * @param array<string, mixed> $arguments The originating arguments.
     *
     * @return array<int, mixed>
     */
    private function paragraphs(
        string $operation,
        string $content,
        array $sections,
        string $template,
        array $arguments,
    ): array {
        if ('structure' === $operation && array() !== $sections) {
            return $this->sectionParagraphs($sections);
        }

        if ('template' === $operation) {
            return $this->templateParagraphs($template, $content, $sections);
        }

        $body = $this->bodyParagraphs($content);

        if ('format' === $operation) {
            return array($this->styledParagraph($body, $arguments));
        }

        return $body;
    }

    /**
     * Sections become a heading paragraph followed by its body.
     *
     * @param array<int, mixed> $sections Section list.
     *
     * @return array<int, mixed>
     */
    private function sectionParagraphs(array $sections): array
    {
        $paragraphs = array();

        foreach ($sections as $section) {
            $heading = $section['heading'] ?? '';

            if (is_string($heading) && '' !== trim($heading)) {
                $level = isset($section['level']) ? (int) $section['level'] : 2;
                $level = max(1, min(6, $level));

                $paragraphs[] = array('heading' => $heading, 'level' => 'HEADING_' . $level);
            }

            $body = $section['content'] ?? '';

            if (is_string($body) && '' !== trim($body)) {
                foreach ($this->bodyParagraphs($body) as $paragraph) {
                    $paragraphs[] = $paragraph;
                }
            }
        }

        return $paragraphs;
    }

    /**
     * A template lays out its headings and places the body under the first.
     *
     * Sections, when given, are matched to the scaffold by heading name and take
     * precedence; anything unmatched is appended in order so no content is lost.
     *
     * @param array<int, mixed> $sections Section list.
     *
     * @return array<int, mixed>
     */
    private function templateParagraphs(string $template, string $content, array $sections): array
    {
        $definition = self::TEMPLATES[$template] ?? self::TEMPLATES['report'];
        $byHeading = array();

        foreach ($sections as $section) {
            $heading = $section['heading'] ?? '';

            if (is_string($heading) && '' !== trim($heading)) {
                $byHeading[strtolower(trim($heading))] = $section;
            }
        }

        $paragraphs = array();
        $used = array();
        $placed = false;

        foreach ($definition['headings'] as $heading) {
            $paragraphs[] = array('heading' => $heading, 'level' => 'HEADING_1');

            $key = strtolower($heading);
            $matched = $byHeading[$key] ?? null;

            if (is_array($matched)) {
                $used[$key] = true;
                $paras = $this->sectionBody($matched);

                foreach ($paras as $paragraph) {
                    $paragraphs[] = $paragraph;
                }

                continue;
            }

            // A flat body belongs under the scaffold's own body heading. Only
            // `business_letter` and `memo` carry one; the rest append at the end.
            if (!$placed && 'Body' === $heading && '' !== trim($content)) {
                foreach ($this->bodyParagraphs($content) as $paragraph) {
                    $paragraphs[] = $paragraph;
                }

                $placed = true;
            }
        }

        // A body with no body heading of its own goes after the scaffold rather
        // than into a heading chosen on the caller's behalf.
        if (!$placed && '' !== trim($content)) {
            foreach ($this->bodyParagraphs($content) as $paragraph) {
                $paragraphs[] = $paragraph;
            }

            $placed = true;
        }

        foreach ($sections as $section) {
            $heading = $section['heading'] ?? '';
            $key = is_string($heading) ? strtolower(trim($heading)) : '';

            if ('' !== $key && isset($used[$key])) {
                continue;
            }

            $paragraphs = array_merge($paragraphs, $this->sectionParagraphs(array($section)));
        }

        return $paragraphs;
    }

    /**
     * A section's body paragraphs, or nothing when it has none.
     *
     * @param array<string, mixed> $section Matched section.
     *
     * @return list<string>
     */
    private function sectionBody(array $section): array
    {
        $body = $section['content'] ?? '';

        return is_string($body) && '' !== trim($body) ? $this->bodyParagraphs($body) : array();
    }

    /**
     * Split a body on blank lines into plain paragraphs.
     *
     * @return list<string>
     */
    private function bodyParagraphs(string $content): array
    {
        $blocks = preg_split('/\n\s*\n/', trim($content)) ?: array();
        $paragraphs = array();

        foreach ($blocks as $block) {
            $block = trim($block);

            if ('' !== $block) {
                $paragraphs[] = $block;
            }
        }

        return $paragraphs;
    }

    /**
     * Turn a paragraph list into a single styled-run paragraph.
     *
     * The `format` operation applies one set of styles to the whole body, which
     * is what the WordPress schema describes: five scalars, not per-run styling.
     *
     * @param list<string>         $body      Paragraphs to join.
     * @param array<string, mixed> $arguments The originating arguments.
     *
     * @return array<string, mixed>
     */
    private function styledParagraph(array $body, array $arguments): array
    {
        $formatting = $this->objectParam($arguments, 'formatting');
        $run = array('text' => implode("\n", $body));

        foreach (array('bold', 'italic') as $flag) {
            if (isset($formatting[$flag])) {
                $run['bold' === $flag ? 'bold' : 'italics'] = (bool) $formatting[$flag];
            }
        }

        $color = $formatting['color'] ?? '';
        if (is_string($color) && 1 === preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
            $run['color'] = ltrim($color, '#');
        }

        $size = $formatting['font_size'] ?? null;
        if (is_numeric($size) && (float) $size > 0) {
            // The writer takes half-points, as the docx library does.
            $run['size'] = (int) round((float) $size * 2);
        }

        $font = $formatting['font_family'] ?? '';
        if (is_string($font) && '' !== trim($font)) {
            $run['font'] = trim($font);
        }

        return array('runs' => array($run));
    }

    /**
     * The title a template implies when the caller gave none.
     */
    private function templateTitle(string $template, string $title): string
    {
        if ('' !== trim($title)) {
            return $title;
        }

        return self::TEMPLATES[$template]['title'] ?? 'Document';
    }

    /**
     * Whether the registry row should be written.
     *
     * Two spellings are honoured, as in {@see \Nvoos\Core\Application\Document\PdfDocumentComposer}:
     * `save_to_media` (this runtime's) and `upload` (the WordPress tool's).
     * Either may opt out; absence means yes, which is what a caller expects from
     * a tool whose output is a file.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function shouldStore(array $arguments): bool
    {
        foreach (array('save_to_media', 'upload') as $key) {
            if (array_key_exists($key, $arguments)) {
                return (bool) $arguments[$key];
            }
        }

        return true;
    }

    /**
     * Read a body field without stripping markup characters.
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

    /**
     * The display name handed to the registry.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function outputName(array $arguments, string $title): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $arguments[$key] ?? '';

            if (is_string($value) && '' !== trim($value)) {
                return $this->stem($value);
            }
        }

        return $this->stem($title);
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = pathinfo(basename($value), PATHINFO_FILENAME);
        $stem = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($stem));
        $stem = trim($stem, '-');

        return '' === $stem ? 'document' : $stem;
    }
}
