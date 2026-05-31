<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Entity\ContentQuery;
class SearchContentTool extends AbstractTool {
    public function __construct(ErrorFactoryInterface $e,private readonly ContentStoreInterface $c){parent::__construct($e);}
    public function getSlug(): string { return 'search_content'; }
    public function getName(): string { return 'Search Content'; }
    public function getDescription(): string { return 'Searches content items by title and body text.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Search query'],'type'=>['type'=>'string','description'=>'Content type slug. Default: any.','default'=>'any'],'count'=>['type'=>'integer','description'=>'Results per page (1-50). Default: 10.','minimum'=>1,'maximum'=>50,'default'=>10],'page'=>['type'=>'integer','description'=>'Page number. Default: 1.','minimum'=>1,'default'=>1]],'required'=>['query'],'additionalProperties'=>false]; }
    public function getRequiredCapability(): string { return 'read'; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $query = $this->stringParam($arguments,'query'); if (''===$query) return $this->errors->validationFailed('query is required.',['query'=>['Search query is required.']]);
        $type = $this->stringParam($arguments,'type','any'); $types = 'any'===$type ? [] : [$type];
        $cq = new ContentQuery(types:$types,statuses:['publish'],search:$query,perPage:$this->intParam($arguments,'count',10),page:$this->intParam($arguments,'page',1),userId:$context['user_id']??null);
        $result = $this->c->query($cq);
        if (!$result->hasItems()) return $this->emptyResult("No results found for: {$query}");
        $items = array_map(fn($i)=>['id'=>$i->id,'title'=>$i->title,'type'=>$i->type,'excerpt'=>$i->excerpt,'slug'=>$i->slug,'created_at'=>$i->createdAt->format('c')],$result->items);
        return $this->collection("Found {$result->total} results for: {$query}",$items,$result->total);
    }
}
