<?php
/** List Professions. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
class ListProfessionsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ProfessionRepositoryInterface $p ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'list_professions'; } public function getName(): string { return 'List Professions'; } public function getDescription(): string { return 'Lists all available professions, optionally filtered by category.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('category'=>array('type'=>'string','description'=>'Filter by category slug.')),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$cat = $this->stringParam($arguments,'category');
		$all = '' !== $cat ? $this->p->getByCategory($cat) : $this->p->getAll();
		return $this->collection('Found '.\count($all).' profession(s).',\array_values($all),\count($all));
	}
}
