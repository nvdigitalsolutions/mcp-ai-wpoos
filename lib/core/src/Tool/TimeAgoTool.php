<?php
declare(strict_types=1);
namespace Oos\Core\Tool;

class TimeAgoTool extends AbstractTool {
	private const I = array('year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'minute'=>60,'second'=>1);
	public function getSlug(): string { return 'time_ago'; }
	public function getName(): string { return 'Time Ago'; }
	public function getDescription(): string { return 'Converts timestamps to human-readable relative time (e.g., "3 hours ago", "in 2 days").'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('date'=>array('type'=>'string','description'=>'Unix timestamp or date string')),'required'=>array('date'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$date = $this->stringParam($arguments,'date');
		if(''===$date) return $this->errors->validationFailed('date required.',array('date'=>array('Date required.')));
		$ts = is_numeric($date)?(int)$date:strtotime($date);
		if(false===$ts||$ts<=0) return $this->errors->create('invalid_date',"Cannot parse: $date");
		$now=time(); $diff=$now-$ts; $future=$diff<0; $diff=abs($diff);
		if($diff<5) $expr='just now';
		else foreach(self::I as $u=>$s){ $c=(int)($diff/$s); if($c>=1){ $u=1===$c?$u:$u.'s'; $expr=$future?"in $c $u":"$c $u ago"; break; } }
		return $this->success($expr??'',array('input'=>$date,'timestamp'=>$ts,'now'=>$now,'diff_secs'=>$now-$ts,'expression'=>$expr??'','is_future'=>$future));
	}
}
