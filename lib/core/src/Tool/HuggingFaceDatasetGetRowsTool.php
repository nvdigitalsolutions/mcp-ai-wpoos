<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetGetRowsTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_get_rows'; }
    public function getName(): string { return 'HuggingFace Dataset Get Rows'; }
    public function getDescription(): string { return 'Retrieves paginated rows from a HuggingFace dataset split.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default'],'split'=>['type'=>'string','description'=>'Split name','default'=>'train'],'offset'=>['type'=>'integer','description'=>'Starting row offset','default'=>0],'length'=>['type'=>'integer','description'=>'Number of rows','default'=>100]],'required'=>['dataset'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        try {
            $data = $this->apiGet("/rows",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default'),'split'=>$this->stringParam($arguments,'split','train'),'offset'=>$this->intParam($arguments,'offset'),'length'=>$this->intParam($arguments,'length',100)]);
            if ($this->errors->isError($data)) return $data;
            $rows = $data['rows'] ?? []; $total = $data['num_rows_total'] ?? count($rows);
            return $this->collection("Retrieved ".count($rows)." rows.", $rows, (int)$total);
        } catch (\Exception $e) { return $this->errors->create('hf_rows_failed',$e->getMessage()); }
    }
}
