<?php

/**
 * The five `docs_hub_*` tools.
 *
 * The corpus adapter carries the risk and has its own suite with the traversal
 * cases (`FilesystemDocsCorpusTest`). What is left to prove here is the layer
 * above it — that each tool refuses a host with no corpus by name, that a
 * refused path and a missing document stay distinguishable, and that delete
 * defaults to a dry run.
 *
 * The corpus is a recording double: the assertions are about what each tool asked
 * it to do, which is the only thing these tools are responsible for.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\DocsHubDeleteDocTool;
use Nvoos\Core\Tool\DocsHubListDocsTool;
use Nvoos\Core\Tool\DocsHubReadDocTool;
use Nvoos\Core\Tool\DocsHubRebuildTool;
use Nvoos\Core\Tool\DocsHubWriteDocTool;
use PHPUnit\Framework\TestCase;

final class DocsHubToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── No corpus on the host ─────────────────────────────────────────────

    /**
     * Every tool must name the absence rather than answer an empty result that
     * reads like "no documents yet".
     */
    public function test_every_tool_names_a_missing_corpus(): void
    {
        $corpus = $this->corpus(inactive: true);

        $results = array(
            'list' => (new DocsHubListDocsTool($this->errors, $corpus))->execute(),
            'read' => (new DocsHubReadDocTool($this->errors, $corpus))->execute(array('path' => 'a.md')),
            'write' => (new DocsHubWriteDocTool($this->errors, $corpus))->execute(array('path' => 'a.md', 'content' => 'x')),
            'delete' => (new DocsHubDeleteDocTool($this->errors, $corpus))->execute(array('path' => 'a.md')),
            'rebuild' => (new DocsHubRebuildTool($this->errors, $corpus))->execute(),
        );

        foreach ($results as $name => $result) {
            $this->assertFalse($result['success'], $name . ' should fail without a corpus.');
            $this->assertSame('no_docs_corpus', $result['error']['code'], $name);
        }
    }

    // ─── Listing ───────────────────────────────────────────────────────────

    public function test_list_reports_the_total_even_when_truncated(): void
    {
        $corpus = $this->corpus();
        $corpus->listing = array(
            'documents' => array(array('path' => 'a.md', 'title' => 'A', 'size' => 1, 'modified' => 0)),
            'total' => 9,
        );

        $result = (new DocsHubListDocsTool($this->errors, $corpus))->execute(array('limit' => 1));

        $this->assertTrue($result['success']);
        $this->assertSame(9, $result['data']['total']);
        $this->assertSame(1, $corpus->calls[0]['limit']);
    }

    public function test_list_caps_an_oversized_limit(): void
    {
        $corpus = $this->corpus();

        (new DocsHubListDocsTool($this->errors, $corpus))->execute(array('limit' => 99999));

        $this->assertSame(500, $corpus->calls[0]['limit']);
    }

    public function test_list_turns_a_refused_prefix_into_a_validation_error(): void
    {
        $corpus = $this->corpus(refuse: true);

        $result = (new DocsHubListDocsTool($this->errors, $corpus))->execute(array('subdirectory' => '../'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Reading ───────────────────────────────────────────────────────────

    public function test_read_returns_the_document(): void
    {
        $corpus = $this->corpus();
        $corpus->document = array(
            'path' => 'guides/a.md',
            'title' => 'A',
            'content' => 'Body',
            'size' => 4,
            'modified' => 123,
        );

        $result = (new DocsHubReadDocTool($this->errors, $corpus))->execute(array('path' => 'guides/a.md'));

        $this->assertTrue($result['success']);
        $this->assertSame('Body', $result['data']['content']);
    }

    /**
     * A missing document and a refused path are different failures. Collapsing
     * them sends a caller off rewriting a path when the file is simply gone.
     */
    public function test_read_separates_missing_from_refused(): void
    {
        $missing = (new DocsHubReadDocTool($this->errors, $this->corpus(missing: true)))
            ->execute(array('path' => 'gone.md'));

        $refused = (new DocsHubReadDocTool($this->errors, $this->corpus(refuse: true)))
            ->execute(array('path' => '../x.md'));

        $this->assertSame('not_found', $missing['error']['code']);
        $this->assertSame('validation_failed', $refused['error']['code']);
    }

    public function test_read_requires_a_path(): void
    {
        $result = (new DocsHubReadDocTool($this->errors, $this->corpus()))->execute();

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Writing ───────────────────────────────────────────────────────────

    public function test_write_passes_only_the_meta_that_was_supplied(): void
    {
        $corpus = $this->corpus();

        (new DocsHubWriteDocTool($this->errors, $corpus))->execute(
            array('path' => 'a.md', 'content' => 'Body', 'title' => 'A', 'order' => 2),
        );

        $this->assertSame(array('title' => 'A', 'order' => 2), $corpus->calls[0]['meta']);
    }

    public function test_write_reports_a_create_against_a_replace(): void
    {
        $corpus = $this->corpus();

        $corpus->created = true;
        $created = (new DocsHubWriteDocTool($this->errors, $corpus))
            ->execute(array('path' => 'a.md', 'content' => 'x'));

        $corpus->created = false;
        $replaced = (new DocsHubWriteDocTool($this->errors, $corpus))
            ->execute(array('path' => 'a.md', 'content' => 'x'));

        $this->assertTrue($created['data']['created']);
        $this->assertFalse($replaced['data']['created']);
    }

    public function test_write_requires_string_content(): void
    {
        $result = (new DocsHubWriteDocTool($this->errors, $this->corpus()))
            ->execute(array('path' => 'a.md', 'content' => array('nope')));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Deleting ──────────────────────────────────────────────────────────

    /**
     * A destructive tool that deletes by default is a data-loss path. The
     * WordPress tool defaults to a dry run and so does this one.
     */
    public function test_delete_defaults_to_a_dry_run(): void
    {
        $corpus = $this->corpus();

        $result = (new DocsHubDeleteDocTool($this->errors, $corpus))->execute(array('path' => 'a.md'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertFalse($result['data']['writes']);
        $this->assertSame(array(), $corpus->deleted, 'A dry run must not delete.');
    }

    public function test_delete_removes_when_the_dry_run_is_off(): void
    {
        $corpus = $this->corpus();

        $result = (new DocsHubDeleteDocTool($this->errors, $corpus))
            ->execute(array('path' => 'a.md', 'dry_run' => false));

        $this->assertTrue($result['success']);
        $this->assertSame(array('a.md'), $corpus->deleted);
    }

    public function test_delete_of_a_missing_document_is_not_found_even_in_a_dry_run(): void
    {
        $result = (new DocsHubDeleteDocTool($this->errors, $this->corpus(missing: true)))
            ->execute(array('path' => 'gone.md'));

        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── Scanning ──────────────────────────────────────────────────────────

    public function test_rebuild_reports_what_the_scan_found(): void
    {
        $corpus = $this->corpus();
        $corpus->scan = array(
            'indexed' => 4,
            'duplicates' => array('same title'),
            'unreadable' => array('broken.md'),
        );

        $result = (new DocsHubRebuildTool($this->errors, $corpus))->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['data']['indexed']);
        $this->assertStringContainsString('collide', $result['message']);
        $this->assertStringContainsString('could not be read', $result['message']);
    }

    /**
     * The WordPress tool offers `mode` and `reset`. Neither is declared here,
     * because there is no index to rebuild asynchronously or reset.
     */
    public function test_rebuild_declares_no_mode_or_reset(): void
    {
        $schema = (new DocsHubRebuildTool($this->errors, $this->corpus()))->getParametersSchema();

        $this->assertSame(array(), $schema['properties']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    private function corpus(
        bool $inactive = false,
        bool $refuse = false,
        bool $missing = false,
    ): DocsCorpusInterface {
        return new class ($inactive, $refuse, $missing) implements DocsCorpusInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = array();

            /** @var list<string> */
            public array $deleted = array();

            /** @var list<array<string, mixed>> */
            public array $archived = array();

            public bool $alreadyArchived = false;

            /** @var array<string, mixed> */
            public array $listing = array('documents' => array(), 'total' => 0);

            /** @var array<string, mixed> */
            public array $document = array('path' => 'a.md', 'title' => 'A', 'content' => 'Body', 'size' => 4, 'modified' => 0);

            /** @var array<string, mixed> */
            public array $scan = array('indexed' => 0, 'duplicates' => array(), 'unreadable' => array());

            public bool $created = true;

            public function __construct(
                private readonly bool $inactive,
                private readonly bool $refuse,
                private readonly bool $missing,
            ) {
            }

            public function isActive(): bool
            {
                return !$this->inactive;
            }

            public function list(string $subdirectory = '', int $limit = 100): array
            {
                $this->calls[] = array('subdirectory' => $subdirectory, 'limit' => $limit);
                $this->deny();

                return $this->listing;
            }

            public function read(string $path): array
            {
                $this->calls[] = array('read' => $path);
                $this->deny();

                return $this->document;
            }

            public function write(string $path, string $content, array $meta = array()): array
            {
                $this->calls[] = array('path' => $path, 'meta' => $meta);
                $this->deny();

                return array('path' => $path, 'title' => 'A', 'size' => 1, 'created' => $this->created);
            }

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return true;
            }

            public function setFields(string $path, array $fields): array
            {
                $this->calls[] = array('set_fields' => $path, 'fields' => $fields);

                return array('path' => $path, 'fields' => array(), 'title' => 'A');
            }

            public function archive(string $path, array $meta = array()): array
            {
                $this->archived[] = array('path' => $path, 'meta' => $meta);
                $this->deny();

                return array(
                    'path' => $path,
                    'title' => 'A',
                    'status' => 'archived',
                    'archived_at' => '2026-11-25T12:00:00+00:00',
                    'archived_by' => (int) ($meta['user_id'] ?? 0),
                    'archive_reason' => (string) ($meta['reason'] ?? ''),
                    'already_archived' => $this->alreadyArchived,
                );
            }

            public function rebuild(): array
            {
                return $this->scan;
            }

            public function maxBytes(): int
            {
                return 2097152;
            }

            public function allowedExtensions(): array
            {
                return array('md', 'txt');
            }

            private function deny(): void
            {
                if ($this->refuse) {
                    throw new \InvalidArgumentException('The path is not allowed.');
                }

                if ($this->missing) {
                    throw new NotFoundException('No document.');
                }
            }
        };
    }
}
