<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceRecommendedDatasetsTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_recommended_datasets'; }
    public function getName(): string { return 'HuggingFace Recommended Datasets'; }
    public function getDescription(): string { return 'Returns a curated list of recommended HuggingFace datasets organized by category.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['category'=>['type'=>'string','description'=>'Filter by category (nlp, vision, audio, etc.)']],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $category = $this->stringParam($arguments,'category');
        // Curated list — no API call needed for recommendations.
        $all = [['dataset'=>'imdb','category'=>'nlp','description'=>'Movie review sentiment classification','rows'=>50000],['dataset'=>'common_voice','category'=>'audio','description'=>'Multi-language speech dataset','rows'=>2500000],['dataset'=>'cifar10','category'=>'vision','description'=>'60K 32x32 color images in 10 classes','rows'=>60000],['dataset'=>'squad','category'=>'nlp','description'=>'Stanford Question Answering Dataset','rows'=>100000],['dataset'=>'mnist','category'=>'vision','description'=>'Handwritten digit recognition','rows'=>70000],['dataset'=>'glue','category'=>'nlp','description'=>'General Language Understanding Evaluation benchmark','rows'=>500000],['dataset'=>'wikitext','category'=>'nlp','description'=>'Wikipedia articles for language modeling','rows'=>30000],['dataset'=>'fashion_mnist','category'=>'vision','description':'Fashion product image classification','rows'=>70000]];
        if (''!==$category) { $all = array_values(array_filter($all,fn($d)=>$d['category']===$category)); }
        return $this->collection("Recommended datasets".(''!==$category?" in '{$category}'":'').".",$all,count($all));
    }
}
