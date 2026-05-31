<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class ClientExtractEntitiesTool extends AbstractClientSideTool {
    public function getSlug(): string { return 'client_extract_entities'; }
    public function getName(): string { return 'Extract Entities (Client)'; }
    public function getDescription(): string { return 'Extracts named entities (people, places, orgs) from text using Transformers.js. Runs client-side.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['text'=>['type'=>'string','description'=>'Text to analyze']],'required'=>['text'],'additionalProperties'=>false]; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $missing = $this->validateText($arguments); if (null!==$missing) return $this->errors->validationFailed("{$missing} is required.",[$missing=>["{$missing} is required."]]);
        return $this->success('Entity extraction will run in the browser using Transformers.js.',['client_side'=>true,'model'=>'Xenova/bert-base-NER']);
    }
}
