<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class ClientSemanticSearchTool extends AbstractClientSideTool {
    public function getSlug(): string { return 'client_semantic_search'; }
    public function getName(): string { return 'Semantic Search (Client)'; }
    public function getDescription(): string { return 'Performs semantic search over text using Transformers.js embeddings. Runs client-side.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Search query'],'documents'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Array of text documents to search']],'required'=>['query','documents'],'additionalProperties'=>false]; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $q = $this->stringParam($arguments,'query'); $docs = $this->arrayParam($arguments,'documents');
        if (''===$q) return $this->errors->validationFailed('query is required.',['query'=>['Search query is required.']]);
        if ([]===$docs) return $this->errors->validationFailed('documents is required.',['documents'=>['At least one document is required.']]);
        return $this->success('Semantic search will run in the browser using Transformers.js.',['client_side'=>true,'model'=>'Xenova/all-MiniLM-L6-v2','document_count'=>count($docs)]);
    }
}
