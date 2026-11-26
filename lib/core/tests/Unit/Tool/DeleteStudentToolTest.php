<?php

/**
 * `delete_student` — the delete, and the number WordPress leaves as prose.
 *
 * The WordPress tool warns that deleting a student "does not automatically
 * unenroll" them. This port keeps the behaviour and returns `enrollments_left`
 * with the activity ids, so the warning becomes something a caller can act on.
 *
 * The report is the *union* of the two sides: what the student record claims and
 * what the activity rosters claim. The two can disagree, and reporting only one
 * of them would hide exactly the rows a cleanup would need.
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
use Nvoos\Core\Tool\Classroom\DeleteStudentTool;
use PHPUnit\Framework\TestCase;

final class DeleteStudentToolTest extends TestCase
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
            static fn (string $message): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    public function test_a_missing_id_is_refused(): void
    {
        $result = $this->tool($this->store())->execute(array(), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_id_that_is_not_a_student_is_refused(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca')));

        $result = $this->tool($store)->execute(array('student_id' => 10), array('user_id' => 7));

        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(array(), $store->deleted);
    }

    /**
     * Student 30's own record names activities 10 and 11; only 10 still lists
     * them on its roster. The union is both, and 10 carries a name.
     */
    public function test_the_report_is_the_union_of_both_sides(): void
    {
        $store = $this->store(array(
            30 => $this->item(30, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(
                    10 => array('eca_id' => 10),
                    11 => array('eca_id' => 11),
                ),
            )),
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_enrollments' => array(30 => array('student_id' => 30)),
            )),
            11 => $this->item(11, 'mcp_ai_eca', array('_eca_enrollments' => array())),
        ));

        $result = $this->tool($store)->execute(array('student_id' => 30), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(array(30), $store->deleted);
        $this->assertSame(2, $result['data']['enrollments_left']);
        $this->assertSame(array(10, 11), $result['data']['eca_ids']);
        $this->assertSame(array(10 => 'Chess Club 10'), $result['data']['eca_names']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertStringContainsString('2 activity(ies)', $result['message']);
    }

    public function test_a_student_nothing_lists_is_reported_as_clean(): void
    {
        $store = $this->store(array(30 => $this->item(30, 'mcp_ai_student')));

        $result = $this->tool($store)->execute(array('student_id' => 30), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['enrollments_left']);
        $this->assertSame(array(), $result['data']['eca_ids']);
        $this->assertStringContainsString('no activity still lists them', $result['message']);
    }

    /**
     * The scan is a single page of 200. Past that the count is a lower bound and
     * `truncated` says so, rather than a number that reads as exact.
     */
    public function test_a_truncated_scan_is_flagged(): void
    {
        $store = $this->store(array(30 => $this->item(30, 'mcp_ai_student')), 260);

        $result = $this->tool($store)->execute(array('student_id' => 30), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['truncated']);
    }

    // ─── Doubles ──────────────────────────────────────────────────────────

    private function tool(ContentStoreInterface $store): DeleteStudentTool
    {
        return new DeleteStudentTool($this->errors, $store);
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
     * @param int|null                $forceTotal Report a larger total, as a
     *                                            truncated page would.
     */
    private function store(array $items = array(), ?int $forceTotal = null): ContentStoreInterface
    {
        return new class ($items, $forceTotal) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items;

            /** @var list<int> */
            public array $deleted = array();

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items, private readonly ?int $forceTotal)
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

                $total = $this->forceTotal ?? count($rows);

                return new ContentCollection(
                    array_slice($rows, 0, max(1, $query->perPage)),
                    $total,
                    1,
                    $query->perPage,
                    (int) ceil($total / max(1, $query->perPage)),
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('create is not used by this tool.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('update is not used by this tool.');
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
