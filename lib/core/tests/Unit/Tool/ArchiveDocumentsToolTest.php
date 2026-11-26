<?php

/**
 * `archive_documents`.
 *
 * The corpus adapter proves the frontmatter mechanics (`FilesystemDocsCorpusTest`
 * — body preserved, timestamp immutable, escaping paths refused). What is left
 * here is the tool's own contract: it defaults to a dry run, it reports per-path
 * outcomes instead of failing a whole batch on one bad path, and it records the
 * reason and the actor on the document.
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
use Nvoos\Core\Tool\ArchiveDocumentsTool;
use PHPUnit\Framework\TestCase;

final class ArchiveDocumentsToolTest extends TestCase
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
    }

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->tool($this->corpus());

        $this->assertSame('archive_documents', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('destructive', $tool->getCapabilityFlags());
    }

    public function test_it_takes_paths_rather_than_wordpress_ids(): void
    {
        $schema = $this->tool($this->corpus())->getParametersSchema();

        $this->assertArrayHasKey('paths', $schema['properties']);
        $this->assertArrayNotHasKey('document_ids', $schema['properties']);
        $this->assertSame(array('paths'), $schema['required']);
    }

    public function test_everything_refuses_a_host_without_a_corpus(): void
    {
        $result = $this->tool($this->corpus(inactive: true))->execute(array('paths' => array('a.md')));

        $this->assertFalse($result['success']);
        $this->assertSame('no_docs_corpus', $result['error']['code']);
    }

    // ─── The default is a dry run ──────────────────────────────────────────

    /**
     * A tool that withdraws documents by default is a data-loss path.
     */
    public function test_it_defaults_to_a_dry_run(): void
    {
        $corpus = $this->corpus();

        $result = $this->tool($corpus)->execute(array('paths' => array('a.md')));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertFalse($result['data']['writes']);
        $this->assertSame(array(), $corpus->archived, 'A dry run must not write.');
    }

    public function test_a_dry_run_reports_what_would_be_withdrawn(): void
    {
        $corpus = $this->corpus();
        $corpus->document = array(
            'path' => 'guides/old.md',
            'title' => 'Old Guide',
            'content' => 'x',
            'size' => 1,
            'modified' => 0,
        );

        $result = $this->tool($corpus)->execute(array('paths' => array('guides/old.md')));

        $this->assertSame(array('guides/old.md'), $result['data']['would_change']['paths']);
        $this->assertSame('Old Guide', $result['data']['would_archive'][0]['title']);
    }

    // ─── Archiving ─────────────────────────────────────────────────────────

    public function test_it_records_the_actor_and_the_reason(): void
    {
        $corpus = $this->corpus();

        $result = $this->tool($corpus)->execute(
            array('paths' => array('a.md'), 'archive_reason' => 'Superseded', 'dry_run' => false),
            array('user_id' => 11),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('a.md', $corpus->archived[0]['path']);
        $this->assertSame('Superseded', $corpus->archived[0]['meta']['reason']);
        $this->assertSame(11, $corpus->archived[0]['meta']['user_id']);
        $this->assertSame(11, $result['data']['archived'][0]['archived_by']);
    }

    /**
     * One bad path in a batch must not lose the good ones. A batch that fails
     * whole is a batch an operator re-runs, and a re-run re-stamps nothing but
     * costs a round trip.
     */
    public function test_a_batch_reports_each_outcome_separately(): void
    {
        $corpus = $this->corpus(missing: array('gone.md'), refuse: array('../escape.md'));

        $result = $this->tool($corpus)->execute(
            array('paths' => array('ok.md', 'gone.md', '../escape.md'), 'dry_run' => false),
            array('user_id' => 2),
        );

        $this->assertTrue($result['success'], 'A partial batch is still a completed call.');
        $this->assertCount(1, $result['data']['archived']);
        $this->assertSame(array('gone.md'), $result['data']['not_found']);
        $this->assertSame('../escape.md', $result['data']['refused'][0]['path']);
        $this->assertStringContainsString('1 not found', $result['message']);
        $this->assertStringContainsString('1 refused', $result['message']);
    }

    public function test_an_already_archived_document_is_reported_as_such(): void
    {
        $corpus = $this->corpus();
        $corpus->alreadyArchived = true;

        $result = $this->tool($corpus)->execute(
            array('paths' => array('a.md'), 'dry_run' => false),
            array('user_id' => 2),
        );

        $this->assertCount(1, $result['data']['already_archived']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    public function test_an_empty_path_list_is_a_validation_failure(): void
    {
        $result = $this->tool($this->corpus())->execute(array('paths' => array()));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_oversized_batch_is_refused(): void
    {
        $result = $this->tool($this->corpus())->execute(
            array('paths' => array_map(static fn (int $n): string => "doc-{$n}.md", range(1, 101))),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $missing Paths that raise not-found.
     * @param list<string> $refuse  Paths that raise a refusal.
     */
    private function corpus(
        bool $inactive = false,
        array $missing = array(),
        array $refuse = array(),
    ): DocsCorpusInterface {
        return new class ($inactive, $missing, $refuse) implements DocsCorpusInterface {
            /** @var list<array<string, mixed>> */
            public array $archived = array();

            /** @var array<string, mixed> */
            public array $document = array('path' => 'a.md', 'title' => 'A', 'content' => 'x', 'size' => 1, 'modified' => 0);

            public bool $alreadyArchived = false;

            /**
             * @param list<string> $missing
             * @param list<string> $refuse
             */
            public function __construct(
                private readonly bool $inactive,
                private readonly array $missing,
                private readonly array $refuse,
            ) {
            }

            public function isActive(): bool
            {
                return !$this->inactive;
            }

            public function list(string $subdirectory = '', int $limit = 100): array
            {
                return array('documents' => array(), 'total' => 0);
            }

            public function read(string $path): array
            {
                $this->deny($path);

                return $this->document;
            }

            public function write(string $path, string $content, array $meta = array()): array
            {
                return array('path' => $path, 'title' => 'A', 'size' => 1, 'created' => true);
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function expiring(string $asOf, int $limit = 100): array
            {
                return array('documents' => array(), 'total' => 0);
            }

            public function setFields(string $path, array $fields): array
            {
                return array('path' => $path, 'fields' => array(), 'title' => 'A');
            }

            public function archive(string $path, array $meta = array()): array
            {
                $this->deny($path);
                $this->archived[] = array('path' => $path, 'meta' => $meta);

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
                return array('indexed' => 0, 'duplicates' => array(), 'unreadable' => array());
            }

            public function maxBytes(): int
            {
                return 2097152;
            }

            public function allowedExtensions(): array
            {
                return array('md', 'txt');
            }

            private function deny(string $path): void
            {
                if (in_array($path, $this->missing, true)) {
                    throw new NotFoundException('No document at ' . $path);
                }

                if (in_array($path, $this->refuse, true)) {
                    throw new \InvalidArgumentException('The path is not allowed.');
                }
            }
        };
    }

    private function tool(DocsCorpusInterface $corpus): ArchiveDocumentsTool
    {
        return new ArchiveDocumentsTool($this->errors, $corpus);
    }
}
