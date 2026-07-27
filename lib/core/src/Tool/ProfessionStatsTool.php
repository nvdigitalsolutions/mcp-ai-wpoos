<?php
/** Profession Stats. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
class ProfessionStatsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ProfessionRepositoryInterface $p ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'profession_stats'; } public function getName(): string { return 'Profession Stats'; } public function getDescription(): string { return 'Returns statistics about available professions grouped by category.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array(),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$cats = $this->p->getCategories(); $total = 0; foreach ($cats as $c) $total += \count($c);
		return $this->success('Profession statistics.',array('total_professions'=>$total,'category_count'=>\count($cats),'categories'=>array_map(fn($c)=>count($c),$cats)));
	}
}
