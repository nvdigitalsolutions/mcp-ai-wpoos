<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\ContentStoreInterface;
class DeletePostTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ContentStoreInterface $c ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'delete_post'; }
	public function getName(): string {
		return 'Delete Post'; }
	public function getDescription(): string {
		return 'Permanently deletes a content item.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the post to delete',
					'minimum'     => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'delete_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$postId = $this->intParam( $arguments, 'post_id' );
		if ( $postId <= 0 ) {
			return $this->errors->validationFailed( 'post_id is required.', array( 'post_id' => array( 'Post ID is required.' ) ) );
		}
		$userId = $context['user_id'] ?? 0;
		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in.' );
		}
		try {
			$this->c->delete( $postId, $userId );
			return $this->success( 'Post deleted.', array( 'id' => $postId ) ); } catch ( \Oos\Core\Domain\Error\NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() ); } catch ( \Oos\Core\Domain\Error\AccessDeniedException $e ) {
				return $this->errors->forbidden( $e->getMessage() ); } catch ( \Throwable $e ) {
				return $this->errors->create( 'delete_failed', $e->getMessage() ); }
	}
}
