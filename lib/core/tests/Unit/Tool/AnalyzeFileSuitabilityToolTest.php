<?php

/**
 * `analyze_file_suitability`.
 *
 * The tool exists to answer one question honestly, so the tests are organised around
 * the invariant that makes the answer honest: `suitable === (warnings === [])`. Every
 * case asserts it, because the WordPress original reports a warning and a `suitable:
 * true` for the same input.
 *
 * Content checks run against real temporary files rather than a mocked filesystem —
 * the point of those checks is what the bytes are, and a mock would be asserting the
 * mock.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\AnalyzeFileSuitabilityLocalTool;
use PHPUnit\Framework\TestCase;

final class AnalyzeFileSuitabilityToolTest extends TestCase
{
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || ( \is_array($value) && isset($value['error']) )
        );

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => $code, 'message' => $message ),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'validation_failed', 'message' => $message ),
            )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $this->temporary = array();
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = new AnalyzeFileSuitabilityLocalTool($this->errors);

        $this->assertSame('analyze_file_suitability', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('local-only', $tool->getCapabilityFlags());
        $this->assertNotContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertNotContains('external-api', $tool->getCapabilityFlags());
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_a_file_id_is_required(): void
    {
        $result = $this->tool($this->storeWith(null))->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_without_a_file_store_says_so(): void
    {
        $result = (new AnalyzeFileSuitabilityLocalTool($this->errors, null))
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('file_store_unavailable', $result['error']['code']);
    }

    public function test_an_unknown_purpose_is_refused(): void
    {
        $result = $this->tool($this->storeWith($this->file('notes.txt', 'text/plain', "hello there\n")))
            ->execute(array( 'file_id' => 1, 'purpose' => 'marketing' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_missing_file_is_named(): void
    {
        $result = $this->tool($this->storeWith(null))->execute(array( 'file_id' => 99 ));

        $this->assertSame('file_not_found', $result['error']['code']);
    }

    public function test_a_file_the_caller_may_not_read_answers_like_a_missing_one(): void
    {
        $store = $this->storeWith($this->file('notes.txt', 'text/plain', 'hello'), false);

        $result = $this->tool($store)->execute(
            array( 'file_id' => 1 ),
            array( 'user_id' => 42 )
        );

        // Not `forbidden`: confirming existence is information too.
        $this->assertSame('file_not_found', $result['error']['code']);
    }

    // ── Suitable ─────────────────────────────────────────────────────────────

    public function test_a_utf8_text_file_is_suitable_for_assistants(): void
    {
        $result = $this->analyse($this->file('notes.txt', 'text/plain', "A short document about widgets.\n"));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['suitable']);
        $this->assertSame(array(), $result['data']['warnings']);
        $this->assertTrue($result['data']['content_checked']);
        $this->assertSame('txt', $result['data']['file_type']);
        $this->assertSame('assistants', $result['data']['purpose']);
    }

    // ── Blocking ─────────────────────────────────────────────────────────────

    public function test_a_file_over_the_ceiling_is_not_suitable(): void
    {
        // Metadata-only size (the path does not exist) still blocks: the ceiling is a
        // property of the purpose, not of the bytes.
        $result = $this->analyse($this->file('big.txt', 'text/plain', '', 600 * 1024 * 1024));

        $this->assertFalse($result['data']['suitable']);
        $this->assertNotEmpty($result['data']['warnings']);
        $this->assertStringContainsString('512 MB', $result['data']['max_size_human']);
    }

    public function test_an_unaccepted_format_is_not_suitable_and_lists_what_is(): void
    {
        $result = $this->analyse($this->file('clip.mp4', 'video/mp4', 'binary'));

        $this->assertFalse($result['data']['suitable']);

        $warnings = \implode(' ', $result['data']['warnings']);

        $this->assertStringContainsString('mp4', $warnings);
        $this->assertStringContainsString('pdf', $warnings);
    }

    public function test_a_spreadsheet_for_a_vector_store_is_blocked_with_the_fix(): void
    {
        $result = $this->analyse($this->file('rows.csv', 'text/csv', "a,b\n1,2\n"));

        $this->assertFalse($result['data']['suitable']);

        $advice = \implode(' ', $result['data']['recommendations']);

        $this->assertStringContainsString('Convert the CSV', $advice);
    }

    public function test_an_empty_text_file_is_not_suitable(): void
    {
        $result = $this->analyse($this->file('empty.txt', 'text/plain', ''));

        $this->assertFalse($result['data']['suitable']);
        $this->assertStringContainsString('empty', \implode(' ', $result['data']['warnings']));
    }

    public function test_a_jsonl_file_with_no_parseable_line_is_not_suitable(): void
    {
        $result = $this->analyse(
            $this->file('rows.jsonl', 'application/jsonl', "this is not json\nneither is this\n"),
            'fine-tune'
        );

        $this->assertFalse($result['data']['suitable']);
        $this->assertStringContainsString('parse as JSON', \implode(' ', $result['data']['warnings']));
    }

    // ── Advisory, and the invariant ──────────────────────────────────────────

    public function test_text_that_is_not_utf8_is_advice_not_a_refusal(): void
    {
        $result = $this->analyse($this->file('legacy.txt', 'text/plain', "\xFF\xFEbroken bytes"));

        $this->assertTrue($result['data']['suitable'], 'Encoding is advice; WordPress also keeps this usable.');
        $this->assertSame(array(), $result['data']['warnings']);
        $this->assertStringContainsString('not valid UTF-8', \implode(' ', $result['data']['recommendations']));
    }

    public function test_a_one_pixel_image_is_advice_not_a_refusal(): void
    {
        // The WordPress original adds a warning here and still answers `suitable: true`.
        // This tool says the same thing in the recommendations, so the two fields agree.
        $result = $this->analyse(
            $this->file('tiny.png', 'image/png', \base64_decode(self::ONE_PIXEL_PNG, true)),
            'vision'
        );

        $this->assertTrue($result['data']['suitable']);
        $this->assertSame(array(), $result['data']['warnings']);
        $this->assertStringContainsString('1×1', \implode(' ', $result['data']['recommendations']));
    }

    public function test_one_bad_jsonl_line_is_advice(): void
    {
        $result = $this->analyse(
            $this->file('rows.jsonl', 'application/jsonl', "{\"ok\":1}\nnot json\n"),
            'batch'
        );

        $this->assertTrue($result['data']['suitable']);
        $this->assertStringContainsString('did not parse', \implode(' ', $result['data']['recommendations']));
    }

    public function test_the_blocking_and_advisory_fields_never_overlap(): void
    {
        $cases = array(
            array( $this->file('notes.txt', 'text/plain', "widget notes\n"), 'assistants' ),
            array( $this->file('big.txt', 'text/plain', '', 600 * 1024 * 1024), 'assistants' ),
            array( $this->file('clip.mp4', 'video/mp4', 'binary'), 'assistants' ),
            array( $this->file('legacy.txt', 'text/plain', "\xFF\xFEbroken"), 'assistants' ),
            array( $this->file('rows.jsonl', 'application/jsonl', "nope\n"), 'fine-tune' ),
        );

        foreach ($cases as $index => [$file, $purpose]) {
            $result = $this->analyse($file, $purpose);

            $this->assertSame(
                array() === $result['data']['warnings'],
                $result['data']['suitable'],
                \sprintf('Case %d broke suitable === empty(warnings).', $index)
            );
        }
    }

    public function test_check_content_false_reads_no_file(): void
    {
        $result = $this->analyse(
            $this->file('notes.txt', 'text/plain', "\xFF\xFEbroken"),
            'assistants',
            false
        );

        $this->assertFalse($result['data']['content_checked']);
        $this->assertStringNotContainsString('UTF-8', \implode(' ', $result['data']['recommendations']));
    }

    public function test_a_file_that_cannot_be_opened_says_so_rather_than_pretending(): void
    {
        // Metadata exists, the path does not.
        $result = $this->analyse($this->file('gone.txt', 'text/plain', '', 20));

        $this->assertFalse($result['data']['content_checked']);
        $this->assertStringContainsString('could not be opened', \implode(' ', $result['data']['recommendations']));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function analyse(StoredFile $file, string $purpose = 'assistants', bool $checkContent = true): array
    {
        return $this->tool($this->storeWith($file))->execute(array(
            'file_id'       => $file->id,
            'purpose'       => $purpose,
            'check_content' => $checkContent,
        ));
    }

    /**
     * A `StoredFile` backed by a real temporary file, unless $bytes is empty and a size
     * is given — then the file is deliberately absent and the size comes from metadata.
     */
    private function file(
        string $filename,
        string $mimeType,
        string $bytes,
        ?int $sizeOverride = null,
    ): StoredFile {
        $path = null;

        if (null === $sizeOverride) {
            $path = \tempnam(\sys_get_temp_dir(), 'suit_');

            $this->assertNotFalse($path, 'Could not create a temporary file.');

            \file_put_contents($path, $bytes);
            $this->temporary[] = $path;
        }

        return new StoredFile(
            id: 1,
            filename: $filename,
            mimeType: $mimeType,
            sizeBytes: $sizeOverride ?? \strlen($bytes),
            localPath: $path ?? (\sys_get_temp_dir() . '/suit_missing_' . \uniqid()),
            ownerId: 1,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function storeWith(?StoredFile $file, bool $mayAccess = true): FileStoreInterface
    {
        $store = $this->createMock(FileStoreInterface::class);

        $store->method('getMetadata')->willReturnCallback(
            static fn (int $id): ?StoredFile => null !== $file && $id === $file->id ? $file : null
        );

        $store->method('userCanAccess')->willReturn($mayAccess);

        return $store;
    }

    private function tool(FileStoreInterface $store): AnalyzeFileSuitabilityLocalTool
    {
        return new AnalyzeFileSuitabilityLocalTool($this->errors, $store);
    }
}
