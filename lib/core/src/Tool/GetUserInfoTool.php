<?php
/** @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
class GetUserInfoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly AuthProviderInterface $a ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'get_user_info'; }
	public function getName(): string {
		return 'Get User Info'; }
	public function getDescription(): string {
		return 'Retrieves information about a WordPress user by ID.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => 'User ID. Default: current user.',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $this->intParam( $arguments, 'user_id', $context['user_id'] ?? 0 );
		if ( $userId <= 0 ) {
			return $this->errors->validationFailed( 'user_id is required.', array( 'user_id' => array( 'User ID is required.' ) ) );
		}
		$info = $this->a->getUserInfo( $userId );
		if ( null === $info ) {
			return $this->errors->notFound( 'User not found.' );
		}
		return $this->success( 'User info retrieved.', $info->jsonSerialize() );
	}
}
