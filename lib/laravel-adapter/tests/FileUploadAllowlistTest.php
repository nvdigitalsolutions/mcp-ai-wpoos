<?php
/**
 * The upload allowlist and the registry must agree.
 *
 * Two lists decide whether a file can be added to the library: the upload
 * service's allowlist (what a caller may pick) and the registry's type map (what
 * can actually be filed). They drifted once — `audio/x-m4a` and
 * `audio/x-hx-aac-adts` were storable but not uploadable, and for a while the
 * reverse too, so an `extract_audio` result in m4a could not be stored while the
 * uploader accepted nothing audio at all. Each direction is a different defect:
 *
 *  - allowed but not storable → validation passes, the write fails;
 *  - storable but not allowed → a type the library can serve that a user can
 *    never add.
 *
 * These are constant-level assertions, so they need no database and no disk.
 *
 * @package Nvoos\Laravel\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\FileUploadService;
use PHPUnit\Framework\TestCase;

final class FileUploadAllowlistTest extends TestCase {

	public function test_nothing_is_uploadable_that_the_registry_would_refuse(): void {
		$unreachable = \array_diff(
			FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES,
			FileStore::acceptedTypes(),
		);

		$this->assertSame(
			array(),
			\array_values( $unreachable ),
			'These types pass upload validation and then fail in the registry. Add them to FileStore::TYPES or remove them from the allowlist.',
		);
	}

	public function test_every_audio_and_video_type_the_registry_files_is_uploadable(): void {
		$media = \array_filter(
			FileStore::acceptedTypes(),
			static fn ( string $type ): bool => \str_starts_with( $type, 'audio/' ) || \str_starts_with( $type, 'video/' ),
		);

		$missing = \array_diff( $media, FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES );

		$this->assertSame(
			array(),
			\array_values( $missing ),
			'The library can serve these but a user can never add them. Add them to FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES.',
		);
	}

	public function test_every_uploadable_type_has_an_extension_on_the_no_registry_path(): void {
		foreach ( FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES as $type ) {
			$this->assertNotSame(
				'bin',
				FileUploadService::extensionFor( $type ),
				$type . ' would be stored as .bin by a host with no registry bound.',
			);
		}
	}

	public function test_the_media_families_are_actually_present(): void {
		// A guard against the list being trimmed back to images and documents:
		// the transcription tools resolve an audio file by id, so audio must be
		// uploadable for that path to be reachable by a user.
		foreach ( array( 'audio/mpeg', 'audio/x-m4a', 'video/mp4' ) as $type ) {
			$this->assertContains( $type, FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES );
		}
	}
}
