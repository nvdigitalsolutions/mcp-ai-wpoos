<?php
/**
 * Get File Info tool — retrieves metadata for a stored file.
 *
 * Uses FileStoreInterface — framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\FileStoreInterface;

class GetFileInfoTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly FileStoreInterface $files,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_file_info';
	}

	public function getName(): string {
		return 'Get File Info';
	}

	public function getDescription(): string {
		return 'Retrieves metadata for a stored file by its ID. Returns filename, MIME type, size, path, URL, and owner.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_id' => array(
					'type'        => 'integer',
					'description' => 'The file ID to retrieve.',
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

		$file = $this->files->getMetadata( $fileId );

		if ( null === $file ) {
			return $this->errors->notFound(
				sprintf( 'File with ID %d not found.', $fileId ),
			);
		}

		return $this->success(
			sprintf( 'File "%s" retrieved.', $file->filename ),
			$file->jsonSerialize(),
		);
	}
}
