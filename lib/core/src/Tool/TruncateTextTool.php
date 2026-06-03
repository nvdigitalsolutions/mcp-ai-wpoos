<?php
declare(strict_types=1);
namespace Oos\Core\Tool;

class TruncateTextTool extends AbstractTool {
	public function getSlug(): string { return 'truncate_text'; }
	public function getName(): string { return 'Truncate Text'; }
	public function getDescription(): string { return 'Truncates text to a maximum length with optional ellipsis and word boundary awareness.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('text'=>array('type'=>'string','description'=>'Text to truncate'),'max_length'=>array('type'=>'integer','description'=>'Maximum length','default'=>100,'minimum'=>1),'ellipsis'=>array('type'=>'string','description'=>'Suffix when truncated','default'=>'...'),'preserve_words'=>array('type'=>'boolean','description'=>'Don\'t break mid-word','default'=>true)),'required'=>array('text'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$text = $this->stringParam($arguments,'text'); if(''===$text) return $this->errors->validationFailed('text required.',array('text'=>array('Text is required.')));
		$max = $this->intParam($arguments,'max_length',100); $ellipsis = $this->stringParam($arguments,'ellipsis','...'); $preserve = $this->boolParam($arguments,'preserve_words',true);
		if(strlen($text)<=$max) return $this->success('Text fits.',array('text'=>$text,'truncated'=>false,'original_length'=>strlen($text),'max_length'=>$max));
		$truncated = substr($text,0,$max);
		if($preserve){ $lastSpace = strrpos($truncated,' '); if(false!==$lastSpace) $truncated = substr($truncated,0,$lastSpace); }
		$truncated = rtrim($truncated).$ellipsis;
		return $this->success('Text truncated.',array('text'=>$truncated,'truncated'=>true,'original_length'=>strlen($text),'truncated_length'=>strlen($truncated),'max_length'=>$max));
	}
}
