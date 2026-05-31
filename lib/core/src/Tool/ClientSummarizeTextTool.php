<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class ClientSummarizeTextTool extends AbstractClientSideTool {
    public function getSlug(): string { return 'client_summarize_text'; }
    public function getName(): string { return 'Summarize Text (Client)'; }
    public function getDescription(): string { return 'Summarizes text using Transformers.js in the browser. Runs entirely client-side.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['text'=>['type'=>'string','description'=>'Text to summarize']],'required'=>['text'],'additionalProperties'=>false]; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $missing = $this->validateText($arguments); if (null!==$missing) return $this->errors->validationFailed("{$missing} is required.",[$missing=>["{$missing} is required."]]);
        return $this->success('Text summarization will run in the browser using Transformers.js.',['client_side'=>true,'model'=>'Xenova/distilbart-cnn-6-6']);
    }
}
