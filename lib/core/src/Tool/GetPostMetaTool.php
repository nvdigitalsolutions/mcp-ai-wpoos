<?php
declare(strict_types=1);
namespace Oos\Core\Tool;
use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;

class GetPostMetaTool extends AbstractTool {
	public function __construct(ErrorFactoryInterface $errors, private readonly ContentStoreInterface $content) { parent::__construct($errors); }
	public function getSlug(): string { return 'get_post_meta'; }
	public function getName(): string { return 'Get Post Meta'; }
	public function getDescription(): string { return 'Retrieves all metadata fields for a post, or a single field by key.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('post_id'=>array('type'=>'integer','description'=>'The post ID','minimum'=>1),'key'=>array('type'=>'string','description'=>'Optional meta key')),'required'=>array('post_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$postId = $this->intParam($arguments,'post_id');
		if($postId<=0) return $this->errors->validationFailed('post_id required.',array('post_id'=>array('Valid post ID required.')));
		$key = $this->stringParam($arguments,'key');
		$meta = $this->content->getMeta($postId);
		if(''!==$key){ $v=$meta[$key]??null; return $this->success(null!==$v?"Meta \"$key\" retrieved.":"Meta \"$key\" not found.",array('post_id'=>$postId,'key'=>$key,'value'=>$v,'found'=>null!==$v)); }
		return $this->success(count($meta).' meta fields.',array('post_id'=>$postId,'meta'=>$meta,'count'=>count($meta)));
	}
}
