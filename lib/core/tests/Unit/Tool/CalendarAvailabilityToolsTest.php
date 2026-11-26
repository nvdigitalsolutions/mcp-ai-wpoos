<?php

/**
 * The four availability tools and the shared rules.
 *
 * Pinned behaviours, collected here rather than buried in assertions:
 *
 * - Business hours are normalised and bounded; a non-clock time falls back, an
 *   oversized slot/buffer clamps.
 * - `withinHours` requires the whole span inside the day's open–close window —
 *   the case WordPress missed by testing only the weekday.
 * - `set_availability_rules` replaces one day and leaves the other six alone;
 *   it refuses an unknown day, a non-clock time and a reversed window.
 * - `block_time_slot` writes an `mcp_blocked_time` record and refuses a
 *   reversed or relative span.
 * - `check_availability` reports conflicts, business hours and blocks
 *   independently, honours the two switches, and keeps the `per_system` map.
 * - `get_available_slots` builds the grid from the rule, drops booked and
 *   blocked slots, advances by duration + buffer, clamps the duration, and
 *   explains an empty result with the day and the effective rule.
 *
 * The content store is a fake that resolves the meta bounds from the query it
 * is given and applies the same overlap test SQL would, so a wrong bound in the
 * tool produces a wrong slot list rather than a passing test.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Tool\Calendar\AvailabilityDefinition;
use Nvoos\Core\Tool\Calendar\BlockTimeSlotTool;
use Nvoos\Core\Tool\Calendar\CheckAvailabilityTool;
use Nvoos\Core\Tool\Calendar\GetAvailableSlotsTool;
use Nvoos\Core\Tool\Calendar\SetAvailabilityRulesTool;
use PHPUnit\Framework\TestCase;

final class CalendarAvailabilityToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    /** @var list<ContentQuery> */
    private array $queries = array();

    private ?CreateContentCommand $created = null;

    private ?array $stored = null;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── AvailabilityDefinition ──────────────────────────────────────────

    public function test_a_day_rule_is_normalised_and_bounded(): void
    {
        $rule = AvailabilityDefinition::normalise(
            array(
                'start_time'    => '9am',
                'end_time'      => '18:30',
                'slot_duration' => 5,
                'buffer_time'   => 999,
            )
        );

        $this->assertSame('09:00', $rule['start_time']);
        $this->assertSame('18:30', $rule['end_time']);
        $this->assertSame(15, $rule['slot_duration']);
        $this->assertSame(240, $rule['buffer_time']);
        $this->assertTrue($rule['enabled']);
    }

    public function test_clock_values_are_normalised_or_refused(): void
    {
        $this->assertSame('09:05', AvailabilityDefinition::clock('9:05'));
        $this->assertSame('23:59', AvailabilityDefinition::clock('23:59'));
        $this->assertNull(AvailabilityDefinition::clock('24:00'));
        $this->assertNull(AvailabilityDefinition::clock('9am'));
        $this->assertNull(AvailabilityDefinition::clock(null));
    }

    public function test_the_weekday_is_read_as_utc(): void
    {
        $this->assertSame('sunday', AvailabilityDefinition::weekday('2026-02-01'));
        $this->assertSame('monday', AvailabilityDefinition::weekday('2026-02-02'));
        $this->assertNull(AvailabilityDefinition::weekday('2026-02-31'));
        $this->assertNull(AvailabilityDefinition::weekday('next monday'));
    }

    public function test_within_hours_requires_the_span_inside_the_window(): void
    {
        $rules = array(
            'monday' => AvailabilityDefinition::normalise(
                array('start_time' => '09:00', 'end_time' => '17:00')
            ),
        );

        $this->assertTrue(AvailabilityDefinition::withinHours($rules, '2026-02-02 10:00:00', '2026-02-02 11:00:00'));
        $this->assertTrue(AvailabilityDefinition::withinHours($rules, '2026-02-02 09:00:00', '2026-02-02 17:00:00'));
        $this->assertFalse(AvailabilityDefinition::withinHours($rules, '2026-02-02 22:00:00', '2026-02-02 23:00:00'));
        $this->assertFalse(AvailabilityDefinition::withinHours($rules, '2026-02-02 08:00:00', '2026-02-02 10:00:00'));
        $this->assertFalse(AvailabilityDefinition::withinHours($rules, '2026-02-03 10:00:00', '2026-02-03 11:00:00'));
        $this->assertTrue(AvailabilityDefinition::withinHours(array(), '2026-02-03 10:00:00', '2026-02-03 11:00:00'));
    }

    // ─── set_availability_rules ──────────────────────────────────────────

    public function test_set_stores_one_day_and_keeps_the_others(): void
    {
        $settings = $this->settingsStore(
            array(
                'monday' => array('enabled' => true, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_duration' => 30, 'buffer_time' => 0),
            )
        );

        $result = ( new SetAvailabilityRulesTool($this->errors, $settings) )->execute(
            array('day_of_week' => 'tuesday', 'start_time' => '10:00', 'end_time' => '16:00', 'slot_duration' => 45),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('monday', $this->stored);
        $this->assertSame('08:00', $this->stored['monday']['start_time']);
        $this->assertSame('10:00', $this->stored['tuesday']['start_time']);
        $this->assertSame(45, $this->stored['tuesday']['slot_duration']);
        $this->assertSame(AvailabilityDefinition::SETTINGS_KEY, $result['data']['day_of_week'] === 'tuesday' ? AvailabilityDefinition::SETTINGS_KEY : '');
    }

    public function test_set_defaults_every_unsupplied_field(): void
    {
        $settings = $this->settingsStore(array());

        $result = ( new SetAvailabilityRulesTool($this->errors, $settings) )->execute(
            array('day_of_week' => 'wednesday'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'enabled'       => true,
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'slot_duration' => 60,
                'buffer_time'   => 0,
            ),
            $result['data']['rules']
        );
    }

    public function test_set_refuses_a_bad_day_a_bad_clock_and_a_reversed_window(): void
    {
        $tool = new SetAvailabilityRulesTool($this->errors, $this->settingsStore(array()));

        $this->assertSame(
            'validation_failed',
            $tool->execute(array('day_of_week' => 'funday'), self::CONTEXT)['error']['code']
        );
        $this->assertArrayHasKey(
            'start_time',
            $tool->execute(array('day_of_week' => 'monday', 'start_time' => '9am'), self::CONTEXT)['error']['data']
        );
        $this->assertArrayHasKey(
            'end_time',
            $tool->execute(
                array('day_of_week' => 'monday', 'start_time' => '17:00', 'end_time' => '09:00'),
                self::CONTEXT
            )['error']['data']
        );
    }

    // ─── block_time_slot ─────────────────────────────────────────────────

    public function test_block_creates_the_wordpress_record(): void
    {
        $result = ( new BlockTimeSlotTool($this->errors, $this->contentStore()) )->execute(
            array('start_time' => '2026-02-02 12:00:00', 'end_time' => '2026-02-02 13:00:00', 'reason' => 'Lunch'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($this->created);
        $this->assertSame(AvailabilityDefinition::BLOCKED_TYPE, $this->created->type);
        $this->assertSame('publish', $this->created->status);
        $this->assertSame('Blocked: 2026-02-02 12:00:00 to 2026-02-02 13:00:00', $this->created->title);
        $this->assertSame('2026-02-02 12:00:00', $this->created->meta['_start_time']);
        $this->assertSame('2026-02-02 13:00:00', $this->created->meta['_end_time']);
        $this->assertSame('Lunch', $this->created->meta['_reason']);
        $this->assertSame(1, $this->created->meta['_blocked_by']);
        $this->assertSame(100, $result['data']['blocked_id']);
    }

    public function test_block_refuses_a_reversed_or_relative_span(): void
    {
        $tool = new BlockTimeSlotTool($this->errors, $this->contentStore());

        $this->assertSame(
            'validation_failed',
            $tool->execute(
                array('start_time' => '2026-02-02 13:00:00', 'end_time' => '2026-02-02 12:00:00'),
                self::CONTEXT
            )['error']['code']
        );
        $this->assertSame(
            'validation_failed',
            $tool->execute(
                array('start_time' => 'tomorrow', 'end_time' => '2026-02-02 12:00:00'),
                self::CONTEXT
            )['error']['code']
        );
    }

    // ─── check_availability ──────────────────────────────────────────────

    public function test_check_reports_a_free_window(): void
    {
        $result = ( new CheckAvailabilityTool($this->errors, $this->contentStore(), $this->settingsStore(array())) )
            ->execute(
                array('start_time' => '2026-02-02 10:00:00', 'end_time' => '2026-02-02 11:00:00'),
                self::CONTEXT
            );

        $this->assertTrue($result['data']['available']);
        $this->assertSame(array(), $result['data']['reasons']);
        $this->assertTrue($result['data']['per_system']['nvoos']['available']);
    }

    public function test_check_reports_a_conflict(): void
    {
        $result = ( new CheckAvailabilityTool(
            $this->errors,
            $this->contentStore(
                array(array('2026-02-02 10:30:00', '2026-02-02 11:30:00'))
            ),
            $this->settingsStore(array())
        ) )->execute(
            array('start_time' => '2026-02-02 10:00:00', 'end_time' => '2026-02-02 11:00:00'),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['available']);
        $this->assertSame(array(100), $result['data']['conflicts']);
        $this->assertSame('1 existing appointment(s) conflict with this time slot.', $result['data']['reasons'][0]);
    }

    public function test_check_reports_a_window_outside_business_hours(): void
    {
        $settings = $this->settingsStore(
            array('monday' => AvailabilityDefinition::normalise(array('start_time' => '09:00', 'end_time' => '17:00')))
        );

        $result = ( new CheckAvailabilityTool($this->errors, $this->contentStore(), $settings) )->execute(
            array('start_time' => '2026-02-02 22:00:00', 'end_time' => '2026-02-02 23:00:00'),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['available']);
        $this->assertContains('Time slot is outside business hours.', $result['data']['reasons']);
    }

    public function test_check_reports_a_blocked_window(): void
    {
        $result = ( new CheckAvailabilityTool(
            $this->errors,
            $this->contentStore(array(), array(array('2026-02-02 09:00:00', '2026-02-02 12:00:00'))),
            $this->settingsStore(array())
        ) )->execute(
            array('start_time' => '2026-02-02 10:00:00', 'end_time' => '2026-02-02 11:00:00'),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['available']);
        $this->assertContains('Time slot has been blocked.', $result['data']['reasons']);
    }

    public function test_check_honours_the_two_switches(): void
    {
        $settings = $this->settingsStore(
            array('monday' => AvailabilityDefinition::normalise(array('start_time' => '09:00', 'end_time' => '17:00')))
        );

        $result = ( new CheckAvailabilityTool(
            $this->errors,
            $this->contentStore(array(), array(array('2026-02-02 22:00:00', '2026-02-02 23:00:00'))),
            $settings
        ) )->execute(
            array(
                'start_time'           => '2026-02-02 22:00:00',
                'end_time'             => '2026-02-02 23:00:00',
                'check_business_hours' => false,
                'check_blocked_times'  => false,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['available']);
    }

    public function test_check_refuses_an_invalid_window(): void
    {
        $result = ( new CheckAvailabilityTool($this->errors, $this->contentStore(), $this->settingsStore(array())) )
            ->execute(
                array('start_time' => '2026-02-02 11:00:00', 'end_time' => '2026-02-02 10:00:00'),
                self::CONTEXT
            );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── get_available_slots ─────────────────────────────────────────────

    public function test_slots_follow_the_grid_and_skip_a_booked_slot(): void
    {
        $settings = $this->settingsStore($this->mondayRule('09:00', '12:00', 60, 0));
        $content  = $this->contentStore(array(array('2026-02-02 10:00:00', '2026-02-02 11:00:00')));

        $result = ( new GetAvailableSlotsTool($this->errors, $content, $settings) )->execute(
            array('date' => '2026-02-02', 'duration_minutes' => 60),
            self::CONTEXT
        );

        $starts = array_column($result['data']['available_slots'], 'start_time');

        $this->assertSame(array('2026-02-02 09:00:00', '2026-02-02 11:00:00'), $starts);
        $this->assertSame(2, $result['data']['total_slots']);
        $this->assertSame('nvoos', $result['data']['available_slots'][0]['source']);
    }

    public function test_slots_skip_a_blocked_range(): void
    {
        $settings = $this->settingsStore($this->mondayRule('09:00', '12:00', 60, 0));
        $content  = $this->contentStore(array(), array(array('2026-02-02 09:00:00', '2026-02-02 10:00:00')));

        $result = ( new GetAvailableSlotsTool($this->errors, $content, $settings) )->execute(
            array('date' => '2026-02-02', 'duration_minutes' => 60),
            self::CONTEXT
        );

        $starts = array_column($result['data']['available_slots'], 'start_time');

        $this->assertSame(array('2026-02-02 10:00:00', '2026-02-02 11:00:00'), $starts);
    }

    public function test_slots_advance_by_duration_plus_buffer(): void
    {
        $settings = $this->settingsStore($this->mondayRule('09:00', '13:00', 60, 15));

        $result = ( new GetAvailableSlotsTool($this->errors, $this->contentStore(), $settings) )->execute(
            array('date' => '2026-02-02', 'duration_minutes' => 60),
            self::CONTEXT
        );

        $starts = array_column($result['data']['available_slots'], 'start_time');

        $this->assertSame(
            array(
                '2026-02-02 09:00:00',
                '2026-02-02 10:15:00',
                '2026-02-02 11:30:00',
            ),
            $starts
        );
    }

    public function test_slots_are_empty_for_a_disabled_day_and_say_why(): void
    {
        $rule = AvailabilityDefinition::normalise(array('enabled' => false));

        $result = ( new GetAvailableSlotsTool(
            $this->errors,
            $this->contentStore(),
            $this->settingsStore(array('monday' => $rule))
        ) )->execute(array('date' => '2026-02-02'), self::CONTEXT);

        $this->assertSame(array(), $result['data']['available_slots']);
        $this->assertSame('monday', $result['data']['day_of_week']);
        $this->assertNull($result['data']['business_hours']);
    }

    public function test_slots_clamp_the_duration(): void
    {
        $settings = $this->settingsStore($this->mondayRule('09:00', '12:00', 60, 0));

        $result = ( new GetAvailableSlotsTool($this->errors, $this->contentStore(), $settings) )->execute(
            array('date' => '2026-02-02', 'duration_minutes' => 1),
            self::CONTEXT
        );

        $this->assertSame(15, $result['data']['duration_minutes']);
        $this->assertSame('2026-02-02 09:15:00', $result['data']['available_slots'][0]['end_time']);
    }

    public function test_slots_refuse_an_invalid_date(): void
    {
        $result = ( new GetAvailableSlotsTool(
            $this->errors,
            $this->contentStore(),
            $this->settingsStore(array())
        ) )->execute(array('date' => '2026-02-31'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_the_block_query_uses_the_overlap_bounds(): void
    {
        $settings = $this->settingsStore($this->mondayRule('09:00', '10:00', 60, 0));

        ( new GetAvailableSlotsTool($this->errors, $this->contentStore(), $settings) )->execute(
            array('date' => '2026-02-02', 'duration_minutes' => 60),
            self::CONTEXT
        );

        $blockQuery = null;

        foreach ($this->queries as $query) {
            if (in_array(AvailabilityDefinition::BLOCKED_TYPE, $query->types, true)) {
                $blockQuery = $query;

                break;
            }
        }

        $this->assertNotNull($blockQuery);

        $bounds = array();

        foreach ($blockQuery->metaQuery as $clause) {
            if (is_array($clause) && isset($clause['key'])) {
                $bounds[ $clause['key'] ] = $clause['value'];
            }
        }

        $this->assertSame('2026-02-02 10:00:00', $bounds['_start_time']);
        $this->assertSame('2026-02-02 09:00:00', $bounds['_end_time']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mondayRule(string $start, string $end, int $slot, int $buffer): array
    {
        return array(
            'monday' => AvailabilityDefinition::normalise(
                array(
                    'start_time'    => $start,
                    'end_time'      => $end,
                    'slot_duration' => $slot,
                    'buffer_time'   => $buffer,
                )
            ),
        );
    }

    /**
     * @param array<string, mixed> $rules Stored rules.
     */
    private function settingsStore(array $rules): SettingsStoreInterface
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => AvailabilityDefinition::SETTINGS_KEY === $key
                ? $rules
                : $default
        );
        $store->method('all')->willReturn(array(AvailabilityDefinition::SETTINGS_KEY => $rules));
        $store->method('set')->willReturnCallback(
            function (string $key, mixed $value): void {
                $this->stored = $value;
            }
        );

        return $store;
    }

    /**
     * A content store that resolves the meta bounds from the query and applies
     * the overlap test SQL would.
     *
     * @param list<array{0: string, 1: string}> $appointments Booked spans.
     * @param list<array{0: string, 1: string}> $blocks       Blocked spans.
     */
    private function contentStore(array $appointments = array(), array $blocks = array()): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);

        $store->method('query')->willReturnCallback(
            function (ContentQuery $query) use ($appointments, $blocks): ContentCollection {
                $this->queries[] = $query;

                $pool  = in_array(AvailabilityDefinition::BLOCKED_TYPE, $query->types, true) ? $blocks : $appointments;
                $bound = array();

                foreach ($query->metaQuery as $clause) {
                    if (is_array($clause) && isset($clause['key']) && is_scalar($clause['value'] ?? null)) {
                        $bound[ $clause['key'] ] = (string) $clause['value'];
                    }
                }

                $items = array();

                foreach ($pool as $index => $span) {
                    // SQL semantics: the row matches when the query's
                    // `_start_time` bound is the window end and its `_end_time`
                    // bound is the window start.
                    if (
                        isset($bound['_start_time'], $bound['_end_time'])
                        && $span[0] < $bound['_start_time']
                        && $span[1] > $bound['_end_time']
                    ) {
                        $items[] = $this->span($span, 100 + $index);
                    }
                }

                return new ContentCollection(
                    items: $items,
                    total: count($items),
                    page: 1,
                    perPage: $query->perPage,
                    totalPages: 1,
                );
            }
        );

        $store->method('create')->willReturnCallback(
            function (CreateContentCommand $command): ContentItem {
                $this->created = $command;

                return new ContentItem(
                    id: 100,
                    title: $command->title,
                    content: $command->content,
                    status: $command->status,
                    type: $command->type,
                    authorId: $command->authorId,
                    createdAt: new DateTimeImmutable(),
                    updatedAt: new DateTimeImmutable(),
                    meta: $command->meta,
                );
            }
        );

        return $store;
    }

    /**
     * @param array{0: string, 1: string} $span Start and end.
     */
    private function span(array $span, int $id): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: 'Item ' . $id,
            content: '',
            status: 'publish',
            type: 'mcp_appointment',
            authorId: 1,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            meta: array('_start_time' => $span[0], '_end_time' => $span[1]),
        );
    }
}
