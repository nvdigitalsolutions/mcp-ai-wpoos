<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class ClientQuestionAnsweringTool extends AbstractClientSideTool {
    public function getSlug(): string { return 'client_question_answering'; }
    public function getName(): string { return 'Question Answering (Client)'; }
    public function getDescription(): string { return 'Answers questions based on provided context using Transformers.js. Runs client-side.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['context'=>['type'=>'string','description'=>'Context text to search in'],'question'=>['type'=>'string','description'=>'Question to answer']],'required'=>['context','question'],'additionalProperties'=>false]; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $c = $this->stringParam($arguments,'context'); $q = $this->stringParam($arguments,'question');
        if (''===$c) return $this->errors->validationFailed('context is required.',['context'=>['Context text is required.']]);
        if (''===$q) return $this->errors->validationFailed('question is required.',['question'=>['Question is required.']]);
        return $this->success('Question answering will run in the browser using Transformers.js.',['client_side'=>true,'model'=>'Xenova/distilbert-base-cased-distilled-squad']);
    }
}
