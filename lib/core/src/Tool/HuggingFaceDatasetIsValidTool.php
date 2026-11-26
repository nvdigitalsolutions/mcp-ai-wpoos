<?php

/** @package Nvoos\Core @since 1.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class HuggingFaceDatasetIsValidTool extends AbstractHuggingFaceTool
{
    /**
     * The datasets-server endpoint this tool calls.
     *
     * Public because the dataset browser derives its catalogue from it:
     * one declaration, two readers, and an endpoint change cannot leave
     * the page describing a route that no longer exists.
     */
    public const ENDPOINT = '/is-valid';

    public function getSlug(): string
    {
        return 'huggingface_dataset_is_valid';
    }
    public function getName(): string
    {
        return 'HuggingFace Dataset Is Valid';
    }
    public function getDescription(): string
    {
        return 'Validates whether a dataset and optional config exist on HuggingFace.';
    }
    public function getParametersSchema(): array
    {
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
            ),
            'required'             => array( 'dataset' ),
            'additionalProperties' => false,
        );
    }
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $dataset = $this->requireDataset($arguments);
        if ($this->errors->isError($dataset)) {
            return $dataset;
        }
        try {
            $params = array( 'dataset' => $dataset );
            $config = $this->stringParam($arguments, 'config');
            if ('' !== $config) {
                $params['config'] = $config;
            }
            $data = $this->apiGet(self::ENDPOINT, $params);
            if ($this->errors->isError($data)) {
                return $data;
            }
            $valid = $data['valid'] ?? false;
            return $this->success(
                $valid ? "Dataset '{$dataset}' is valid." : "Dataset '{$dataset}' is not valid.",
                array(
                    'dataset' => $dataset,
                    'valid'   => $valid,
                )
            );
        } catch (\Exception $e) {
            return $this->errors->create('hf_validate_failed', $e->getMessage());
        }
    }
}
