<?php

/**
 * `delete_eca` — the confirmation, the cascade, and the two paths.
 *
 * The interesting assertions are about order and state, not about the delete
 * call: a cascade that runs before the activity is touched is what keeps a
 * half-finished delete recoverable, and a cancel that clears the roster and the
 * counter is what stops `list_ecas` reporting twelve students on an activity
 * whose students are gone (\u00a7164.56's one divergence from WordPress).
 *
 * The store is a stateful double: `update()` merges meta exactly as the real
 * store does, so the student side can be read back after the cascade instead of
 * asserting on a recorded call.
 *
 * @package Nvoos\Core\Tests
 * @since   2.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Classroom\DeleteEcaTool;
use PHPUnit\Framework\TestCase;

final class DeleteEcaToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Refusals before anything is written ───────────────────────────────

    public function test_a_missing_id_is_refused(): void
    {
        $result = $this->tool($this->store())->execute(array(), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_id_that_is_not_an_eca_is_refused(): void
    {
        $store = $this->store(array(5 => $this->item(5, 'mcp_ai_student')));

        $result = $this->tool($store)->execute(array('eca_id' => 5), array('user_id' => 7));

        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(array(), $store->deleted);
    }

    /**
     * A cascade that unenrols students is not something a caller should learn
     * about afterwards, so the refusal names the count before any write.
     */
    public function test_active_enrolments_require_confirmation_and_name_the_count(): void
    {
        $store = $this->store($this->twoEnrolledStudents());

        $result = $this->tool($store)->execute(array('eca_id' => 10), array('user_id' => 7));

        $this->assertSame('confirmation_required', $result['error']['code']);
        $this->assertStringContainsString('2 active enrollment', $result['error']['message']);
        $this->assertSame(array(), $store->updates);
        $this->assertSame(array(), $store->deleted);
    }

    // ─── The two paths ────────────────────────────────────────────────────

    public function test_a_confirmed_delete_unenrols_the_students_then_removes_the_activity(): void
    {
        $store  = $this->store($this->twoEnrolledStudents());
        $result = $this->tool($store)->execute(
            array('eca_id' => 10, 'confirm_delete' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('deleted', $result['data']['action']);
        $this->assertSame(2, $result['data']['students_affected']);
        $this->assertSame(array(10), $store->deleted);

        // Both student sides lost the activity, and only that key.
        foreach (array(20, 21) as $student) {
            $this->assertSame(array(), $store->items[ $student ]->meta['_student_eca_enrollments']);
        }
    }

    /**
     * WordPress leaves the roster and the counter on a cancelled activity, so
     * `list_ecas` then reports students who no longer have it. Clearing both is
     * the divergence \u00a7164.56 records.
     */
    public function test_a_soft_delete_cancels_clears_the_roster_and_zeroes_the_count(): void
    {
        $store  = $this->store($this->twoEnrolledStudents(2));
        $result = $this->tool($store)->execute(
            array('eca_id' => 10, 'soft_delete' => true, 'confirm_delete' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $result['data']['action']);
        $this->assertSame(array(), $store->deleted);

        $meta = $store->items[10]->meta;

        $this->assertSame('cancelled', $meta['_eca_status']);
        $this->assertSame(array(), $meta['_eca_student_enrollments']);
        $this->assertSame(array(), $meta['_eca_enrollments']);
        $this->assertSame(0, $meta['_eca_current_enrollment']);
        $this->assertSame(7, $meta['_eca_cancelled_by']);
        $this->assertNotSame('', $meta['_eca_cancelled_at']);
    }

    /**
     * Ordering proof: when the second student write fails, the activity must
     * still exist and must not be cancelled, so the half-done cascade is visible
     * rather than hidden behind a deleted row.
     */
    public function test_a_cascade_failure_stops_before_the_activity_is_touched(): void
    {
        $store  = $this->store($this->twoEnrolledStudents(), 2);
        $result = $this->tool($store)->execute(
            array('eca_id' => 10, 'soft_delete' => true, 'confirm_delete' => true),
            array('user_id' => 7)
        );

        $this->assertSame('eca_delete_failed', $result['error']['code']);
        $this->assertStringContainsString('after 1 student', $result['error']['message']);
        $this->assertArrayHasKey(10, $store->items);
        $this->assertSame('active', $store->items[10]->meta['_eca_status'] ?? null);
    }

    /**
     * `_eca_enrollments` is the older name: WordPress's `list` tool reads it and
     * nothing in this family writes it, but an imported row may carry it and
     * deleting such an activity must still unenroll the students it names.
     */
    public function test_a_legacy_roster_key_is_still_unenrolled(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_enrollments' => array(20 => array('student_id' => 20)),
                '_eca_current_enrollment' => 1,
            )),
            20 => $this->item(20, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(10 => array('eca_id' => 10)),
            )),
        ));

        $result = $this->tool($store)->execute(
            array('eca_id' => 10, 'confirm_delete' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['students_affected']);
        $this->assertSame(array(), $store->items[20]->meta['_student_eca_enrollments']);
    }

    public function test_an_empty_roster_needs_no_confirmation(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_enrollments' => array(), '_eca_current_enrollment' => 0)),
        ));

        $result = $this->tool($store)->execute(array('eca_id' => 10), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['students_affected']);
        $this->assertSame(array(10), $store->deleted);
    }

    /**
     * A roster can name a student whose record is already gone. That student is
     * skipped rather than failing the delete, and the count reports what was
     * actually written.
     */
    public function test_a_student_that_vanished_is_skipped(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_enrollments' => array(20 => array('student_id' => 20), 99 => array('student_id' => 99)),
                '_eca_current_enrollment' => 2,
            )),
            20 => $this->item(20, 'mcp_ai_student', array('_student_eca_enrollments' => array(10 => array('eca_id' => 10)))),
        ));

        $result = $this->tool($store)->execute(
            array('eca_id' => 10, 'confirm_delete' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['students_affected']);
        $this->assertSame(array(10), $store->deleted);
    }

    // ─── Doubles ──────────────────────────────────────────────────────────

    private function tool(ContentStoreInterface $store): DeleteEcaTool
    {
        return new DeleteEcaTool($this->errors, $store);
    }

    /**
     * One activity with two enrolled students, both sides consistent.
     *
     * @return array<int, ContentItem>
     */
    private function twoEnrolledStudents(int $count = 2): array
    {
        return array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_student_enrollments' => array(
                    20 => array('student_id' => 20),
                    21 => array('student_id' => 21),
                ),
                '_eca_current_enrollment' => $count,
                '_eca_status' => 'active',
            )),
            20 => $this->item(20, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(10 => array('eca_id' => 10)),
            )),
            21 => $this->item(21, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(10 => array('eca_id' => 10)),
            )),
        );
    }

    private function item(int $id, string $type, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: ('mcp_ai_eca' === $type ? 'Chess Club ' : 'Student ') . $id,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    /**
     * @param array<int, ContentItem> $items
     * @param int|null                $failUpdateOn The 1-based update call that throws.
     */
    private function store(array $items = array(), ?int $failUpdateOn = null): ContentStoreInterface
    {
        return new class ($items, $failUpdateOn) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items;

            /** @var list<int> */
            public array $deleted = array();

            /** @var list<array{id: int, meta: array<string, mixed>}> */
            public array $updates = array();

            private int $seen = 0;

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items, private readonly ?int $failUpdateOn)
            {
                $this->items = $items;
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $type = (string) ( $query->types[0] ?? '' );
                $rows = array_values(array_filter(
                    $this->items,
                    static fn (ContentItem $item): bool => '' === $type || $item->type === $type
                ));

                return new ContentCollection($rows, count($rows), 1, $query->perPage, 1);
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('create is not used by this tool.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                ++$this->seen;

                if (null !== $this->failUpdateOn && $this->seen === $this->failUpdateOn) {
                    throw new \RuntimeException('storage refused the write');
                }

                $item = $this->items[ $id ] ?? null;

                if (null === $item) {
                    throw new \RuntimeException('no record ' . $id);
                }

                $this->updates[] = array('id' => $id, 'meta' => $command->meta);

                $this->items[ $id ] = new ContentItem(
                    id: $item->id,
                    title: $item->title,
                    content: $item->content,
                    status: $item->status,
                    type: $item->type,
                    authorId: $item->authorId,
                    createdAt: $item->createdAt,
                    updatedAt: $item->updatedAt,
                    meta: array_merge($item->meta, $command->meta),
                );

                return $this->items[ $id ];
            }

            public function delete(int $id, int $userId): void
            {
                $this->deleted[] = $id;
                unset($this->items[ $id ]);
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return true;
            }
        };
    }
}
