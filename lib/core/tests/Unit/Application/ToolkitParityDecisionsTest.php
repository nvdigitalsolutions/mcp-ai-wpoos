<?php

/**
 * Tests for the toolkit parity decision register.
 *
 * The register exists so "not built yet" and "will never be built" stop reading
 * identically on the parity table. That only works if the register is checked,
 * so these tests pin the four ways it can rot: an unknown decision word, a
 * missing reason, a slug no toolkit declares, and a slug recorded as not-ported
 * that is in fact served. The last is the reason the class exists at all.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application;

use Nvoos\Core\Application\Tool\ToolkitParityDecisions;
use PHPUnit\Framework\TestCase;

final class ToolkitParityDecisionsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = array();
        ToolkitParityDecisions::flush();

        parent::tearDown();
    }

    // ─── Loading ───────────────────────────────────────────────────────────

    public function testAMissingRegisterIsEmptyRatherThanAnError(): void
    {
        $register = new ToolkitParityDecisions($this->path() . '/no-such-register.json');

        $this->assertSame(array(), $register->all());
        $this->assertNull($register->decisionFor('anything'));
        $this->assertFalse($register->isDeclined('anything'));
        $this->assertFalse($register->isDeferred('anything'));
    }

    public function testAMalformedRegisterThrowsRatherThanReadingAsEmpty(): void
    {
        $path = $this->registerRaw('{ this is not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('decisions');

        (new ToolkitParityDecisions($path))->all();
    }

    public function testAJsonDocumentWithoutTheDecisionsMapThrows(): void
    {
        $path = $this->registerRaw('{"something_else": []}');

        $this->expectException(\RuntimeException::class);

        (new ToolkitParityDecisions($path))->all();
    }

    // ─── The two words ─────────────────────────────────────────────────────

    public function testDeclinedAndDeferredAreDistinguished(): void
    {
        $register = $this->register(array(
            'gone'    => array('decision' => 'declined', 'reason' => 'the served tool answers it'),
            'blocked' => array('decision' => 'deferred', 'reason' => 'needs an uploader'),
        ));

        $this->assertSame('declined', $register->decisionFor('gone'));
        $this->assertTrue($register->isDeclined('gone'));
        $this->assertFalse($register->isDeferred('gone'));

        $this->assertSame('deferred', $register->decisionFor('blocked'));
        $this->assertTrue($register->isDeferred('blocked'));
        $this->assertFalse($register->isDeclined('blocked'));
    }

    /**
     * The word is lower-cased on read, so a capital letter is not a typo. A
     * misspelling still is, and it must not silently become a decision.
     */
    public function testTheDecisionWordIsNormalisedButAMisspellingIsNotAccepted(): void
    {
        $register = $this->register(array(
            'shouted'  => array('decision' => '  DECLINED ', 'reason' => 'normalised'),
            'typo'     => array('decision' => 'declinded', 'reason' => 'misspelt'),
        ));

        $this->assertTrue($register->isDeclined('shouted'));
        $this->assertFalse($register->isDeclined('typo'));
        $this->assertSame('declinded', $register->decisionFor('typo'));
    }

    // ─── Violations ────────────────────────────────────────────────────────

    public function testAConsistentRegisterHasNoViolations(): void
    {
        $register = $this->register(array(
            'gone' => array('decision' => 'declined', 'reason' => 'superseded by a served tool'),
        ));

        $violations = $register->violations(
            array('served_one' => true, 'gone' => true),
            array('served_one' => true)
        );

        $this->assertSame(array(), $violations);
    }

    public function testAnUnknownDecisionWordIsAViolation(): void
    {
        $register = $this->register(array(
            'wobbly' => array('decision' => 'maybe', 'reason' => 'a decision that is not one'),
        ));

        $violations = $register->violations(array('wobbly' => true), array());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('unknown decision', $violations[0]);
        $this->assertStringContainsString('declined, deferred', $violations[0]);
    }

    public function testAnEmptyDecisionIsReportedAsEmptyRatherThanGuessed(): void
    {
        $register = $this->register(array(
            'blank' => array('decision' => '', 'reason' => 'no word'),
        ));

        $violations = $register->violations(array('blank' => true), array());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('(empty)', $violations[0]);
    }

    public function testADecisionWithoutAReasonIsAViolation(): void
    {
        $register = $this->register(array(
            'mute' => array('decision' => 'declined', 'reason' => '   '),
        ));

        $violations = $register->violations(array('mute' => true), array());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('no reason', $violations[0]);
    }

    /**
     * A decision about a slug nobody declares is either stale — the tool moved or
     * was renamed — or a typo. Both are worth failing on.
     */
    public function testADecisionForASlugNoToolkitDeclaresIsAStaleViolation(): void
    {
        $register = $this->register(array(
            'vanished' => array('decision' => 'declined', 'reason' => 'recorded once, never real'),
        ));

        $violations = $register->violations(array('other' => true), array());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('no toolkit declares it', $violations[0]);
    }

    /**
     * The contradiction: a decision not to port cannot stand next to a tool that
     * is served. This is the check that stops a stale register being quoted.
     */
    public function testADecisionForAServedSlugIsAContradiction(): void
    {
        $register = $this->register(array(
            'shipped' => array('decision' => 'deferred', 'reason' => 'written before the port landed'),
        ));

        $violations = $register->violations(
            array('shipped' => true),
            array('shipped' => true)
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('but is served', $violations[0]);
    }

    public function testOneSlugCanBreakTheRegisterInTwoWaysAtOnce(): void
    {
        $register = $this->register(array(
            'double' => array('decision' => 'unsure', 'reason' => ''),
        ));

        $violations = $register->violations(array('double' => true), array('double' => true));

        // Unknown word and empty reason; the served contradiction is not reached
        // because the unknown word short-circuits the slug.
        $this->assertCount(2, $violations);
        $this->assertStringContainsString('unknown decision', $violations[0]);
        $this->assertStringContainsString('no reason', $violations[1]);
    }

    // ─── The register that ships ───────────────────────────────────────────

    /**
     * The file in the tree is data, so nothing else type-checks it. A dropped
     * reason or a made-up decision word should fail here rather than in review.
     */
    public function testTheShippedRegisterIsWellFormed(): void
    {
        $register = new ToolkitParityDecisions();

        $this->assertFileExists($register->path());

        $all = $register->all();

        $this->assertNotSame(array(), $all, 'The shipped register records at least one decision.');

        foreach ($all as $slug => $row) {
            $this->assertContains(
                $row['decision'],
                ToolkitParityDecisions::VOCABULARY,
                "{$slug} records a decision outside the vocabulary."
            );

            $this->assertGreaterThan(
                40,
                strlen($row['reason']),
                "{$slug} needs a reason a reader can act on, not a label."
            );
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Write a register from a decision map.
     *
     * @param array<string, array{decision: string, reason: string}> $decisions
     */
    private function register(array $decisions): ToolkitParityDecisions
    {
        return new ToolkitParityDecisions(
            $this->registerRaw((string) json_encode(array('decisions' => $decisions)))
        );
    }

    private function registerRaw(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'nvoos-parity-decisions-');

        file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function path(): string
    {
        return (string) sys_get_temp_dir();
    }
}
