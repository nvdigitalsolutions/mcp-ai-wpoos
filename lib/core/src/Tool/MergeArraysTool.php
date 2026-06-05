<?php
declare(strict_types=1);
namespace Nvoos\Core\Tool;

class MergeArraysTool extends AbstractTool {
	public function getSlug(): string { return 'merge_arrays'; }
	public function getName(): string { return 'Merge Arrays'; }
	public function getDescription(): string { return 'Merges multiple arrays/objects with shallow, deep, or overwrite strategies.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('arrays'=>array('type'=>'array','description'=>'Arrays to merge','items'=>array('type'=>'object')),'strategy'=>array('type'=>'string','enum'=>array('shallow','deep','overwrite'),'default'=>'deep')),'required'=>array('arrays'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$arrays = $arguments['arrays']??null;
		$s = $this->stringParam($arguments,'strategy','deep');
		if(!is_array($arrays)||[]===$arrays) return $this->errors->validationFailed('arrays required.',array('arrays'=>array('Provide arrays to merge.')));
		$r = array_shift($arrays);
		foreach($arrays as $m){ if(!is_array($m)) continue; $r = match($s){'overwrite'=>array_replace_recursive($r,$m),'shallow'=>array_merge($r,$m),default=>$this->dm($r,$m)}; }
		return $this->success("Merged ($s).",array('result'=>$r,'strategy'=>$s,'keys'=>count($r)));
	}
	private function dm(array $a,array $b): array { foreach($b as $k=>$v){ if(is_array($v)&&isset($a[$k])&&is_array($a[$k])) $a[$k]=$this->dm($a[$k],$v); else $a[$k]=$v; } return $a; }
}
