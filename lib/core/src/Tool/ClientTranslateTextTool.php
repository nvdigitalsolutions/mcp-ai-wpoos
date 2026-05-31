<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class ClientTranslateTextTool extends AbstractClientSideTool {
    public function getSlug(): string { return 'client_translate_text'; }
    public function getName(): string { return 'Translate Text (Client)'; }
    public function getDescription(): string { return 'Translates text between 200+ languages using Transformers.js. Runs client-side.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['text'=>['type'=>'string','description'=>'Text to translate'],'source_lang'=>['type'=>'string','description'=>'Source language code'],'target_lang'=>['type'=>'string','description'=>'Target language code','default'=>'en']],'required'=>['text'],'additionalProperties'=>false]; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $missing = $this->validateText($arguments); if (null!==$missing) return $this->errors->validationFailed("{$missing} is required.",[$missing=>["{$missing} is required."]]);
        return $this->success('Translation will run in the browser using Transformers.js.',['client_side'=>true,'model'=>'Xenova/nllb-200-distilled-600M']);
    }
}
