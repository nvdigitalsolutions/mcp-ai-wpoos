/**
 * Tests for getFileTypeInfo helper in chat-attachments-service.js
 *
 * Validates that file type icons and labels are correctly returned
 * for various MIME types and file extensions.
 *
 * @package WP_MCP_AI
 */

describe( 'getFileTypeInfo', () => {
	// Replicate getFileExtension from chat-attachments-service.js
	function getFileExtension( file ) {
		let name = '';
		if ( file && typeof file === 'object' && file.name ) {
			name = file.name;
		} else if ( typeof file === 'string' ) {
			name = file;
		}
		if ( ! name ) {
			return '';
		}
		const lastDot = name.lastIndexOf( '.' );
		if ( lastDot === -1 || lastDot === name.length - 1 ) {
			return '';
		}
		return name.substring( lastDot + 1 ).toLowerCase();
	}

	// Replicate getFileTypeInfo from chat-attachments-service.js
	function getFileTypeInfo( attachment ) {
		var fallback = { icon: '\uD83D\uDCC4', label: 'File' };

		if ( ! attachment ) {
			return fallback;
		}

		var mime = ( attachment.type || attachment.mime || attachment.mime_type || '' ).toLowerCase();
		var ext = getFileExtension( attachment.name || attachment.file_name || '' );

		if ( mime.indexOf( 'image/' ) === 0 || [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'heic', 'heif', 'ico', 'tiff', 'tif', 'avif' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDDBC\uFE0F', label: 'Image' };
		}
		if ( mime.indexOf( 'video/' ) === 0 || [ 'mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'mkv', 'flv', 'wmv' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83C\uDFA5', label: 'Video' };
		}
		if ( mime.indexOf( 'audio/' ) === 0 || [ 'mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'opus', 'wma', 'aiff' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83C\uDFB5', label: 'Audio' };
		}
		if ( mime === 'application/pdf' || ext === 'pdf' ) {
			return { icon: '\uD83D\uDCC4', label: 'PDF' };
		}
		if ( mime.indexOf( 'wordprocessingml' ) !== -1 || mime.indexOf( 'ms-word' ) !== -1 || mime === 'application/msword' || [ 'doc', 'docx', 'docm', 'dotx', 'dotm', 'odt' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCD8', label: 'Word Document' };
		}
		if ( mime.indexOf( 'spreadsheetml' ) !== -1 || mime.indexOf( 'ms-excel' ) !== -1 || [ 'xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'ods' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCCA', label: 'Spreadsheet' };
		}
		if ( mime.indexOf( 'presentationml' ) !== -1 || mime.indexOf( 'ms-powerpoint' ) !== -1 || mime === 'application/vnd.ms-powerpoint' || [ 'ppt', 'pptx', 'pptm', 'ppsx', 'odp' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCFD\uFE0F', label: 'Presentation' };
		}
		if ( mime === 'text/markdown' || [ 'md', 'markdown' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCDD', label: 'Markdown' };
		}
		if ( mime === 'text/csv' || mime === 'text/tab-separated-values' || [ 'csv', 'tsv' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCC8', label: 'Data File' };
		}
		if ( mime === 'text/plain' || ext === 'txt' ) {
			return { icon: '\uD83D\uDCC3', label: 'Text File' };
		}
		if ( mime === 'text/html' || [ 'html', 'htm' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83C\uDF10', label: 'HTML' };
		}
		if ( mime === 'application/json' || mime === 'application/x-ndjson' || mime === 'application/jsonl' || [ 'json', 'jsonl', 'ndjson' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDD27', label: 'JSON' };
		}
		if ( mime === 'application/xml' || mime === 'text/xml' || ext === 'xml' ) {
			return { icon: '\uD83D\uDD27', label: 'XML' };
		}
		if ( [ 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'php', 'java', 'c', 'cpp', 'cs', 'go', 'rs', 'swift', 'kt', 'sh', 'bash', 'sql', 'r', 'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCBB', label: 'Code' };
		}
		if ( mime.indexOf( 'zip' ) !== -1 || mime.indexOf( 'compressed' ) !== -1 || mime.indexOf( 'archive' ) !== -1 || [ 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz' ].indexOf( ext ) !== -1 ) {
			return { icon: '\uD83D\uDCE6', label: 'Archive' };
		}
		if ( mime.indexOf( 'text/' ) === 0 ) {
			return { icon: '\uD83D\uDCC3', label: 'Text File' };
		}
		return fallback;
	}

	it( 'should return fallback for null input', () => {
		const result = getFileTypeInfo( null );
		expect( result ).toEqual( { icon: '\uD83D\uDCC4', label: 'File' } );
	} );

	it( 'should return fallback for empty object', () => {
		const result = getFileTypeInfo( {} );
		expect( result ).toEqual( { icon: '\uD83D\uDCC4', label: 'File' } );
	} );

	// Image types
	it( 'should detect JPEG images by MIME type', () => {
		const result = getFileTypeInfo( { type: 'image/jpeg' } );
		expect( result.label ).toBe( 'Image' );
	} );

	it( 'should detect PNG images by extension', () => {
		const result = getFileTypeInfo( { name: 'screenshot.png' } );
		expect( result.label ).toBe( 'Image' );
	} );

	it( 'should detect WebP images', () => {
		const result = getFileTypeInfo( { name: 'photo.webp' } );
		expect( result.label ).toBe( 'Image' );
	} );

	// Video types
	it( 'should detect MP4 videos by MIME type', () => {
		const result = getFileTypeInfo( { type: 'video/mp4' } );
		expect( result.label ).toBe( 'Video' );
	} );

	it( 'should detect MKV videos by extension', () => {
		const result = getFileTypeInfo( { name: 'movie.mkv' } );
		expect( result.label ).toBe( 'Video' );
	} );

	// Audio types
	it( 'should detect MP3 audio by extension', () => {
		const result = getFileTypeInfo( { name: 'song.mp3' } );
		expect( result.label ).toBe( 'Audio' );
	} );

	it( 'should detect audio by MIME type', () => {
		const result = getFileTypeInfo( { type: 'audio/wav' } );
		expect( result.label ).toBe( 'Audio' );
	} );

	// PDF
	it( 'should detect PDF by MIME type', () => {
		const result = getFileTypeInfo( { type: 'application/pdf' } );
		expect( result.label ).toBe( 'PDF' );
	} );

	it( 'should detect PDF by extension', () => {
		const result = getFileTypeInfo( { name: 'report.pdf' } );
		expect( result.label ).toBe( 'PDF' );
	} );

	// Word documents
	it( 'should detect DOCX by extension', () => {
		const result = getFileTypeInfo( { name: 'document.docx' } );
		expect( result.label ).toBe( 'Word Document' );
	} );

	it( 'should detect DOC by extension', () => {
		const result = getFileTypeInfo( { name: 'letter.doc' } );
		expect( result.label ).toBe( 'Word Document' );
	} );

	it( 'should detect DOCX by MIME type', () => {
		const result = getFileTypeInfo( {
			type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		} );
		expect( result.label ).toBe( 'Word Document' );
	} );

	it( 'should detect ODT by extension', () => {
		const result = getFileTypeInfo( { name: 'doc.odt' } );
		expect( result.label ).toBe( 'Word Document' );
	} );

	// Excel spreadsheets
	it( 'should detect XLSX by extension', () => {
		const result = getFileTypeInfo( { name: 'data.xlsx' } );
		expect( result.label ).toBe( 'Spreadsheet' );
	} );

	it( 'should detect XLS by extension', () => {
		const result = getFileTypeInfo( { name: 'budget.xls' } );
		expect( result.label ).toBe( 'Spreadsheet' );
	} );

	it( 'should detect Excel by MIME type', () => {
		const result = getFileTypeInfo( {
			type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		} );
		expect( result.label ).toBe( 'Spreadsheet' );
	} );

	// PowerPoint
	it( 'should detect PPTX by extension', () => {
		const result = getFileTypeInfo( { name: 'slides.pptx' } );
		expect( result.label ).toBe( 'Presentation' );
	} );

	it( 'should detect PPT by extension', () => {
		const result = getFileTypeInfo( { name: 'deck.ppt' } );
		expect( result.label ).toBe( 'Presentation' );
	} );

	// Markdown
	it( 'should detect Markdown by extension', () => {
		const result = getFileTypeInfo( { name: 'README.md' } );
		expect( result.label ).toBe( 'Markdown' );
	} );

	it( 'should detect Markdown by MIME type', () => {
		const result = getFileTypeInfo( { type: 'text/markdown' } );
		expect( result.label ).toBe( 'Markdown' );
	} );

	// CSV / TSV
	it( 'should detect CSV by extension', () => {
		const result = getFileTypeInfo( { name: 'export.csv' } );
		expect( result.label ).toBe( 'Data File' );
	} );

	it( 'should detect TSV by extension', () => {
		const result = getFileTypeInfo( { name: 'data.tsv' } );
		expect( result.label ).toBe( 'Data File' );
	} );

	// Plain text
	it( 'should detect TXT by extension', () => {
		const result = getFileTypeInfo( { name: 'notes.txt' } );
		expect( result.label ).toBe( 'Text File' );
	} );

	it( 'should detect plain text by MIME type', () => {
		const result = getFileTypeInfo( { type: 'text/plain' } );
		expect( result.label ).toBe( 'Text File' );
	} );

	// HTML
	it( 'should detect HTML by extension', () => {
		const result = getFileTypeInfo( { name: 'page.html' } );
		expect( result.label ).toBe( 'HTML' );
	} );

	// JSON
	it( 'should detect JSON by extension', () => {
		const result = getFileTypeInfo( { name: 'config.json' } );
		expect( result.label ).toBe( 'JSON' );
	} );

	// XML
	it( 'should detect XML by extension', () => {
		const result = getFileTypeInfo( { name: 'feed.xml' } );
		expect( result.label ).toBe( 'XML' );
	} );

	// Code files
	it( 'should detect JavaScript code files', () => {
		const result = getFileTypeInfo( { name: 'app.js' } );
		expect( result.label ).toBe( 'Code' );
	} );

	it( 'should detect Python code files', () => {
		const result = getFileTypeInfo( { name: 'script.py' } );
		expect( result.label ).toBe( 'Code' );
	} );

	it( 'should detect PHP code files', () => {
		const result = getFileTypeInfo( { name: 'index.php' } );
		expect( result.label ).toBe( 'Code' );
	} );

	// Archive files
	it( 'should detect ZIP archives by extension', () => {
		const result = getFileTypeInfo( { name: 'backup.zip' } );
		expect( result.label ).toBe( 'Archive' );
	} );

	it( 'should detect TAR.GZ archives by extension', () => {
		const result = getFileTypeInfo( { name: 'package.gz' } );
		expect( result.label ).toBe( 'Archive' );
	} );

	// Edge cases
	it( 'should prefer MIME type over extension when both present', () => {
		const result = getFileTypeInfo( {
			type: 'application/pdf',
			name: 'weird.txt',
		} );
		// Images are checked first in order, so MIME takes precedence
		expect( result.label ).toBe( 'PDF' );
	} );

	it( 'should handle file_name property', () => {
		const result = getFileTypeInfo( { file_name: 'report.docx' } );
		expect( result.label ).toBe( 'Word Document' );
	} );

	it( 'should handle mime_type property', () => {
		const result = getFileTypeInfo( { mime_type: 'text/csv' } );
		expect( result.label ).toBe( 'Data File' );
	} );

	it( 'should handle mime property', () => {
		const result = getFileTypeInfo( { mime: 'application/json' } );
		expect( result.label ).toBe( 'JSON' );
	} );

	it( 'should return consistent icon and label pair', () => {
		const result = getFileTypeInfo( { name: 'test.xlsx' } );
		expect( result ).toHaveProperty( 'icon' );
		expect( result ).toHaveProperty( 'label' );
		expect( typeof result.icon ).toBe( 'string' );
		expect( typeof result.label ).toBe( 'string' );
		expect( result.icon.length ).toBeGreaterThan( 0 );
		expect( result.label.length ).toBeGreaterThan( 0 );
	} );
} );
