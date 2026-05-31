<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

class HuggingFaceDatasetSearchTool extends AbstractHuggingFaceTool {

	public function getSlug(): string {
		return 'huggingface_dataset_search'; }
	public function getName(): string {
		return 'HuggingFace Dataset Search'; }
	public function getDescription(): string {
		return 'Full-text search within a HuggingFace dataset split.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'dataset' => array(
					'type'        => 'string',
					'description' => 'Dataset ID',
				),
				'config'  => array(
					'type'        => 'string',
					'description' => 'Config name',
				),
				'split'   => array(
					'type'        => 'string',
					'description' => 'Split name',
				),
				'query'   => array(
					'type'        => 'string',
					'description' => 'Search query',
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => 'Max results',
					'default'     => 50,
				),
			),
			'required'             => array( 'dataset', 'query' ),
			'additionalProperties' => false,
		); }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$dataset = $this->requireDataset( $arguments );
		if ( $this->errors->isError( $dataset ) ) {
			return $dataset;
		}
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed( 'query is required.', array( 'query' => array( 'Search query is required.' ) ) );
		}
		$config = $this->stringParam( $arguments, 'config', 'default' );
		$split  = $this->stringParam( $arguments, 'split', 'train' );
		try {
			$data = $this->apiGet(
				'/search',
				array(
					'dataset' => $dataset,
					'config'  => $config,
					'split'   => $split,
					'query'   => $query,
					'length'  => $this->intParam( $arguments, 'limit', 50 ),
				)
			);
			if ( $this->errors->isError( $data ) ) {
				return $data;
			}
			$rows  = $data['rows'] ?? array();
			$total = $data['num_rows_total'] ?? count( $rows );
			return $this->collection( "Found {$total} matching rows.", array_slice( $rows, 0, 50 ), (int) $total );
		} catch ( \Exception $e ) {
			return $this->errors->create( 'hf_search_failed', $e->getMessage() ); }
	}
}
