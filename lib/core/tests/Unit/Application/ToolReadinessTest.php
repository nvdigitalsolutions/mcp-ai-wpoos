<?php

/**
 * Tests for the tool readiness manifest.
 *
 * The manifest exists to stop a hollow tool from being registered under a slug
 * that promises real work, so these tests pin the safe defaults: an unknown
 * slug is never servable, a missing file serves nothing, and the vocabulary is
 * closed.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application;

use Nvoos\Core\Application\Tool\ToolReadiness;
use PHPUnit\Framework\TestCase;

final class ToolReadinessTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = array();
        ToolReadiness::flush();

        parent::tearDown();
    }

    public function testVocabularyIsClosed(): void
    {
        foreach (array('ready', 'pure', 'delegated', 'unverified', 'quarantined', 'unavailable') as $value) {
            $this->assertTrue(ToolReadiness::isKnown($value), "{$value} should be known");
        }

        $this->assertFalse(ToolReadiness::isKnown('probably_fine'));
        $this->assertFalse(ToolReadiness::isKnown(''));
    }

    public function testOnlyThreeClassesAreServable(): void
    {
        $this->assertTrue(ToolReadiness::isServableClass(ToolReadiness::READY));
        $this->assertTrue(ToolReadiness::isServableClass(ToolReadiness::PURE));
        $this->assertTrue(ToolReadiness::isServableClass(ToolReadiness::DELEGATED));

        $this->assertFalse(ToolReadiness::isServableClass(ToolReadiness::UNVERIFIED));
        $this->assertFalse(ToolReadiness::isServableClass(ToolReadiness::QUARANTINED));
        $this->assertFalse(ToolReadiness::isServableClass(ToolReadiness::UNAVAILABLE));
    }

    public function testPackagedManifestIsInternallyConsistent(): void
    {
        $manifest = new ToolReadiness();

        $this->assertFileExists($manifest->path());
        $this->assertGreaterThan(0, count($manifest->all()));
        $this->assertSame(array(), $manifest->violations());
    }

    public function testUnknownSlugIsUnverifiedAndNotServable(): void
    {
        $manifest = new ToolReadiness();

        $this->assertSame(ToolReadiness::UNVERIFIED, $manifest->classify('no_such_tool'));
        $this->assertFalse($manifest->isServable('no_such_tool'));
        $this->assertNull($manifest->get('no_such_tool'));
        $this->assertNull($manifest->reason('no_such_tool'));
    }

    public function testMissingFileServesNothing(): void
    {
        $manifest = new ToolReadiness(sys_get_temp_dir() . '/nvoos-missing-manifest.php');

        $this->assertSame(array(), $manifest->all());
        $this->assertSame(array(), $manifest->servable());
        $this->assertFalse($manifest->isServable('base64'));
    }

    public function testAnnotationsKeepOnlyTheSpecificationHints(): void
    {
        $path = $this->writeManifest(
            array(
                'demo' => array(
                    'class'       => 'Nvoos\\Core\\Tool\\TimeAgoTool',
                    'readiness'   => 'pure',
                    'annotations' => array(
                        'readOnlyHint'   => true,
                        'openWorldHint'  => false,
                        'destructiveHint' => 1,
                        'notASpecHint'   => true,
                    ),
                ),
            )
        );

        $manifest = new ToolReadiness($path);

        $this->assertSame(
            array(
                'readOnlyHint'    => true,
                'destructiveHint' => true,
                'openWorldHint'   => false,
            ),
            $manifest->annotations('demo')
        );
    }

    public function testServableExcludesQuarantinedAndUnverified(): void
    {
        $path = $this->writeManifest(
            array(
                'real'   => array('class' => 'Nvoos\\Core\\Tool\\TimeAgoTool', 'readiness' => 'ready'),
                'stub'   => array('class' => 'Nvoos\\Core\\Tool\\FormatDateTool', 'readiness' => 'quarantined'),
                'unsure' => array('class' => 'Nvoos\\Core\\Tool\\FormatBytesTool', 'readiness' => 'unverified'),
            )
        );

        $manifest = new ToolReadiness($path);

        $this->assertSame(array('real'), array_keys($manifest->servable()));
        $this->assertTrue($manifest->isServable('real'));
        $this->assertFalse($manifest->isServable('stub'));
        $this->assertFalse($manifest->isServable('unsure'));
    }

    public function testHashCoversTheServableSetOnly(): void
    {
        $base = array(
            'real'   => array('class' => 'Nvoos\\Core\\Tool\\TimeAgoTool', 'readiness' => 'ready'),
            'stub'   => array('class' => 'Nvoos\\Core\\Tool\\FormatDateTool', 'readiness' => 'quarantined'),
        );

        $first = new ToolReadiness($this->writeManifest($base, 'a'));
        $same  = new ToolReadiness($this->writeManifest($base, 'b'));

        $this->assertSame($first->hash(), $same->hash(), 'The hash must be stable across files.');

        $changed = $base;
        $changed['real']['annotations'] = array('destructiveHint' => true);
        $third = new ToolReadiness($this->writeManifest($changed, 'c'));

        $this->assertNotSame($first->hash(), $third->hash(), 'A served definition change must move the hash.');

        $quarantinedChanged = $base;
        $quarantinedChanged['stub']['readiness'] = 'unavailable';
        $fourth = new ToolReadiness($this->writeManifest($quarantinedChanged, 'd'));

        $this->assertSame($first->hash(), $fourth->hash(), 'A non-servable row must not move the hash.');
    }

    public function testViolationsReportVocabularyAndMissingClasses(): void
    {
        $path = $this->writeManifest(
            array(
                'bad_vocabulary' => array('class' => 'Nvoos\\Core\\Tool\\TimeAgoTool', 'readiness' => 'probably_fine'),
                'ghost'          => array('class' => 'Nvoos\\Core\\Tool\\DoesNotExist', 'readiness' => 'ready'),
                'no_class'       => array('readiness' => 'ready'),
            )
        );

        $violations = (new ToolReadiness($path))->violations();

        $this->assertCount(3, $violations);
        $this->assertStringContainsString('outside the vocabulary', $violations[0]);
        $this->assertStringContainsString('does not exist', $violations[1]);
        $this->assertStringContainsString('no class', $violations[2]);
    }

    /**
     * @param array<string, array<string, mixed>> $tools Rows keyed by slug.
     */
    private function writeManifest(array $tools, string $suffix = 'x'): string
    {
        $path = sys_get_temp_dir() . '/nvoos-readiness-' . $suffix . '-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents(
            $path,
            "<?php\nreturn array('version' => 1, 'tools' => " . var_export($tools, true) . ");\n"
        );

        $this->tempFiles[] = $path;

        return $path;
    }
}
