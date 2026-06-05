<?php
declare(strict_types=1);
namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GetPostTaxonomiesTool extends AbstractTool {
	public function __construct(ErrorFactoryInterface $errors, private readonly ContentStoreInterface $content) { parent::__construct($errors); }
	public function getSlug(): string { return 'get_post_taxonomies'; }
	public function getName(): string { return 'Get Post Taxonomies'; }
	public function getDescription(): string { return 'Retrieves all taxonomy terms assigned to a post, grouped by taxonomy.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('post_id'=>array('type'=>'integer','description'=>'The post ID','minimum'=>1)),'required'=>array('post_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$postId = $this->intParam($arguments,'post_id');
		if($postId<=0) return $this->errors->validationFailed('post_id required.',array('post_id'=>array('Valid post ID required.')));
		$terms = $this->content->getTaxonomyTerms($postId);
		$count = array_sum(array_map('count',$terms));
		return $this->success("$count terms across ".count($terms).' taxonomies.',array('post_id'=>$postId,'taxonomies'=>$terms,'total_terms'=>$count));
	}
}
