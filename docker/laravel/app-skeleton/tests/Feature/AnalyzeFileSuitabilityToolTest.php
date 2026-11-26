<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Tests\TestCase;

/**
 * `analyze_file_suitability` against the app's real file store — §25.
 *
 * The unit suite pins the limits and the `suitable === empty(warnings)` invariant with
 * temporary files. This pins what only the app can get wrong: that the real store's
 * `localPath` is a path the check can actually open, and that a file the caller does
 * not own is refused as though it did not exist.
 */
final class AnalyzeFileSuitabilityToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('analyze_file_suitability'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_a_real_stored_text_file_is_suitable(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $file = $this->store($user, 'notes.txt', "A short document about widgets.\n");

        $result = $this->tool()->execute(
            array( 'file_id' => $file->id, 'purpose' => 'assistants' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['suitable']);
        $this->assertSame(array(), $result['data']['warnings']);

        // The store's localPath has to be a file the check can open, or every content
        // check silently degrades to a metadata-only answer.
        $this->assertTrue(
            $result['data']['content_checked'],
            'The real file store did not yield a readable path.'
        );

        $this->assertSame('txt', $result['data']['file_type']);
    }

    public function test_a_nearly_empty_stored_file_is_not_suitable(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        // A zero-byte file never reaches the tool: the real store sniffs
        // `application/x-empty` and refuses it at upload. Under ten bytes is the case
        // that can be stored and still has nothing to ingest.
        $file = $this->store($user, 'tiny.txt', "hi\n");

        $result = $this->tool()->execute(
            array( 'file_id' => $file->id ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['suitable']);
        $this->assertStringContainsString('empty', \implode(' ', $result['data']['warnings']));
    }

    public function test_a_missing_file_id_is_an_error(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $result = $this->tool()->execute(
            array( 'file_id' => 999999 ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('999999', $result->getMessage());
    }

    public function test_another_users_file_answers_as_though_it_does_not_exist(): void
    {
        Storage::fake('public');

        $owner = User::factory()->admin()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner);

        $file = $this->store($owner, 'private-notes.txt', "Not for the stranger.\n");

        $this->actingAs($stranger);

        $result = $this->tool()->execute(
            array( 'file_id' => $file->id ),
            array( 'user_id' => (int) $stranger->getKey() )
        );

        // Not `forbidden`: confirming the file exists is itself information.
        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('readable by this caller', $result->getMessage());
    }

    private function store(User $owner, string $filename, string $bytes): StoredFile
    {
        $source = \tempnam(\sys_get_temp_dir(), 'suit_src_');

        $this->assertNotFalse($source, 'Could not create a source file.');

        \file_put_contents($source, $bytes);

        $mime = \str_ends_with($filename, '.txt') ? 'text/plain' : 'application/octet-stream';

        $file = $this->app->make(FileStoreInterface::class)->store(
            $source,
            $filename,
            $mime,
            (int) $owner->getKey()
        );

        @\unlink($source);

        return $file;
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('analyze_file_suitability');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
