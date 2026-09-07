/**
 * NV oOS Comic Reader — Archive Worker
 *
 * Web Worker that uses libarchive.js to extract comic archives (CBR/CBZ/CB7/CBT)
 * off the main thread. Returns sorted image Blobs plus ComicInfo.xml metadata
 * when the archive embeds one.
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

export interface ComicInfoMetadata {
	title?: string;
	series?: string;
	number?: string;
	volume?: string;
	writer?: string;
	penciller?: string;
	publisher?: string;
	genre?: string;
	page_count?: string;
	rtl?: string; // "Yes"/"No" — RightToLeft flag.
	manga?: string; // "Yes"/"No"
	[key: string]: string | undefined;
}

const IMAGE_EXTENSIONS = /\.(jpe?g|png|gif|webp|bmp|tiff?)$/i;
const COMIC_INFO_RE = /comicinfo\.xml$/i;

const COMIC_INFO_FIELDS: string[] = [
	'title',
	'series',
	'number',
	'volume',
	'writer',
	'penciller',
	'publisher',
	'genre',
	'pagecount',
	'righttoleft',
	'manga',
];

/** Decode basic XML character entities. */
function decodeEntities(value: string): string {
	return value
		.replace(/&amp;/g, '&')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&quot;/g, '"')
		.replace(/&apos;/g, "'");
}

/**
 * Parse a ComicInfo.xml document with a small regex-based extractor.
 * (No DOMParser dependency — worker compatibility across browsers.)
 */
export function parseComicInfo(xml: string): ComicInfoMetadata {
	const metadata: ComicInfoMetadata = {};

	for (const field of COMIC_INFO_FIELDS) {
		// Match <Field ...>value</Field> ignoring attributes on the tag.
		const re = new RegExp(`<${field}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${field}>`, 'i');
		const match = re.exec(xml);
		if (!match) continue;

		const value = decodeEntities(match[1].trim());
		switch (field) {
			case 'pagecount':
				metadata.page_count = value;
				break;
			case 'righttoleft':
				metadata.rtl = value;
				break;
			default:
				metadata[field] = value;
				break;
		}
	}

	return metadata;
}

/** Convert a Blob to text, or null on failure. */
async function blobToText(blob: Blob): Promise<string | null> {
	try {
		const buffer = await blob.arrayBuffer();
		return new TextDecoder('utf-8').decode(buffer);
	} catch {
		return null;
	}
}

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

		// Parse embedded ComicInfo.xml when present.
		let metadata: ComicInfoMetadata | null = null;
		for (const [entryName, entry] of Object.entries(extracted)) {
			if (!COMIC_INFO_RE.test(entryName)) continue;
			const entryFile = entry.file || (entry as unknown as Blob);
			const blobEntry = entryFile instanceof Blob ? entryFile : new Blob([entryFile]);
			const xml = await blobToText(blobEntry);
			if (xml) {
				metadata = parseComicInfo(xml);
			}
			break;
		}

		self.postMessage({ type: 'success', pages, total: pages.length, metadata });

	} catch (err) {
		self.postMessage({
			type: 'error',
			message: err instanceof Error ? err.message : 'Unknown extraction error',
		});
	}
};

// Signal ready (only in a real worker context — jsdom/main-thread imports
// of this module for its pure helpers must not post messages).
const workerScope = self as unknown as { postMessage?: (msg: unknown) => void };
if (typeof window === 'undefined' && typeof workerScope.postMessage === 'function') {
	workerScope.postMessage({ type: 'ready' });
}
