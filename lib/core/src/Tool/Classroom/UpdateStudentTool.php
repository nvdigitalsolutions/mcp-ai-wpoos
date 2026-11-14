<?php
/**
 * Update Student tool — field-level patch.
 *
 * Mirrors `WP_MCP_AI_Tool_Update_Student` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, name-merge rule, validation and
 * result shape.
 *
 * The distinction from {@see CreateStudentTool} is the point of this tool
 * existing: here **only the supplied fields move**, and an explicitly empty
 * string clears a field. `create_student` requires both names on every call
 * and ignores empty optionals.
 *
 * Behaviour carried over deliberately:
 * - Supplying one half of the name merges against the stored half, then
 *   rewrites the record title — so `first_name: 'Amara'` alone keeps the
 *   existing surname.
 * - If the merged full name is empty, the title is left alone rather than
 *   blanked.
 * - An explicitly empty `house` (or any other option) **clears** it, because
 *   `isset()` is what decides whether a field was supplied.
 * - An invalid email is an error (`invalid_email`), not a silent drop.
 * - The result is `{student_id}` — the tool reports the record it touched, not
 *   the new state. Use `get_student` for that.
 *
 * Divergence, deliberate: **permission is the store's.** WordPress checks only
 * `edit_posts` here, then writes meta regardless of who owns the record; this
 * port pre-flights `userCanAccess( ..., 'edit' )` on the record, which is the
 * rule every other write in the family already follows.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class UpdateStudentTool extends AbstractTool {

	/**
	 * Argument → student meta key for the plain-text fields.
	 */
	private const FIELDS = array(
		'year_group' => '_student_year_group',
		'house'      => '_student_house',
		'email'      => '_student_email',
		'isams_id'   => '_student_isams_id',
	);

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'update_student';
	}

	public function getName(): string {
		return 'Update Student';
	}

	public function getDescription(): string {
		return 'Updates individual fields on an existing student record. Only the supplied fields are touched, and an empty string clears one.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id' => array(
					'type'        => 'integer',
					'description' => 'Student record ID to update (required)',
					'minimum'     => 1,
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => 'New first name',
					'maxLength'   => 100,
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => 'New last name',
					'maxLength'   => 100,
				),
				'year_group' => array(
					'type'        => 'string',
					'description' => 'New year group',
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => 'New house name',
					'maxLength'   => 100,
				),
				'email'      => array(
					'type'        => 'string',
					'description' => 'New email address',
					'format'      => 'email',
				),
				'isams_id'   => array(
					'type'        => 'string',
					'description' => 'New external (iSAMS) student id',
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'student_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id    = (int) ( $context['user_id'] ?? 0 );
		$student_id = $this->intParam( $arguments, 'student_id' );

		if ( $student_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'Student ID is required.' );
		}

		$student = $this->content->find( $student_id );

		if ( ! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type ) {
			return $this->errors->create( 'invalid_student', 'Invalid student ID.' );
		}

		if ( ! $this->content->userCanAccess( $student_id, $user_id, 'edit' ) ) {
			return $this->errors->forbidden( 'You do not have permission to update this student.' );
		}

		$meta  = array();
		$title = null;

		if ( isset( $arguments['first_name'] ) || isset( $arguments['last_name'] ) ) {
			$first = isset( $arguments['first_name'] )
				? Text::singleLine( (string) $arguments['first_name'] )
				: $this->textMeta( $student, '_student_first_name' );

			$last = isset( $arguments['last_name'] )
				? Text::singleLine( (string) $arguments['last_name'] )
				: $this->textMeta( $student, '_student_last_name' );

			$full_name = trim( $first . ' ' . $last );

			// An emptied name pair leaves the title alone rather than blanking it.
			if ( '' !== $full_name ) {
				$title = $full_name;
			}

			if ( isset( $arguments['first_name'] ) ) {
				$meta['_student_first_name'] = $first;
			}

			if ( isset( $arguments['last_name'] ) ) {
				$meta['_student_last_name'] = $last;
			}
		}

		foreach ( self::FIELDS as $argument => $meta_key ) {
			if ( ! isset( $arguments[ $argument ] ) ) {
				continue;
			}

			$value = Text::singleLine( (string) $arguments[ $argument ] );

			if ( 'email' === $argument && '' !== $value && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
				return $this->errors->create( 'invalid_email', 'Invalid email address.' );
			}

			$meta[ $meta_key ] = $value;
		}

		try {
			$this->content->update(
				$student_id,
				new UpdateContentCommand(
					userId: $user_id,
					title: $title,
					meta: $meta,
				)
			);
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'student_write_failed', $e->getMessage() );
		}

		return $this->success(
			'Student updated successfully.',
			array( 'student_id' => $student_id )
		);
	}

	private function textMeta( ContentItem $item, string $key ): string {
		$value = $item->meta[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
