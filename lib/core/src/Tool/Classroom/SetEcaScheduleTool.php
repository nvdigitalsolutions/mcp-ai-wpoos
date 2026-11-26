<?php

/**
 * Replace an activity's timetable — Proposal 164 §164.59.
 *
 * Ported from `WP_MCP_AI_Tool_Set_ECA_Schedule`: same `eca_id` and `sessions`
 * arguments, the same seven days and three recurrences, and the same mirroring
 * of the first session into the single-slot fields other tools read. Three
 * things are decided here rather than inherited:
 *
 * ### The payload is checked against itself
 *
 * WordPress validates each session in isolation, so a payload that books the
 * same room on the same day twice is saved and then reported by the timetable
 * as a conflict the caller already knew about. This port refuses it up front,
 * naming both session numbers, because a schedule that collides with itself
 * was never a schedule.
 *
 * ### An unknown recurrence is refused, not rewritten
 *
 * WordPress falls back to `weekly` for any value outside the three it knows.
 * A caller that typed `fortnight` gets a weekly activity and no signal. Here
 * the value is part of the contract: an unknown one is `validation_failed`
 * naming the accepted three. The schema already declares the enum, so this
 * only fires for a caller that bypassed schema validation — which is exactly
 * the caller that needs the signal.
 *
 * ### Cross-activity venue clashes are warnings
 *
 * WordPress scans other activities for the same day and venue and reports
 * overlaps as prose warnings without refusing. That shape is kept: a shared
 * hall is a scheduling decision, not an invalid request. This port also reads
 * each other activity's stored `_eca_sessions`, so a second session that never
 * reached the single-slot mirror is still seen, and it grades with
 * {@see Schedule::overlaps()}, where back-to-back is not a clash.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class SetEcaScheduleTool extends AbstractTool
{
    private const RECURRENCES = array( 'weekly', 'biweekly', 'monthly' );

    /** The ceiling on sessions in one payload. A school week has no use for more. */
    private const MAX_SESSIONS = 20;

    /** The ceiling on the scan for venue clashes. */
    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'set_eca_schedule';
    }

    public function getName(): string
    {
        return 'Set ECA Schedule';
    }

    public function getDescription(): string
    {
        return 'Replaces the timetable of an Extra-Curricular Activity with one or more weekly, biweekly or monthly sessions. The first session is mirrored into the single day, start, end and venue fields that other tools read. Sessions that overlap each other, or that end before they start, are refused; sessions that share a venue with another activity on the same day are reported as warnings and still saved.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'eca_id'   => array(
                    'type'        => 'integer',
                    'description' => 'ECA record ID whose schedule is being replaced (required).',
                    'minimum'     => 1,
                ),
                'sessions' => array(
                    'type'        => 'array',
                    'description' => 'The complete new timetable; it replaces the stored one.',
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_SESSIONS,
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'day'        => array(
                                'type'        => 'string',
                                'enum'        => Schedule::DAYS,
                                'description' => 'Day of the week.',
                            ),
                            'start_time' => array(
                                'type'        => 'string',
                                'description' => 'Start time as HH:MM (24-hour).',
                                'pattern'     => '^[0-9]{1,2}:[0-9]{2}$',
                            ),
                            'end_time'   => array(
                                'type'        => 'string',
                                'description' => 'End time as HH:MM (24-hour). Must be after start_time.',
                                'pattern'     => '^[0-9]{1,2}:[0-9]{2}$',
                            ),
                            'venue'      => array(
                                'type'        => 'string',
                                'description' => 'Where the session meets. Empty when no room is booked.',
                                'maxLength'   => 200,
                            ),
                            'recurrence' => array(
                                'type'        => 'string',
                                'enum'        => self::RECURRENCES,
                                'default'     => 'weekly',
                                'description' => 'How often the session repeats.',
                            ),
                        ),
                        'required'   => array( 'day', 'start_time', 'end_time' ),
                    ),
                ),
            ),
            'required'   => array( 'eca_id', 'sessions' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id = (int) ( $context['user_id'] ?? 0 );
        $eca_id  = $this->intParam($arguments, 'eca_id');

        if ($eca_id <= 0) {
            return $this->errors->validationFailed(
                'An ECA id is required.',
                array( 'eca_id' => array( 'Give the id of the activity whose schedule is being set.' ) ),
            );
        }

        $raw = $arguments['sessions'] ?? null;

        if (! is_array($raw) || array() === $raw) {
            return $this->errors->validationFailed(
                'At least one session is required.',
                array( 'sessions' => array( 'Give a list of sessions, each with a day, start and end time.' ) ),
            );
        }

        if (count($raw) > self::MAX_SESSIONS) {
            return $this->errors->validationFailed(
                sprintf('A payload may hold at most %d sessions; got %d.', self::MAX_SESSIONS, count($raw)),
                array( 'sessions' => array( 'Split the timetable or raise the ceiling deliberately.' ) ),
            );
        }

        $eca = $this->content->find($eca_id);

        if (! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
            return $this->errors->notFound('There is no ECA with id ' . $eca_id . '.');
        }

        $sessions = array();
        $errors   = array();

        foreach (array_values($raw) as $index => $session) {
            $number = $index + 1;

            if (! is_array($session)) {
                $errors[ 'sessions[' . $index . ']' ] = array( sprintf('Session %d is not an object.', $number) );

                continue;
            }

            $day        = trim((string) ( $session['day'] ?? '' ));
            $start_time = trim((string) ( $session['start_time'] ?? '' ));
            $end_time   = trim((string) ( $session['end_time'] ?? '' ));
            $venue      = trim((string) ( $session['venue'] ?? '' ));
            $recurrence = strtolower(trim((string) ( $session['recurrence'] ?? 'weekly' )));

            if (! in_array($day, Schedule::DAYS, true)) {
                $errors[ 'sessions[' . $index . '].day' ] = array(
                    sprintf('Session %d has day "%s"; use one of %s.', $number, $day, implode(', ', Schedule::DAYS)),
                );
            }

            $start_at = Schedule::minutes($start_time);
            $end_at   = Schedule::minutes($end_time);

            if (null === $start_at) {
                $errors[ 'sessions[' . $index . '].start_time' ] = array(
                    sprintf('Session %d start_time "%s" is not HH:MM.', $number, $start_time),
                );
            }

            if (null === $end_at) {
                $errors[ 'sessions[' . $index . '].end_time' ] = array(
                    sprintf('Session %d end_time "%s" is not HH:MM.', $number, $end_time),
                );
            }

            if (null !== $start_at && null !== $end_at && $end_at <= $start_at) {
                $errors[ 'sessions[' . $index . '].end_time' ] = array(
                    sprintf('Session %d ends at %s, which is not after its start at %s.', $number, $end_time, $start_time),
                );
            }

            if ('' === $recurrence) {
                $recurrence = 'weekly';
            }

            if (! in_array($recurrence, self::RECURRENCES, true)) {
                $errors[ 'sessions[' . $index . '].recurrence' ] = array(
                    sprintf('Session %d has recurrence "%s"; use one of %s.', $number, $recurrence, implode(', ', self::RECURRENCES)),
                );
            }

            $sessions[] = array(
                'day'        => $day,
                'start_time' => $start_time,
                'end_time'   => $end_time,
                'venue'      => $venue,
                'recurrence' => $recurrence,
                'start_at'   => $start_at,
                'end_at'     => $end_at,
            );
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The timetable has ' . count($errors) . ' problem(s).', $errors);
        }

        $overlap = $this->firstOverlap($sessions);

        if (null !== $overlap) {
            return $this->errors->validationFailed(
                sprintf(
                    'Sessions %d and %d both run on %s and share at least one minute.',
                    $overlap['first'],
                    $overlap['second'],
                    $overlap['day']
                ),
                array( 'sessions' => array( 'Two sessions of one activity cannot occupy the same time.' ) ),
            );
        }

        $conflicts = $this->venueConflicts($eca_id, $sessions);

        $meta = array(
            '_eca_sessions'   => array_map(
                static fn (array $session): array => array(
                    'day'        => $session['day'],
                    'start_time' => $session['start_time'],
                    'end_time'   => $session['end_time'],
                    'venue'      => $session['venue'],
                    'recurrence' => $session['recurrence'],
                ),
                $sessions
            ),
            '_eca_day'        => $sessions[0]['day'],
            '_eca_start_time' => $sessions[0]['start_time'],
            '_eca_end_time'   => $sessions[0]['end_time'],
        );

        if ('' !== $sessions[0]['venue']) {
            $meta['_eca_venue'] = $sessions[0]['venue'];
        }

        try {
            $this->content->update($eca_id, new UpdateContentCommand(userId: $user_id, meta: $meta));
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('eca_schedule_failed', $e->getMessage());
        }

        $warnings = array();

        foreach ($conflicts as $conflict) {
            $warnings[] = sprintf(
                'Session on %s %s-%s in "%s" overlaps %s (id %d).',
                $conflict['day'],
                $conflict['start_time'],
                $conflict['end_time'],
                $conflict['venue'],
                $conflict['eca_name'],
                $conflict['eca_id']
            );
        }

        return $this->success(
            sprintf('Saved %d session(s) for "%s".', count($sessions), $eca->title),
            array(
                'eca_id'         => $eca_id,
                'eca_name'       => $eca->title,
                'sessions_saved' => count($sessions),
                'sessions'       => array_map(
                    static fn (array $session): array => array(
                        'day'        => $session['day'],
                        'start_time' => $session['start_time'],
                        'end_time'   => $session['end_time'],
                        'venue'      => $session['venue'],
                        'recurrence' => $session['recurrence'],
                    ),
                    $sessions
                ),
                'warnings'       => $warnings,
                'conflicts'      => $conflicts,
            )
        );
    }

    /**
     * The first pair of sessions in this payload that share a minute.
     *
     * @param list<array{day: string, start_at: int|null, end_at: int|null}> $sessions
     * @return array{first: int, second: int, day: string}|null
     */
    private function firstOverlap(array $sessions): ?array
    {
        $seen = count($sessions);

        for ($i = 0; $i < $seen; $i++) {
            for ($j = $i + 1; $j < $seen; $j++) {
                if ($sessions[ $i ]['day'] !== $sessions[ $j ]['day']) {
                    continue;
                }

                if (
                    Schedule::overlaps(
                        (int) $sessions[ $i ]['start_at'],
                        (int) $sessions[ $i ]['end_at'],
                        (int) $sessions[ $j ]['start_at'],
                        (int) $sessions[ $j ]['end_at'],
                    )
                ) {
                    return array(
                        'first'  => $i + 1,
                        'second' => $j + 1,
                        'day'    => $sessions[ $i ]['day'],
                    );
                }
            }
        }

        return null;
    }

    /**
     * Other activities that hold a session in the same room at the same time.
     *
     * Reads the stored `_eca_sessions` as well as the single-slot mirror, so an
     * activity with several sessions is compared on all of them rather than
     * only the first. An activity with no venue is skipped: a blank room is a
     * room not booked.
     *
     * @param list<array{day: string, start_at: int|null, end_at: int|null}> $sessions
     * @return list<array{eca_id: int, eca_name: string, day: string, start_time: string, end_time: string, venue: string}>
     */
    private function venueConflicts(int $eca_id, array $sessions): array
    {
        $wanted = array();

        foreach ($sessions as $session) {
            if ('' !== $session['venue']) {
                $wanted[] = strtolower($session['venue']);
            }
        }

        if (array() === $wanted) {
            return array();
        }

        $page      = $this->content->query(
            new ContentQuery(types: array( 'mcp_ai_eca' ), perPage: self::SCAN_LIMIT)
        );
        $conflicts = array();

        foreach ($page->items as $other) {
            if (! $other instanceof ContentItem || $other->id === $eca_id) {
                continue;
            }

            foreach ($this->heldSessions($other) as $held) {
                if (! in_array(strtolower($held['venue']), $wanted, true)) {
                    continue;
                }

                $start_at = Schedule::minutes($held['start_time']);
                $end_at   = Schedule::minutes($held['end_time']);

                if (null === $start_at || null === $end_at) {
                    continue;
                }

                foreach ($sessions as $session) {
                    if ($session['day'] !== $held['day'] || '' === $session['venue']) {
                        continue;
                    }

                    if (strtolower($session['venue']) !== strtolower($held['venue'])) {
                        continue;
                    }

                    if (! Schedule::overlaps((int) $session['start_at'], (int) $session['end_at'], $start_at, $end_at)) {
                        continue;
                    }

                    $conflicts[] = array(
                        'eca_id'     => $other->id,
                        'eca_name'   => $other->title,
                        'day'        => $held['day'],
                        'start_time' => $held['start_time'],
                        'end_time'   => $held['end_time'],
                        'venue'      => $held['venue'],
                    );

                    continue 3;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Every session an activity holds, from both storage shapes.
     *
     * @return list<array{day: string, start_time: string, end_time: string, venue: string}>
     */
    private function heldSessions(ContentItem $eca): array
    {
        $held = array();
        $raw  = $eca->meta['_eca_sessions'] ?? array();

        if (is_array($raw)) {
            foreach ($raw as $session) {
                if (! is_array($session)) {
                    continue;
                }

                $held[] = array(
                    'day'        => trim((string) ( $session['day'] ?? '' )),
                    'start_time' => trim((string) ( $session['start_time'] ?? '' )),
                    'end_time'   => trim((string) ( $session['end_time'] ?? '' )),
                    'venue'      => trim((string) ( $session['venue'] ?? '' )),
                );
            }
        }

        if (array() === $held) {
            $held[] = array(
                'day'        => trim((string) ( $eca->meta['_eca_day'] ?? '' )),
                'start_time' => trim((string) ( $eca->meta['_eca_start_time'] ?? '' )),
                'end_time'   => trim((string) ( $eca->meta['_eca_end_time'] ?? '' )),
                'venue'      => trim((string) ( $eca->meta['_eca_venue'] ?? '' )),
            );
        }

        return array_values(
            array_filter($held, static fn (array $s): bool => '' !== $s['day'] && '' !== $s['venue'])
        );
    }
}
