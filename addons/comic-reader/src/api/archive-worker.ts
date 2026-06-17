/**
 * NV oOS Comic Reader — Archive Worker
 *
 * Web Worker that uses libarchive.js to extract comic archives (CBR/CBZ/CB7/CBT)
 * off the main thread. Returns sorted image Blobs plus metadata.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

// libarchive.js types (minimal — the actual API surface).
interface ArchiveEntry {
	name: string;
	file: File | Blob;
}

interface ArchiveInstance {
	extractFiles(callback?: (entry: ArchiveEntry) => void): Promise<Record<string, { file: File | Blob }>>;
}

interface ArchiveModule {
	Archive: {
		init(options: { workerUrl: string }): void;
		open(file: File): Promise<ArchiveInstance>;
	};
}

// We'll use dynamic import at runtime to keep libarchive.js out of the main bundle.
// The actual extraction happens in this worker.

const IMAGE_EXTENSIONS = /\.(jpe?g|png|gif|webp|bmp|tiff?)$/i;

self.onmessage = async (e: MessageEvent<{ file: ArrayBuffer; name: string }>) => {
	try {
		const { file: buffer, name } = e.data;
		const blob = new Blob([buffer]);
		const file = new File([blob], name);

		// Import libarchive.js dynamically.
		const libarchive = await import('libarchive.js/main.js') as unknown as ArchiveModule;
		libarchive.Archive.init({
			workerUrl: '', // We handle extraction inline in this worker.
		});

		const archive = await libarchive.Archive.open(file);
		const extracted = await archive.extractFiles();

		// Collect and sort image entries.
		const imageEntries: Array<{ name: string; blob: Blob }> = [];

		for (const [entryName, entry] of Object.entries(extracted)) {
			if (IMAGE_EXTENSIONS.test(entryName)) {
				const entryFile = entry.file || (entry as unknown as Blob);
				imageEntries.push({
					name: entryName,
					blob: entryFile instanceof Blob ? entryFile : new Blob([entryFile]),
				});
			}
		}

		// Sort by filename (natural sort).
		imageEntries.sort((a, b) =>
			a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' })
		);

		// Convert blobs to URLs and return.
		const pages = imageEntries.map((entry, index) => ({
			index,
			name: entry.name,
			url: URL.createObjectURL(entry.blob),
		}));

		self.postMessage({ type: 'success', pages, total: pages.length });

	} catch (err) {
		self.postMessage({
			type: 'error',
			message: err instanceof Error ? err.message : 'Unknown extraction error',
		});
	}
};

// Signal ready.
self.postMessage({ type: 'ready' });
