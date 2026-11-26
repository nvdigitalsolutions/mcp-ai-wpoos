<?php

/**
 * Tests for the tool class scanner.
 *
 * The scanner decides which classes the readiness gate can see. When it could
 * not see `final class`, `slug_mismatch` silently stopped working for the
 * newest tools — 25 of them (issue #8201). These pin the declaration match so
 * the same drift cannot happen quietly again.
 *
 * @package Nvoos\Core\Tests
 * @since   2.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\ToolClassScanner;
use PHPUnit\Framework\TestCase;

final class ToolClassScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nvoos-scanner-' . bin2hex(random_bytes(6));

        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree();
    }

    // ─── the defect (#8201) ────────────────────────────────────────────

    public function test_it_finds_a_final_class(): void
    {
        $this->write('FinalTool.php', <<<'PHP'
<?php
namespace Fixtures;

final class FinalTool extends AbstractTool
{
    public function getSlug(): string { return 'final_tool'; }
}
PHP);

        $found = $this->scan();

        $this->assertCount(1, $found, 'A final class must be visible to the scanner.');

        $entry = array_values($found)[0];

        $this->assertSame('FinalTool', $entry['class']);
        $this->assertSame('Fixtures\\FinalTool', $entry['fqcn']);
        $this->assertSame('final_tool', $entry['slug']);
    }

    public function test_declaration_reads_a_final_class(): void
    {
        $this->assertSame(
            array('name' => 'Foo', 'abstract' => false),
            ToolClassScanner::declaration("<?php\nfinal class Foo extends AbstractTool {}\n")
        );
    }

    public function test_declaration_reads_a_final_readonly_class(): void
    {
        $this->assertSame(
            array('name' => 'Foo', 'abstract' => false),
            ToolClassScanner::declaration("<?php\nfinal readonly class Foo implements ToolInterface {}\n")
        );
    }

    // ─── the cases that already worked ─────────────────────────────────

    public function test_it_finds_a_plain_class(): void
    {
        $this->write('PlainTool.php', <<<'PHP'
<?php
namespace Fixtures;

class PlainTool implements ToolInterface
{
    public function getSlug(): string { return 'plain_tool'; }
}
PHP);

        $found = $this->scan();

        $this->assertCount(1, $found);
        $this->assertSame('plain_tool', array_values($found)[0]['slug']);
    }

    public function test_it_finds_a_readonly_class(): void
    {
        $this->write('ReadonlyTool.php', <<<'PHP'
<?php
namespace Fixtures;

readonly class ReadonlyTool implements ToolInterface
{
    public function getSlug(): string { return 'readonly_tool'; }
}
PHP);

        $found = $this->scan();

        $this->assertCount(1, $found);
        $this->assertSame('ReadonlyTool', array_values($found)[0]['class']);
    }

    // ─── exclusions ────────────────────────────────────────────────────

    public function test_it_skips_an_abstract_class(): void
    {
        $this->write('AbstractThing.php', <<<'PHP'
<?php
namespace Fixtures;

abstract class AbstractThing extends AbstractTool
{
    public function getSlug(): string { return 'abstract_thing'; }
}
PHP);

        $this->assertSame(array(), $this->scan(), 'An abstract class declares no servable tool.');
    }

    /**
     * The old skip was an unanchored `/abstract\s+class/` over the whole file,
     * so prose in a docblock hid a concrete declaration. The skip now reads the
     * modifiers of the declaration itself.
     */
    public function test_a_docblock_mentioning_an_abstract_class_does_not_hide_the_declaration(): void
    {
        $this->write('ConcreteTool.php', <<<'PHP'
<?php
namespace Fixtures;

/**
 * Unlike the abstract class this replaces, this one is servable.
 */
final class ConcreteTool extends AbstractTool
{
    public function getSlug(): string { return 'concrete_tool'; }
}
PHP);

        $found = $this->scan();

        $this->assertCount(1, $found);
        $this->assertSame('concrete_tool', array_values($found)[0]['slug']);
    }

    public function test_it_ignores_a_file_that_declares_no_class(): void
    {
        $this->write('helpers.php', "<?php\n\nfunction helper(): string { return 'x'; }\n");

        $this->assertSame(array(), $this->scan());
    }

    public function test_it_ignores_a_file_that_declares_no_tool(): void
    {
        $this->write('NotATool.php', "<?php\n\nfinal class NotATool\n{\n}\n");

        $this->assertSame(array(), $this->scan());
    }

    public function test_it_ignores_non_php_files(): void
    {
        $this->write('notes.txt', 'final class Ignored implements ToolInterface {}');

        $this->assertSame(array(), $this->scan());
    }

    // ─── the slug read ────────────────────────────────

    /**
     * `return self::SLUG;` is opaque to a source scan, and a lazy match over the
     * whole file runs past it into `getName()`. That read the catalogue tool as
     * answering to `List MCP Tools` instead of `list_mcp_tools`.
     */
    public function test_it_resolves_a_slug_returned_from_a_class_constant(): void
    {
        $this->write('ConstantTool.php', <<<'PHP'
<?php
namespace Fixtures;

final class ConstantTool extends AbstractTool
{
    public const SLUG = 'constant_tool';

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function getName(): string
    {
        return 'Constant Tool';
    }
}
PHP);

        $entry = array_values($this->scan())[0];

        $this->assertSame('constant_tool', $entry['slug']);
        $this->assertNotSame('Constant Tool', $entry['slug'], 'The display name is not the slug.');
    }

    // ─── the constructor read ──────────────────────────────────────────

    public function test_it_reads_the_constructor_parameter_types(): void
    {
        $this->write('CtorTool.php', <<<'PHP'
<?php
namespace Fixtures;

final class CtorTool extends AbstractTool
{
    public function __construct(
        private readonly ContentStoreInterface $content,
        ErrorFactoryInterface $errors,
    ) {
    }

    public function getSlug(): string { return 'ctor_tool'; }
}
PHP);

        $found = $this->scan();

        $this->assertSame(
            array('ContentStoreInterface', 'ErrorFactoryInterface'),
            array_values($found)[0]['ctor']
        );
    }

    // ─── helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, array{class: string, fqcn: string, file: string, slug: string, ctor: array<int, string>}>
     */
    private function scan(): array
    {
        return (new ToolClassScanner())->scan($this->root);
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->root . '/' . $name, $body);
    }

    private function removeTree(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }
}
