<?php
/**
 * Check Capability tool — verifies if the current user has a given permission.
 *
 * Uses AuthProviderInterface::currentUserId() and userCan().
 * Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class CheckCapabilityTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly AuthProviderInterface $auth,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'check_capability';
	}

	public function getName(): string {
		return 'Check Capability';
	}

	public function getDescription(): string {
		return 'Checks whether the current user has a specific capability (e.g., edit_posts, manage_options).';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'capability' => array(
					'type'        => 'string',
					'description' => 'The capability to check (e.g., edit_posts, manage_options, read).',
				),
				'object_id'  => array(
					'type'        => 'integer',
					'description' => 'Optional object ID for object-level capability checks (e.g., post ID for edit_post check).',
				),
			),
			'required'             => array( 'capability' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$capability = $this->stringParam( $arguments, 'capability' );
		if ( '' === $capability ) {
			return $this->errors->validationFailed(
				'capability is required.',
				array( 'capability' => array( 'A capability string is required.' ) ),
			);
		}

		$userId   = $this->auth->currentUserId();
		$objectId = isset( $arguments['object_id'] )
			? $this->intParam( $arguments, 'object_id' )
			: null;

		$hasCapability = $this->auth->userCan( $userId, $capability, $objectId );

		$data = array(
			'user_id'    => $userId,
			'capability' => $capability,
			'granted'    => $hasCapability,
		);

		if ( null !== $objectId ) {
			$data['object_id'] = $objectId;
		}

		return $this->success(
			$hasCapability
				? sprintf( 'User %d has the "%s" capability.', $userId, $capability )
				: sprintf( 'User %d does NOT have the "%s" capability.', $userId, $capability ),
			$data,
		);
	}
}
