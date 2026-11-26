<?php

/**
 * Academic terms — create, list, get, transition and close.
 *
 * Ported from `WP_MCP_AI_Tool_Manage_ECA_Term` (Proposal 164 §164.59): same five
 * actions, same argument names and the same term shape. Four things are decided
 * for this runtime rather than copied:
 *
 * ### Terms live in the settings store
 *
 * WordPress keeps them in the `wp_mcp_ai_eca_terms` option. Here they are the
 * `eca_terms` setting, because that is the runtime's durable key/value surface
 * and a term list is a small enumerated configuration, not a record set. An
 * operator migrating a WordPress install copies the option's array across
 * unchanged; the shape is identical.
 *
 * ### The name is the join key
 *
 * WordPress links an activity to a term twice: `_eca_term` (free text) and
 * `_eca_term_id` (the generated id). This runtime writes only `_eca_term`, so a
 * term's **name** is what activities are matched on. Two consequences follow,
 * and both are on the record:
 *
 * - `get` counts, and `close` deactivates, the activities whose `_eca_term`
 *   equals the term name. WordPress joins on the id.
 * - Creating a second term with the same name is refused, because two terms
 *   that join to the same rows make every count and every transition ambiguous.
 *   WordPress permits it.
 *
 * ### Rollover retags, it does not snapshot
 *
 * WordPress copies each activity's roster into
 * `_eca_student_enrollments_{term_id}` and nothing ever reads that key. This
 * port does not create it. With `rollover_enrollments` the activities that
 * carried the outgoing term are retagged with the incoming term's name; their
 * rosters are already the same roster, and now the term filter in
 * `generate_eca_participation_report` agrees with reality.
 *
 * ### The attendance reset is scoped, counted and irreversible
 *
 * `reset_attendance` keeps its WordPress default of true because callers rely
 * on starting a term clean. WordPress deletes `_eca_attendance_log` on **every**
 * activity with one boolean. Here the delete is scoped to the activities that
 * belonged to the term being completed, and when there is no outgoing term it
 * says so in `attendance_scope` before doing it. The count of activities whose
 * log was cleared travels back as `attendance_reset`. The log holds dated
 * totals with no per-session provenance, so this deletion cannot be undone —
 * which is why the scope is named in the result.
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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class ManageEcaTermTool extends AbstractTool
{
    /** The settings key holding the term list. */
    public const SETTINGS_KEY = 'eca_terms';

    private const ACTIONS = array( 'create', 'list', 'get', 'transition', 'close' );

    private const STATUSES = array( 'upcoming', 'active', 'completed' );

    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'manage_eca_term';
    }

    public function getName(): string
    {
        return 'Manage ECA Term';
    }

    public function getDescription(): string
    {
        return 'Creates, lists, inspects, transitions and closes academic terms. Terms are the eca_terms setting, and an activity belongs to a term when its stored term name matches. Transition completes every active term, activates the target, optionally retags the outgoing term activities to the new term, and by default clears their attendance log - dated totals only, with no per-session history, so that deletion is irreversible and its scope is reported back.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action'               => array(
                    'type'        => 'string',
                    'enum'        => self::ACTIONS,
                    'description' => 'Term management action to perform.',
                ),
                'term_name'            => array(
                    'type'        => 'string',
                    'description' => 'Name of the term (required for create), e.g. "Term 1 2025-2026". Must be unique, because it is how activities are matched to a term.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'term_id'              => array(
                    'type'        => 'string',
                    'description' => 'The term id returned by create or list (required for get, transition and close).',
                    'maxLength'   => 100,
                ),
                'start_date'           => array(
                    'type'        => 'string',
                    'description' => 'Term start date as YYYY-MM-DD (used with create).',
                    'pattern'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
                ),
                'end_date'             => array(
                    'type'        => 'string',
                    'description' => 'Term end date as YYYY-MM-DD (used with create). Must be after start_date.',
                    'pattern'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
                ),
                'academic_year'        => array(
                    'type'        => 'string',
                    'description' => 'Academic year label, e.g. "2025-2026".',
                    'maxLength'   => 50,
                ),
                'rollover_enrollments' => array(
                    'type'        => 'boolean',
                    'description' => 'Retag the activities that carried the outgoing term with the new term, so the term filter keeps agreeing with the roster. Rosters themselves are untouched.',
                    'default'     => false,
                ),
                'reset_attendance'     => array(
                    'type'        => 'boolean',
                    'description' => 'Clear the attendance log on the activities being retagged. The log is dated totals only, so a cleared log cannot be reconstructed.',
                    'default'     => true,
                ),
            ),
            'required'   => array( 'action' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        // Terms reshape every activity's term membership, so this keeps
        // WordPress's operator-level check rather than the classroom family's
        // `edit_posts`.
        return 'manage_options';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $action  = strtolower(trim($this->stringParam($arguments, 'action')));
        $user_id = (int) ( $context['user_id'] ?? 0 );

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'Unknown action "' . $action . '".',
                array( 'action' => array( 'Use one of ' . implode(', ', self::ACTIONS) . '.' ) ),
            );
        }

        return match ($action) {
            'create'     => $this->create($arguments, $user_id),
            'list'       => $this->listing(),
            'get'        => $this->getTerm($arguments),
            'transition' => $this->transition($arguments, $user_id),
            'close'      => $this->close($arguments, $user_id),
        };
    }

    private function create(array $arguments, int $user_id): mixed
    {
        $name   = trim($this->stringParam($arguments, 'term_name'));
        $start  = trim($this->stringParam($arguments, 'start_date'));
        $end    = trim($this->stringParam($arguments, 'end_date'));
        $year   = trim($this->stringParam($arguments, 'academic_year'));
        $errors = array();

        if ('' === $name) {
            $errors['term_name'] = array( 'A term name is required.' );
        }

        if ('' !== $start && ! $this->isIsoDate($start)) {
            $errors['start_date'] = array( 'start_date must be YYYY-MM-DD.' );
        }

        if ('' !== $end && ! $this->isIsoDate($end)) {
            $errors['end_date'] = array( 'end_date must be YYYY-MM-DD.' );
        }

        if ('' !== $start && '' !== $end && ! isset($errors['start_date']) && ! isset($errors['end_date']) && $start >= $end) {
            $errors['end_date'] = array( 'start_date must be before end_date.' );
        }

        $terms = $this->terms();

        if ('' !== $name) {
            foreach ($terms as $term) {
                if (0 === strcasecmp($term['name'], $name)) {
                    $errors['term_name'] = array(
                        'A term named "' . $term['name'] . '" already exists (id ' . $term['id'] . '). Names are unique because activities are matched by name.',
                    );

                    break;
                }
            }
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The term cannot be created.', $errors);
        }

        $term = array(
            'id'            => 'term_' . bin2hex(random_bytes(6)),
            'name'          => $name,
            'start_date'    => $start,
            'end_date'      => $end,
            'academic_year' => $year,
            'status'        => 'upcoming',
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'created_by'    => $user_id,
        );

        $terms[] = $term;
        $this->save($terms);

        return $this->success(
            'Academic term "' . $name . '" created.',
            array( 'action' => 'create', 'term' => $term )
        );
    }

    /**
     * Every term, oldest start date first; terms without a date sort last.
     */
    private function listing(): mixed
    {
        $terms = $this->terms();

        usort(
            $terms,
            static function (array $a, array $b): int {
                $left  = '' !== $a['start_date'] ? $a['start_date'] : '9999-12-31';
                $right = '' !== $b['start_date'] ? $b['start_date'] : '9999-12-31';

                return strcmp($left, $right);
            }
        );

        return $this->success(
            sprintf('%d term(s).', count($terms)),
            array( 'action' => 'list', 'total' => count($terms), 'terms' => $terms )
        );
    }

    private function getTerm(array $arguments): mixed
    {
        $term_id = trim($this->stringParam($arguments, 'term_id'));

        if ('' === $term_id) {
            return $this->errors->validationFailed(
                'term_id is required for the get action.',
                array( 'term_id' => array( 'Use the id returned by create or list.' ) ),
            );
        }

        $term = $this->find($term_id);

        if (null === $term) {
            return $this->errors->notFound('There is no term with id ' . $term_id . '.');
        }

        $term['eca_count'] = count($this->activitiesFor($term['name']));

        return $this->success('Term ' . $term['name'] . '.', array( 'action' => 'get', 'term' => $term ));
    }

    private function transition(array $arguments, int $user_id): mixed
    {
        $term_id  = trim($this->stringParam($arguments, 'term_id'));
        $rollover = $this->boolParam($arguments, 'rollover_enrollments', false);
        $reset    = $this->boolParam($arguments, 'reset_attendance', true);

        if ('' === $term_id) {
            return $this->errors->validationFailed(
                'term_id is required for the transition action.',
                array( 'term_id' => array( 'The term to activate.' ) ),
            );
        }

        $terms   = $this->terms();
        $target  = null;
        $actives = array();

        foreach ($terms as $index => $term) {
            if ($term['id'] === $term_id) {
                $target = $index;
            }

            if ('active' === $term['status']) {
                $actives[] = $index;
            }
        }

        if (null === $target) {
            return $this->errors->notFound('There is no term with id ' . $term_id . '.');
        }

        if (in_array($target, $actives, true)) {
            return $this->errors->validationFailed(
                'Term ' . $terms[ $target ]['name'] . ' is already active.',
                array( 'term_id' => array( 'Choose the term to move to.' ) ),
            );
        }

        $now        = gmdate('Y-m-d H:i:s');
        $previous   = array();

        foreach ($actives as $index) {
            $terms[ $index ]['status']       = 'completed';
            $terms[ $index ]['completed_at'] = $now;
            $terms[ $index ]['completed_by'] = $user_id;
            $previous[]                      = $terms[ $index ];
        }

        $terms[ $target ]['status']       = 'active';
        $terms[ $target ]['activated_at'] = $now;
        $terms[ $target ]['activated_by'] = $user_id;

        $this->save($terms);

        $incoming  = $terms[ $target ];
        $outgoing  = array();

        foreach ($previous as $term) {
            $outgoing[] = $term['name'];
        }

        $activities = array();
        $scope      = 'outgoing_term';

        foreach ($outgoing as $name) {
            foreach ($this->activitiesFor($name) as $eca) {
                $activities[ $eca->id ] = $eca;
            }
        }

        if (array() === $outgoing) {
            $scope = 'all_activities';

            foreach ($this->allActivities() as $eca) {
                $activities[ $eca->id ] = $eca;
            }
        }

        $retagged = 0;

        if ($rollover) {
            foreach ($activities as $eca) {
                $current = trim((string) ( $eca->meta['_eca_term'] ?? '' ));

                if ($current === $incoming['name']) {
                    continue;
                }

                try {
                    $this->content->update(
                        $eca->id,
                        new UpdateContentCommand(userId: $user_id, meta: array( '_eca_term' => $incoming['name'] ))
                    );
                } catch (NotFoundException $e) {
                    continue;
                } catch (AccessDeniedException $e) {
                    return $this->errors->forbidden($e->getMessage());
                }

                ++$retagged;
            }
        }

        $cleared = 0;

        if ($reset) {
            foreach ($activities as $eca) {
                $log = $eca->meta['_eca_attendance_log'] ?? array();

                if (! is_array($log) || array() === $log) {
                    continue;
                }

                try {
                    $this->content->update(
                        $eca->id,
                        new UpdateContentCommand(userId: $user_id, meta: array( '_eca_attendance_log' => array() ))
                    );
                } catch (NotFoundException $e) {
                    continue;
                } catch (AccessDeniedException $e) {
                    return $this->errors->forbidden($e->getMessage());
                }

                ++$cleared;
            }
        }

        return $this->success(
            'Transitioned to term "' . $incoming['name'] . '".',
            array(
                'action'             => 'transition',
                'previous_term'      => array() === $previous ? null : $previous[ count($previous) - 1 ],
                'previous_terms'     => $previous,
                'active_term'        => $incoming,
                'enrollments_rolled' => $retagged,
                'activities_retagged' => $retagged,
                'attendance_reset'   => $cleared,
                'attendance_scope'   => $scope,
            )
        );
    }

    private function close(array $arguments, int $user_id): mixed
    {
        $term_id = trim($this->stringParam($arguments, 'term_id'));

        if ('' === $term_id) {
            return $this->errors->validationFailed(
                'term_id is required for the close action.',
                array( 'term_id' => array( 'The term to close.' ) ),
            );
        }

        $terms  = $this->terms();
        $target = null;

        foreach ($terms as $index => $term) {
            if ($term['id'] === $term_id) {
                $target = $index;

                break;
            }
        }

        if (null === $target) {
            return $this->errors->notFound('There is no term with id ' . $term_id . '.');
        }

        $terms[ $target ]['status']       = 'completed';
        $terms[ $target ]['completed_at'] = gmdate('Y-m-d H:i:s');
        $terms[ $target ]['completed_by'] = $user_id;

        $this->save($terms);

        $deactivated = 0;

        foreach ($this->activitiesFor($terms[ $target ]['name']) as $eca) {
            if ('inactive' === (string) ( $eca->meta['_eca_status'] ?? '' )) {
                continue;
            }

            try {
                $this->content->update(
                    $eca->id,
                    new UpdateContentCommand(userId: $user_id, meta: array( '_eca_status' => 'inactive' ))
                );
            } catch (NotFoundException $e) {
                continue;
            } catch (AccessDeniedException $e) {
                return $this->errors->forbidden($e->getMessage());
            }

            ++$deactivated;
        }

        return $this->success(
            'Term "' . $terms[ $target ]['name'] . '" closed.',
            array(
                'action'           => 'close',
                'term'             => $terms[ $target ],
                'ecas_deactivated' => $deactivated,
            )
        );
    }

    /**
     * The stored term list, normalised.
     *
     * A row without an id or a name cannot be joined or found, so it is dropped
     * rather than carried as a half-term that every count would silently miss.
     *
     * @return list<array<string, mixed>>
     */
    private function terms(): array
    {
        $raw = $this->settings->get(self::SETTINGS_KEY, array());

        if (! is_array($raw)) {
            return array();
        }

        $terms = array();

        foreach ($raw as $term) {
            if (! is_array($term)) {
                continue;
            }

            $id   = trim((string) ( $term['id'] ?? '' ));
            $name = trim((string) ( $term['name'] ?? '' ));

            if ('' === $id || '' === $name) {
                continue;
            }

            $status = strtolower((string) ( $term['status'] ?? 'upcoming' ));

            $terms[] = array_merge(
                $term,
                array(
                    'id'         => $id,
                    'name'       => $name,
                    'start_date' => trim((string) ( $term['start_date'] ?? '' )),
                    'end_date'   => trim((string) ( $term['end_date'] ?? '' )),
                    'status'     => in_array($status, self::STATUSES, true) ? $status : 'upcoming',
                )
            );
        }

        return $terms;
    }

    /**
     * @param list<array<string, mixed>> $terms
     */
    private function save(array $terms): void
    {
        $this->settings->set(self::SETTINGS_KEY, array_values($terms));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $term_id): ?array
    {
        foreach ($this->terms() as $term) {
            if ($term['id'] === $term_id) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Activities carrying a term name.
     *
     * @return list<ContentItem>
     */
    private function activitiesFor(string $name): array
    {
        if ('' === $name) {
            return array();
        }

        $page = $this->content->query(
            new ContentQuery(
                types: array( 'mcp_ai_eca' ),
                metaQuery: array(
                    array(
                        'key'     => '_eca_term',
                        'value'   => $name,
                        'compare' => '=',
                    ),
                ),
                perPage: self::SCAN_LIMIT,
            )
        );

        return array_values(array_filter($page->items, static fn ($item): bool => $item instanceof ContentItem));
    }

    /**
     * @return list<ContentItem>
     */
    private function allActivities(): array
    {
        $page = $this->content->query(
            new ContentQuery(types: array( 'mcp_ai_eca' ), perPage: self::SCAN_LIMIT)
        );

        return array_values(array_filter($page->items, static fn ($item): bool => $item instanceof ContentItem));
    }
}
