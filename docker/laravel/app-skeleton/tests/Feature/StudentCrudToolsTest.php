<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `create_student` and `update_student`.
 *
 * Two tools that look interchangeable and are not:
 * - `create_student` requires both names on every call and ignores empty
 *   optionals, so it can never clear a field.
 * - `update_student` patches, so an explicitly empty string **clears** a field
 *   and a single name half merges against the stored half.
 *
 * Both behaviours are asserted, because "tidying them into one" would silently
 * change what a caller gets back.
 */
class StudentCrudToolsTest extends TestCase
{
    use RefreshDatabase;

    private int $amaraId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $this->amaraId = $this->store()
            ->query(new ContentQuery(types: ['mcp_ai_student'], perPage: 10))
            ->items[0]
            ->id;
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function student(int $id): ContentItem
    {
        return $this->store()->find($id);
    }

    private function callTool(string $slug, array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get($slug)
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    private function callExpectingError(string $slug, array $args): \RuntimeException
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get($slug)
            ->execute($args, ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);

        return $result;
    }

    // ─── create_student ──────────────────────────────────────────────

    public function test_create_student_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('create_student'));
    }

    public function test_create_student_returns_the_wordpress_shape(): void
    {
        $result = $this->callTool('create_student', [
            'first_name' => 'Nina',
            'last_name' => 'Patel',
            'year_group' => '9',
            'house' => 'Heron',
            'email' => 'nina.patel@example.org',
            'isams_id' => 'IS-42',
        ]);

        $this->assertSame(
            ['student_id', 'student', 'updated'],
            array_keys($result['data'])
        );

        $this->assertFalse($result['data']['updated']);
        $this->assertSame('Student created successfully.', $result['message']);

        $student = $result['data']['student'];

        $this->assertSame(
            ['id', 'name', 'first_name', 'last_name', 'year_group', 'house', 'email', 'isams_id', 'created_at'],
            array_keys($student)
        );

        $this->assertSame('Nina Patel', $student['name']);
        $this->assertSame('IS-42', $student['isams_id']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $student['created_at']
        );
    }

    public function test_create_student_writes_the_meta_and_an_empty_enrolment_map(): void
    {
        $result = $this->callTool('create_student', [
            'first_name' => 'Nina',
            'last_name' => 'Patel',
            'house' => 'Heron',
        ]);

        $saved = $this->student($result['data']['student_id']);

        $this->assertSame('mcp_ai_student', $saved->type);
        $this->assertSame('Nina Patel', $saved->title);
        $this->assertSame('Nina', $saved->meta['_student_first_name']);
        $this->assertSame('Patel', $saved->meta['_student_last_name']);
        $this->assertSame('Heron', $saved->meta['_student_house']);

        // Readers must never have to distinguish absent from empty.
        $this->assertSame([], $saved->meta['_student_eca_enrollments']);
    }

    public function test_create_student_omits_optional_meta_that_was_not_supplied(): void
    {
        $result = $this->callTool('create_student', [
            'first_name' => 'Nina',
            'last_name' => 'Patel',
        ]);

        $saved = $this->student($result['data']['student_id']);

        $this->assertArrayNotHasKey('_student_year_group', $saved->meta);
        $this->assertArrayNotHasKey('_student_house', $saved->meta);
        $this->assertArrayNotHasKey('_student_email', $saved->meta);
        $this->assertArrayNotHasKey('_student_isams_id', $saved->meta);
    }

    public function test_create_student_requires_both_names(): void
    {
        $error = $this->callExpectingError('create_student', [
            'first_name' => 'Nina',
        ]);

        $this->assertSame(422, $error->getCode());
    }

    public function test_create_student_rejects_an_invalid_email(): void
    {
        $error = $this->callExpectingError('create_student', [
            'first_name' => 'Nina',
            'last_name' => 'Patel',
            'email' => 'not-an-email',
        ]);

        $this->assertSame('Invalid email address.', $error->getMessage());
    }

    public function test_create_student_with_an_id_updates_in_place(): void
    {
        $result = $this->callTool('create_student', [
            'student_id' => $this->amaraId,
            'first_name' => 'Amara',
            'last_name' => 'Nwosu',
            'house' => 'Falcon',
        ]);

        $this->assertTrue($result['data']['updated']);
        $this->assertSame('Student updated successfully.', $result['message']);
        $this->assertSame($this->amaraId, $result['data']['student_id']);
        $this->assertArrayHasKey('updated_at', $result['data']['student']);

        $saved = $this->student($this->amaraId);

        $this->assertSame('Amara Nwosu', $saved->title);
        $this->assertSame('Nwosu', $saved->meta['_student_last_name']);

        // No second record was created.
        $this->assertSame(2, $this->store()->query(new ContentQuery(types: ['mcp_ai_student']))->total);
    }

    public function test_create_student_update_does_not_clear_an_omitted_optional(): void
    {
        $before = $this->student($this->amaraId)->meta['_student_house'];

        $this->callTool('create_student', [
            'student_id' => $this->amaraId,
            'first_name' => 'Amara',
            'last_name' => 'Okafor',
        ]);

        $this->assertSame($before, $this->student($this->amaraId)->meta['_student_house']);
    }

    public function test_create_student_rejects_an_unknown_id(): void
    {
        $error = $this->callExpectingError('create_student', [
            'student_id' => 999999,
            'first_name' => 'Ghost',
            'last_name' => 'Student',
        ]);

        $this->assertSame('Student not found.', $error->getMessage());
    }

    // ─── update_student ──────────────────────────────────────────────

    public function test_update_student_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('update_student'));
    }

    public function test_update_student_reports_only_the_record_it_touched(): void
    {
        $result = $this->callTool('update_student', [
            'student_id' => $this->amaraId,
            'house' => 'Heron',
        ]);

        $this->assertSame(['student_id'], array_keys($result['data']));
        $this->assertSame($this->amaraId, $result['data']['student_id']);
        $this->assertSame('Student updated successfully.', $result['message']);
    }

    public function test_update_student_only_touches_the_supplied_fields(): void
    {
        $before = $this->student($this->amaraId);

        $this->callTool('update_student', [
            'student_id' => $this->amaraId,
            'year_group' => '11',
        ]);

        $after = $this->student($this->amaraId);

        $this->assertSame('11', $after->meta['_student_year_group']);
        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->meta['_student_house'], $after->meta['_student_house']);
        $this->assertSame($before->meta['_student_email'], $after->meta['_student_email']);
    }

    public function test_update_student_clears_a_field_given_an_empty_string(): void
    {
        $this->assertNotSame('', $this->student($this->amaraId)->meta['_student_house']);

        $this->callTool('update_student', [
            'student_id' => $this->amaraId,
            'house' => '',
        ]);

        $this->assertSame('', $this->student($this->amaraId)->meta['_student_house']);
    }

    public function test_update_student_merges_one_name_half_against_the_stored_half(): void
    {
        $before = $this->student($this->amaraId)->meta['_student_last_name'];

        $this->callTool('update_student', [
            'student_id' => $this->amaraId,
            'first_name' => 'Amara-Jane',
        ]);

        $saved = $this->student($this->amaraId);

        $this->assertSame('Amara-Jane '.$before, $saved->title);
        $this->assertSame('Amara-Jane', $saved->meta['_student_first_name']);
        $this->assertSame($before, $saved->meta['_student_last_name']);
    }

    public function test_update_student_leaves_the_title_alone_when_both_names_are_emptied(): void
    {
        $before = $this->student($this->amaraId)->title;

        $this->callTool('update_student', [
            'student_id' => $this->amaraId,
            'first_name' => '',
            'last_name' => '',
        ]);

        $saved = $this->student($this->amaraId);

        // The meta is cleared, but a blank title would be worse than a stale one.
        $this->assertSame($before, $saved->title);
        $this->assertSame('', $saved->meta['_student_first_name']);
        $this->assertSame('', $saved->meta['_student_last_name']);
    }

    public function test_update_student_rejects_an_invalid_email(): void
    {
        $error = $this->callExpectingError('update_student', [
            'student_id' => $this->amaraId,
            'email' => 'nope',
        ]);

        $this->assertSame('Invalid email address.', $error->getMessage());
    }

    public function test_update_student_requires_an_id(): void
    {
        $error = $this->callExpectingError('update_student', ['house' => 'Heron']);

        $this->assertSame('Student ID is required.', $error->getMessage());
    }

    public function test_update_student_rejects_an_unknown_id(): void
    {
        $error = $this->callExpectingError('update_student', [
            'student_id' => 999999,
            'house' => 'Heron',
        ]);

        $this->assertSame('Invalid student ID.', $error->getMessage());
    }

    public function test_a_created_student_can_then_be_patched(): void
    {
        $created = $this->callTool('create_student', [
            'first_name' => 'Nina',
            'last_name' => 'Patel',
        ]);

        $id = $created['data']['student_id'];

        $this->callTool('update_student', [
            'student_id' => $id,
            'year_group' => '9',
            'house' => 'Heron',
        ]);

        $saved = $this->student($id);

        $this->assertSame('Nina Patel', $saved->title);
        $this->assertSame('9', $saved->meta['_student_year_group']);
        $this->assertSame('Heron', $saved->meta['_student_house']);
    }
}
