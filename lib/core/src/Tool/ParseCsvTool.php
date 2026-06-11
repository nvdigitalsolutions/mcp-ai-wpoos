<?php
declare(strict_types=1);
namespace Nvoos\Core\Tool;

class ParseCsvTool extends AbstractTool {
	public function getSlug(): string { return 'parse_csv'; }
	public function getName(): string { return 'Parse CSV'; }
	public function getDescription(): string { return 'Parses CSV strings into structured arrays with header detection.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('csv'=>array('type'=>'string','description'=>'CSV content'),'delimiter'=>array('type'=>'string','default'=>','),'has_header'=>array('type'=>'boolean','default'=>true)),'required'=>array('csv'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$csv = $this->stringParam($arguments,'csv');
		if(''===$csv) return $this->errors->validationFailed('csv required.',array('csv'=>array('CSV content required.')));
		$del = $this->stringParam($arguments,'delimiter',',');
		$hdr = $this->boolParam($arguments,'has_header',true);
		$lines = explode("\n",trim($csv)); $rows=[]; $header=[];
		foreach($lines as $i=>$line){ $line=trim($line); if(''===$line) continue; $cols=str_getcsv($line,$del); if(0===$i&&$hdr){ $header=$cols; continue; } if($hdr&&[]!==$header){ $row=[]; foreach($cols as $j=>$v) $row[$header[$j]??"col_$j"]=$v; $rows[]=$row; } else $rows[]=$cols; }
		return $this->success(count($rows).' rows parsed.',array('rows'=>$rows,'count'=>count($rows),'header'=>$hdr?$header:[],'delimiter'=>$del));
	}
}
