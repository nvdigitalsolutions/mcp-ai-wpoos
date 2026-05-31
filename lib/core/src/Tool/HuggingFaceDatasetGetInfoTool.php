<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

class HuggingFaceDatasetGetInfoTool extends AbstractHuggingFaceTool {

	public function getSlug(): string {
		return 'huggingface_dataset_get_info'; }
	public function getName(): string {
		return 'HuggingFace Dataset Get Info'; }
	public function getDescription(): string {
		return 'Retrieves metadata and configuration info for a HuggingFace dataset.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'dataset' => array(
					'type'        => 'string',
					'description' => 'Dataset ID',
				),
			),
			'required'             => array( 'dataset' ),
			'additionalProperties' => false,
		); }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$dataset = $this->requireDataset( $arguments );
		if ( $this->errors->isError( $dataset ) ) {
			return $dataset;
		}
		try {
			$data = $this->apiGet( '/info', array( 'dataset' => $dataset ) );
			if ( $this->errors->isError( $data ) ) {
				return $data;
			}
			return $this->success( 'Dataset info retrieved.', $data['dataset_info'] ?? $data );
		} catch ( \Exception $e ) {
			return $this->errors->create( 'hf_info_failed', $e->getMessage() ); }
	}
}
