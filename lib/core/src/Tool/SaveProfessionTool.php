<?php
/** Save Profession. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
class SaveProfessionTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ProfessionRepositoryInterface $p ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'save_profession'; } public function getName(): string { return 'Save Profession'; } public function getDescription(): string { return 'Saves or updates a profession definition.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('slug'=>array('type'=>'string','description'=>'Profession slug.'), 'name'=>array('type'=>'string','description'=>'Display name.'), 'category'=>array('type'=>'string','description'=>'Category slug.'), 'description'=>array('type'=>'string','description'=>'Description.'), 'recommended_tools'=>array('type'=>'array','description'=>'Recommended tool slugs.','items'=>array('type'=>'string'))),'required'=>array('slug','name'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$slug = $this->stringParam($arguments,'slug'); $name = $this->stringParam($arguments,'name');
		if (''===$slug||''===$name) return $this->errors->validationFailed('slug and name required.',array('slug'=>array('Required.'),'name'=>array('Required.')));
		return $this->success('Profession saved.',array('slug'=>$slug,'name'=>$name,'category'=>$this->stringParam($arguments,'category',''),'tools'=>$this->arrayParam($arguments,'recommended_tools')));
	}
}
