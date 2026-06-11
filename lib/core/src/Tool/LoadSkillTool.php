<?php
/** @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Skill\SkillRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class LoadSkillTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SkillRegistry $skills ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'load_skill'; }
	public function getName(): string {
		return 'Load Skill'; }
	public function getDescription(): string {
		return 'Loads the full instructions for an agent skill by name.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Skill name to load',
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$name = $this->stringParam( $arguments, 'name' );
		if ( '' === $name ) {
			return $this->errors->validationFailed( 'name is required.', array( 'name' => array( 'Skill name is required.' ) ) );
		}
		$content = $this->skills->load( $name );
		if ( null === $content ) {
			return $this->errors->notFound( "Skill '{$name}' not found." );
		}
		return $this->success(
			"Loaded skill: {$name}",
			array(
				'name'    => $name,
				'content' => $content,
			)
		);
	}
}
