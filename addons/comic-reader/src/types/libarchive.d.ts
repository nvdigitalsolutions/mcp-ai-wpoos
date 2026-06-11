/**
 * Type declarations for libarchive.js — NV oOS Comic Reader.
 *
 * libarchive.js does not ship its own TypeScript declarations.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.2.0
 */

declare module 'libarchive.js/main.js' {
	interface ArchiveEntry {
		name: string;
		file: File | Blob;
	}

	interface ArchiveInstance {
		extractFiles(
			callback?: ( entry: ArchiveEntry ) => void
		): Promise< Record< string, { file: File | Blob } > >;
	}

	interface ArchiveModule {
		Archive: {
			init( options: { workerUrl: string } ): void;
			open( file: File ): Promise< ArchiveInstance >;
		};
	}

	const mod: ArchiveModule;
	export = mod;
}
