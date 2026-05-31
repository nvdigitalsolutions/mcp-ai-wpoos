<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetFilterTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_filter'; }
    public function getName(): string { return 'HuggingFace Dataset Filter'; }
    public function getDescription(): string { return 'Retrieves filtered rows from a dataset split using a where clause.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default'],'split'=>['type'=>'string','description'=>'Split name','default'=>'train'],'where'=>['type'=>'string','description'=>'Filter expression (e.g., "age > 30")'],'limit'=>['type'=>'integer','description'=>'Max rows','default'=>100]],'required'=>['dataset','where'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        $where = $this->stringParam($arguments,'where'); if (''===$where) return $this->errors->validationFailed('where is required.',['where'=>['Filter expression is required.']]);
        try {
            $data = $this->apiGet("/filter",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default'),'split'=>$this->stringParam($arguments,'split','train'),'where'=>$where,'length'=>$this->intParam($arguments,'limit',100)]);
            if ($this->errors->isError($data)) return $data;
            $rows = $data['rows'] ?? []; $total = $data['num_rows_total'] ?? count($rows);
            return $this->collection("Found {$total} matching rows.", $rows, (int)$total);
        } catch (\Exception $e) { return $this->errors->create('hf_filter_failed',$e->getMessage()); }
    }
}
