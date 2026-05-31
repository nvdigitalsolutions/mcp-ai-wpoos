<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Application\Skill\SkillRegistry;
class ListSkillsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SkillRegistry $skills ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'list_skills'; }
	public function getName(): string {
		return 'List Skills'; }
	public function getDescription(): string {
		return 'Lists all available agent skills with their descriptions.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$catalogue = $this->skills->catalogue();
		if ( array() === $catalogue ) {
			return $this->emptyResult( 'No skills registered.' );
		}
		return $this->collection( "Found {$this->skills->count()} skills.", $catalogue, $this->skills->count() );
	}
}
