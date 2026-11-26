<?php

/**
 * Build a Word document from structured content.
 *
 * Proposal 164 §164.4.3. The content shape is the media worker's (`docx`), not a
 * second dialect: `title`, `paragraphs`, `table`, `header`, `footer`. The tool
 * validates the outer shape and normalises the paragraph list, then hands the
 * structure to the writer, because re-implementing `docx`'s argument grammar in
 * a tool schema would drift from the worker the first time either side changed.
 *
 * **Input is plain text, not HTML.** `docx` builds structure from paragraphs and
 * runs; markup in a `text` field would render as literal characters. An HTML
 * document belongs in `html_to_pdf`, which renders it in a browser.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class GenerateWordTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface             $e       Error factory.
     * @param OfficeWriterInterface             $writer  The bound office writer.
     * @param FileVisibilityStoreInterface|null $store   Optional: persist the file.
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
        return 'generate_word';
    }

    public function getName(): string
    {
        return 'Generate Word Document';
    }

    public function getDescription(): string
    {
        return 'Build a .docx from a title, paragraphs (plain strings, headings, styled runs or bullets), a table, and optional header and footer. Input is plain text, not HTML.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title, rendered as a centred title paragraph.',
                ),
                'paragraphs' => array(
                    'type' => 'array',
                    'description' => 'Body content, in order. Each item is a plain string, or an object: {"heading": "...", "level": "HEADING_1"} for a heading, {"runs": [{"text": "...", "bold": true}]} for styled text, or {"text": "...", "bullet": true}.',
                    'items' => array(),
                ),
                'table' => array(
                    'type' => 'object',
                    'description' => 'A single table: {"headers": ["A","B"], "rows": [["1","2"]]}.',
                ),
                'header' => array(
                    'type' => 'string',
                    'description' => 'Text for the page header, right-aligned.',
                ),
                'footer' => array(
                    'type' => 'string',
                    'description' => 'Text for the page footer, centred, followed by the page number.',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to the title, then "document".',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * The WordPress tool declares `consumes-tokens` and `requires-model` because
     * it can hand the outline to a model first. This one cannot: the worker's
     * `docx` route is a deterministic formatter, so claiming a model dependency
     * would send every call through a gate for a cost it never incurs.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $content = $this->content($arguments);

        if (array() === $content) {
            return $this->errors->validationFailed(
                'Nothing to render. Give a title, a paragraph, a table, a header or a footer.',
                array('paragraphs' => array('At least one content field is required.')),
            );
        }

        if (!$this->writer->isAvailable()) {
            return $this->errors->create(
                'no_document_engine',
                'No office writer is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        try {
            $result = $this->writer->docx($content);

            $payload = array(
                'format' => 'docx',
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success('Word document generated.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('word_failed', $e->getMessage());
        }
    }

    /**
     * The worker's `content` object, with only the keys the route reads.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function content(array $arguments): array
    {
        $content = array();

        foreach (array('title', 'header', 'footer') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                $content[$key] = $value;
            }
        }

        $paragraphs = $this->paragraphs($arguments['paragraphs'] ?? array());

        if (array() !== $paragraphs) {
            $content['paragraphs'] = $paragraphs;
        }

        $table = $this->table($arguments['table'] ?? array());

        if (array() !== $table) {
            $content['table'] = $table;
        }

        return $content;
    }

    /**
     * Keep the paragraph forms the worker understands, drop everything else.
     *
     * A malformed item is dropped rather than sent: the route would either throw
     * (a whole document lost to one bad element) or render `[object Object]`.
     *
     * @param mixed $value Raw `paragraphs` argument.
     *
     * @return array<int, string|array<string, mixed>>
     */
    private function paragraphs(mixed $value): array
    {
        if (!\is_array($value)) {
            return array();
        }

        $paragraphs = array();

        foreach ($value as $item) {
            if (\is_string($item) && '' !== \trim($item)) {
                $paragraphs[] = \trim($item);
                continue;
            }

            if (\is_array($item) && array() !== $item) {
                $paragraphs[] = $item;
            }
        }

        return $paragraphs;
    }

    /**
     * Normalise the table to `{headers, rows}`, or nothing.
     *
     * @param mixed $value Raw `table` argument.
     *
     * @return array<string, mixed>
     */
    private function table(mixed $value): array
    {
        if (!\is_array($value) || !isset($value['rows']) || !\is_array($value['rows'])) {
            return array();
        }

        $rows = array();

        foreach ($value['rows'] as $row) {
            $rows[] = \is_array($row) ? \array_values($row) : array($row);
        }

        if (array() === $rows) {
            return array();
        }

        $table = array('rows' => $rows);

        if (isset($value['headers']) && \is_array($value['headers'])) {
            $table['headers'] = \array_values($value['headers']);
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                return $this->stem($value) . '.docx';
            }
        }

        return 'document.docx';
    }

    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));

        return '' !== \trim($stem, '-') ? \trim($stem, '-') : 'document';
    }
}
