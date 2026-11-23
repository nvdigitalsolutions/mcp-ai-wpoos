<?php

/**
 * The OKF writer.
 *
 * These tests exercise the writer against the **reader that ships with it**, not
 * against a string fixture: a writer whose output the package cannot read back is
 * not a writer. That is also what pins the quoting rules — `title: 2026` must
 * survive as a string, because YAML would otherwise hand back an int.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Integration\Okf;

use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Domain\Okf\OkfWriteException;
use Nvoos\Core\Domain\Okf\OkfWriter;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleRoot;
use PHPUnit\Framework\TestCase;

final class OkfWriterTest extends TestCase
{
    private string $directory;

    private FilesystemBundleRoot $root;

    private OkfWriter $writer;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/okf-writer-' . \bin2hex(\random_bytes(6));

        \mkdir($this->directory, 0775, true);

        $this->root   = new FilesystemBundleRoot($this->directory);
        $this->writer = new OkfWriter($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function test_a_written_concept_reads_back_through_the_reader(): void
    {
        $this->writer->writeConcept(
            'tables/orders',
            array(
                'type'        => 'BigQuery Table',
                'title'       => '2026',
                'description' => 'It "quotes" fine',
                'tags'        => array('sales', 'orders'),
                'verified'    => array(
                    array('by' => 'process:ci-lint', 'at' => '2026-07-31T12:05:00Z'),
                ),
            ),
            "# Schema\n\nOne row per order.",
        );

        $concept = (new OkfReader($this->root))->getConcept('tables/orders');

        $this->assertSame('BigQuery Table', $concept['frontmatter']['type']);

        // A numeric-looking title must stay a string; YAML 1.2 would read the
        // bare form as an int and break "no re-serialisation" on the next write.
        $this->assertSame('2026', $concept['frontmatter']['title']);

        // The quote is escaped on write and unescaped on read.
        $this->assertSame('It "quotes" fine', $concept['frontmatter']['description']);

        $this->assertSame(array('sales', 'orders'), $concept['frontmatter']['tags']);
        $this->assertSame('process:ci-lint', $concept['frontmatter']['verified'][0]['by']);
        $this->assertStringContainsString('# Schema', $concept['body']);
    }

    public function test_a_concept_without_a_type_is_refused(): void
    {
        $this->expectException(OkfWriteException::class);
        $this->expectExceptionMessage('non-empty "type"');

        $this->writer->writeConcept('notes/empty', array('title' => 'No type'));
    }

    public function test_a_concept_id_cannot_escape_the_bundle(): void
    {
        $this->expectException(OkfWriteException::class);
        $this->expectExceptionMessage('cannot contain ".."');

        $this->writer->writeConcept('../outside', array('type' => 'Note'));
    }

    public function test_reserved_filenames_cannot_be_written_as_concepts(): void
    {
        $this->expectException(OkfWriteException::class);
        $this->expectExceptionMessage('reserved');

        $this->writer->writeConcept('index', array('type' => 'Note'));
    }

    public function test_delete_retires_the_concept_instead_of_destroying_it(): void
    {
        $path = $this->writer->writeConcept('notes/old', array('type' => 'Note'));

        $this->assertTrue($this->writer->deleteConcept('notes/old'));
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($path . '.deleted');

        // A second delete has nothing to do, and says so.
        $this->assertFalse($this->writer->deleteConcept('notes/old'));
    }

    public function test_a_burst_of_log_entries_shares_one_date_heading(): void
    {
        $this->writer->appendLog('', 'Created the bundle.', 'Init');
        $this->writer->appendLog('', 'Added the orders table.', 'Create');

        $log = (string) \file_get_contents($this->directory . '/log.md');

        $this->assertSame(1, \substr_count($log, '## ' . \gmdate('Y-m-d')));
        $this->assertStringContainsString('* **Init**: Created the bundle.', $log);
        $this->assertStringContainsString('* **Create**: Added the orders table.', $log);
    }

    public function test_regenerating_an_index_lists_concepts_with_their_descriptions(): void
    {
        $this->writer->writeConcept('orders', array(
            'type'        => 'BigQuery Table',
            'title'       => 'Orders',
            'description' => 'One row per completed order.',
        ));

        $this->writer->writeConcept('customers', array('type' => 'BigQuery Table', 'title' => 'Customers'));

        $this->writer->regenerateIndex();

        $index = (string) \file_get_contents($this->directory . '/index.md');

        $this->assertStringContainsString('## Concepts', $index);
        $this->assertStringContainsString('* [Customers](customers.md)', $index);
        $this->assertStringContainsString('* [Orders](orders.md) - One row per completed order.', $index);
    }

    public function test_regenerating_an_index_keeps_the_bundle_frontmatter(): void
    {
        // v0.3 §8.1: a producer that regenerates the root index must preserve
        // okf_version, title, description and license.
        \file_put_contents(
            $this->directory . '/index.md',
            "---\nokf_version: \"0.3\"\nlicense: CC-BY-4.0\n---\n\n# Old\n",
        );

        $this->writer->writeConcept('orders', array('type' => 'BigQuery Table', 'title' => 'Orders'));
        $this->writer->regenerateIndex();

        $index = (string) \file_get_contents($this->directory . '/index.md');

        $this->assertStringStartsWith("---\nokf_version: \"0.3\"", $index);
        $this->assertStringContainsString('license: CC-BY-4.0', $index);
        $this->assertStringNotContainsString('# Old', $index);
        $this->assertStringContainsString('* [Orders](orders.md)', $index);
    }

    private function remove(string $path): void
    {
        if (! \is_dir($path)) {
            return;
        }

        foreach ((array) \scandir($path) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path . '/' . $entry;

            \is_dir($child) ? $this->remove($child) : @\unlink($child);
        }

        @\rmdir($path);
    }
}
