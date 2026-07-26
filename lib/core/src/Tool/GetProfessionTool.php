<?php
/** Get Profession. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
class GetProfessionTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ProfessionRepositoryInterface $p ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'get_profession'; } public function getName(): string { return 'Get Profession'; } public function getDescription(): string { return 'Retrieves a profession definition by slug.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('slug'=>array('type'=>'string','description'=>'Profession slug.')),'required'=>array('slug'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$slug = $this->stringParam($arguments,'slug'); if (''===$slug) return $this->errors->validationFailed('Slug required.',array('slug'=>array('Required.')));
		$r = $this->p->getBySlug($slug); return $r['found'] ? $this->success('Profession found.',$r['profession']??array()) : $this->errors->notFound('Profession not found.');
	}
}
