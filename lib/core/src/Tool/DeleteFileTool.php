<?php
/**
 * Delete File tool — removes a stored file.
 *
 * Uses FileStoreInterface — framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

class DeleteFileTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly FileStoreInterface $files,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_file';
	}

	public function getName(): string {
		return 'Delete File';
	}

	public function getDescription(): string {
		return 'Permanently deletes a stored file by its ID. This action cannot be undone.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_id' => array(
					'type'        => 'integer',
					'description' => 'The file ID to delete.',
					'minimum'     => 1,
				),
			),
			'required'             => array( 'file_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'upload_files';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$fileId = $this->intParam( $arguments, 'file_id' );
		if ( $fileId <= 0 ) {
			return $this->errors->validationFailed(
				'file_id must be a positive integer.',
				array( 'file_id' => array( 'A valid file ID is required.' ) ),
			);
		}

		// Verify file exists before attempting delete.
		$file = $this->files->getMetadata( $fileId );
		if ( null === $file ) {
			return $this->errors->notFound(
				sprintf( 'File with ID %d not found.', $fileId ),
			);
		}

		try {
			$this->files->delete( $fileId );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'delete_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( 'File %d ("%s") deleted.', $fileId, $file->filename ),
			array(
				'file_id'  => $fileId,
				'filename' => $file->filename,
			),
		);
	}
}
