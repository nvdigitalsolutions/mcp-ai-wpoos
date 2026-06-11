<?php
/**
 * Get Current User tool — retrieves info about the authenticated user.
 *
 * Uses AuthProviderInterface::currentUserId() and getUserInfo().
 * Framework-agnostic — works with any auth system.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\UserInfo;

class GetCurrentUserTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly AuthProviderInterface $auth,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_current_user';
	}

	public function getName(): string {
		return 'Get Current User';
	}

	public function getDescription(): string {
		return 'Retrieves information about the currently authenticated user, including login, display name, email, roles, and capabilities.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $this->auth->currentUserId();

		if ( 0 === $userId ) {
			return $this->success(
				'No authenticated user.',
				array(
					'authenticated' => false,
					'user_id'       => 0,
				),
			);
		}

		$userInfo = $this->auth->getUserInfo( $userId );

		if ( null === $userInfo ) {
			return $this->errors->notFound(
				sprintf( 'User with ID %d not found.', $userId ),
			);
		}

		return $this->success(
			sprintf( 'User "%s" retrieved.', $userInfo->displayName ),
			$userInfo->jsonSerialize(),
		);
	}
}
