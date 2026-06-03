<?php
declare(strict_types=1);
namespace Oos\Core\Tool;

class FormatDateTool extends AbstractTool {
	public function getSlug(): string { return 'format_date'; }
	public function getName(): string { return 'Format Date'; }
	public function getDescription(): string { return 'Converts dates between formats: ISO 8601, Unix timestamps, and PHP date() formats.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('date'=>array('type'=>'string','description'=>'Date string to convert'),'to_format'=>array('type'=>'string','description'=>'Output format: Y-m-d H:i:s, iso, rfc, unix','default'=>'Y-m-d H:i:s'),'timezone'=>array('type'=>'string','description'=>'Output timezone','default'=>'UTC')),'required'=>array('date'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$date = $this->stringParam($arguments,'date');
		if(''===$date) return $this->errors->validationFailed('date required.',array('date'=>array('Date string required.')));
		$toFormat = $this->stringParam($arguments,'to_format','Y-m-d H:i:s');
		$timezone = $this->stringParam($arguments,'timezone','UTC');
		$ts = strtotime($date);
		if(false===$ts) return $this->errors->create('invalid_date',"Cannot parse: $date");
		try { $dt = (new \DateTimeImmutable('@'.$ts))->setTimezone(new \DateTimeZone($timezone)); }
		catch(\Throwable $e){ return $this->errors->create('timezone_error',$e->getMessage()); }
		$out = match($toFormat){'iso'=>$dt->format('c'),'rfc'=>$dt->format('r'),'unix'=>(string)$dt->getTimestamp(),default=>$dt->format($toFormat)};
		return $this->success("Converted: $out",array('input'=>$date,'output'=>$out,'timestamp'=>$ts,'timezone'=>$timezone,'iso'=>$dt->format('c')));
	}
}
