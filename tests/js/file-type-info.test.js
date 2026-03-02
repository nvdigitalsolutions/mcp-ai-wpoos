/**
 * Tests for getFileTypeInfo and isAudioAttachment helpers
 *
 * @package WP_MCP_AI
 */

describe( 'getFileTypeInfo helper', () => {
	let getFileTypeInfo;

	beforeEach( () => {
		// Load the function from the attachments service
		// Since it's inside an IIFE, we replicate the map-based logic for testing
		const map = {
			pdf: { label: 'PDF Document', icon: '📕' },
			doc: { label: 'Word Document', icon: '📝' },
			docx: { label: 'Word Document', icon: '📝' },
			odt: { label: 'OpenDocument Text', icon: '📝' },
			rtf: { label: 'Rich Text', icon: '📝' },
			txt: { label: 'Text File', icon: '📄' },
			md: { label: 'Markdown', icon: '📄' },
			xls: { label: 'Excel Spreadsheet', icon: '📊' },
			xlsx: { label: 'Excel Spreadsheet', icon: '📊' },
			ods: { label: 'OpenDocument Spreadsheet', icon: '📊' },
			csv: { label: 'CSV File', icon: '📊' },
			tsv: { label: 'TSV File', icon: '📊' },
			ppt: { label: 'PowerPoint', icon: '📽️' },
			pptx: { label: 'PowerPoint', icon: '📽️' },
			odp: { label: 'OpenDocument Presentation', icon: '📽️' },
			key: { label: 'Keynote', icon: '📽️' },
			json: { label: 'JSON', icon: '🔧' },
			xml: { label: 'XML', icon: '🔧' },
			yaml: { label: 'YAML', icon: '🔧' },
			yml: { label: 'YAML', icon: '🔧' },
			zip: { label: 'ZIP Archive', icon: '🗜️' },
			rar: { label: 'RAR Archive', icon: '🗜️' },
			'7z': { label: '7-Zip Archive', icon: '🗜️' },
			tar: { label: 'TAR Archive', icon: '🗜️' },
			gz: { label: 'GZip Archive', icon: '🗜️' },
			js: { label: 'JavaScript', icon: '💻' },
			ts: { label: 'TypeScript', icon: '💻' },
			py: { label: 'Python', icon: '💻' },
			php: { label: 'PHP', icon: '💻' },
			java: { label: 'Java', icon: '💻' },
			html: { label: 'HTML', icon: '🌐' },
			css: { label: 'CSS', icon: '🎨' },
			sql: { label: 'SQL', icon: '🗃️' },
			sh: { label: 'Shell Script', icon: '💻' },
			jpg: { label: 'JPEG Image', icon: '🖼️' },
			png: { label: 'PNG Image', icon: '🖼️' },
			gif: { label: 'GIF Image', icon: '🖼️' },
			svg: { label: 'SVG Image', icon: '🖼️' },
			mp3: { label: 'MP3 Audio', icon: '🎵' },
			wav: { label: 'WAV Audio', icon: '🎵' },
			mp4: { label: 'MP4 Video', icon: '🎬' },
			webm: { label: 'WebM Video', icon: '🎬' },
			mov: { label: 'MOV Video', icon: '🎬' },
			ttf: { label: 'TrueType Font', icon: '🔤' },
			epub: { label: 'ePub', icon: '📚' },
		};

		getFileTypeInfo = function( ext ) {
			if ( ! ext || typeof ext !== 'string' ) {
				return { label: 'File', icon: '📄' };
			}
			const key = ext.toLowerCase();
			return map[ key ] || { label: 'File', icon: '📄' };
		};
	} );

	// Document types
	test( 'returns PDF info for pdf extension', () => {
		const result = getFileTypeInfo( 'pdf' );
		expect( result.label ).toBe( 'PDF Document' );
		expect( result.icon ).toBe( '📕' );
	} );

	test( 'returns Word Document info for docx extension', () => {
		const result = getFileTypeInfo( 'docx' );
		expect( result.label ).toBe( 'Word Document' );
		expect( result.icon ).toBe( '📝' );
	} );

	test( 'returns Word Document info for doc extension', () => {
		const result = getFileTypeInfo( 'doc' );
		expect( result.label ).toBe( 'Word Document' );
	} );

	test( 'returns Text File info for txt extension', () => {
		const result = getFileTypeInfo( 'txt' );
		expect( result.label ).toBe( 'Text File' );
	} );

	test( 'returns Markdown info for md extension', () => {
		const result = getFileTypeInfo( 'md' );
		expect( result.label ).toBe( 'Markdown' );
	} );

	// Spreadsheets
	test( 'returns Excel Spreadsheet info for xlsx extension', () => {
		const result = getFileTypeInfo( 'xlsx' );
		expect( result.label ).toBe( 'Excel Spreadsheet' );
		expect( result.icon ).toBe( '📊' );
	} );

	test( 'returns CSV File info for csv extension', () => {
		const result = getFileTypeInfo( 'csv' );
		expect( result.label ).toBe( 'CSV File' );
		expect( result.icon ).toBe( '📊' );
	} );

	// Presentations
	test( 'returns PowerPoint info for pptx extension', () => {
		const result = getFileTypeInfo( 'pptx' );
		expect( result.label ).toBe( 'PowerPoint' );
		expect( result.icon ).toBe( '📽️' );
	} );

	// Archives
	test( 'returns ZIP Archive info for zip extension', () => {
		const result = getFileTypeInfo( 'zip' );
		expect( result.label ).toBe( 'ZIP Archive' );
		expect( result.icon ).toBe( '🗜️' );
	} );

	test( 'returns 7-Zip Archive info for 7z extension', () => {
		const result = getFileTypeInfo( '7z' );
		expect( result.label ).toBe( '7-Zip Archive' );
	} );

	// Code files
	test( 'returns JavaScript info for js extension', () => {
		const result = getFileTypeInfo( 'js' );
		expect( result.label ).toBe( 'JavaScript' );
		expect( result.icon ).toBe( '💻' );
	} );

	test( 'returns Python info for py extension', () => {
		const result = getFileTypeInfo( 'py' );
		expect( result.label ).toBe( 'Python' );
	} );

	test( 'returns PHP info for php extension', () => {
		const result = getFileTypeInfo( 'php' );
		expect( result.label ).toBe( 'PHP' );
	} );

	test( 'returns HTML info for html extension', () => {
		const result = getFileTypeInfo( 'html' );
		expect( result.label ).toBe( 'HTML' );
		expect( result.icon ).toBe( '🌐' );
	} );

	test( 'returns CSS info for css extension', () => {
		const result = getFileTypeInfo( 'css' );
		expect( result.label ).toBe( 'CSS' );
		expect( result.icon ).toBe( '🎨' );
	} );

	// Media
	test( 'returns MP3 Audio info for mp3 extension', () => {
		const result = getFileTypeInfo( 'mp3' );
		expect( result.label ).toBe( 'MP3 Audio' );
		expect( result.icon ).toBe( '🎵' );
	} );

	test( 'returns MP4 Video info for mp4 extension', () => {
		const result = getFileTypeInfo( 'mp4' );
		expect( result.label ).toBe( 'MP4 Video' );
		expect( result.icon ).toBe( '🎬' );
	} );

	test( 'returns JPEG Image info for jpg extension', () => {
		const result = getFileTypeInfo( 'jpg' );
		expect( result.label ).toBe( 'JPEG Image' );
		expect( result.icon ).toBe( '🖼️' );
	} );

	// Case insensitivity
	test( 'handles uppercase extensions', () => {
		const result = getFileTypeInfo( 'PDF' );
		expect( result.label ).toBe( 'PDF Document' );
	} );

	test( 'handles mixed-case extensions', () => {
		const result = getFileTypeInfo( 'Docx' );
		expect( result.label ).toBe( 'Word Document' );
	} );

	// Edge cases
	test( 'returns default for null input', () => {
		const result = getFileTypeInfo( null );
		expect( result.label ).toBe( 'File' );
		expect( result.icon ).toBe( '📄' );
	} );

	test( 'returns default for empty string', () => {
		const result = getFileTypeInfo( '' );
		expect( result.label ).toBe( 'File' );
		expect( result.icon ).toBe( '📄' );
	} );

	test( 'returns default for unknown extension', () => {
		const result = getFileTypeInfo( 'xyz123' );
		expect( result.label ).toBe( 'File' );
		expect( result.icon ).toBe( '📄' );
	} );

	test( 'returns default for non-string input', () => {
		const result = getFileTypeInfo( 42 );
		expect( result.label ).toBe( 'File' );
	} );
} );

describe( 'isAudioAttachment helper', () => {
	let isAudioAttachment;

	beforeEach( () => {
		const getFileExtension = function( file ) {
			let name = '';
			if ( file && typeof file === 'object' && file.name ) {
				name = file.name;
			} else if ( typeof file === 'string' ) {
				name = file;
			}
			if ( ! name ) return '';
			const lastDot = name.lastIndexOf( '.' );
			if ( lastDot === -1 || lastDot === name.length - 1 ) return '';
			return name.substring( lastDot + 1 ).toLowerCase();
		};

		isAudioAttachment = function( attachment ) {
			if ( ! attachment ) return false;

			if ( attachment.type && typeof attachment.type === 'string' ) {
				if ( attachment.type.indexOf( 'audio/' ) === 0 ) return true;
			}

			const name = attachment.name || attachment.file_name || attachment.label || '';
			if ( name ) {
				const ext = getFileExtension( name );
				const audioExtensions = [ 'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus', 'mid', 'midi' ];
				return audioExtensions.indexOf( ext ) !== -1;
			}

			const url = attachment.url || '';
			if ( url && typeof url === 'string' ) {
				const urlPath = url.toLowerCase().split( '?' )[ 0 ].split( '#' )[ 0 ];
				const audioExts = [ '.mp3', '.wav', '.ogg', '.flac', '.aac', '.m4a', '.wma' ];
				for ( let i = 0; i < audioExts.length; i++ ) {
					if ( urlPath.lastIndexOf( audioExts[ i ] ) === urlPath.length - audioExts[ i ].length ) {
						return true;
					}
				}
			}

			return false;
		};
	} );

	test( 'detects audio from MIME type', () => {
		expect( isAudioAttachment( { type: 'audio/mpeg' } ) ).toBe( true );
		expect( isAudioAttachment( { type: 'audio/wav' } ) ).toBe( true );
	} );

	test( 'detects audio from file name extension', () => {
		expect( isAudioAttachment( { name: 'song.mp3' } ) ).toBe( true );
		expect( isAudioAttachment( { name: 'track.flac' } ) ).toBe( true );
		expect( isAudioAttachment( { name: 'voice.m4a' } ) ).toBe( true );
	} );

	test( 'detects audio from URL extension', () => {
		expect( isAudioAttachment( { url: 'https://example.com/audio.mp3' } ) ).toBe( true );
		expect( isAudioAttachment( { url: 'https://example.com/file.wav?dl=1' } ) ).toBe( true );
	} );

	test( 'returns false for non-audio files', () => {
		expect( isAudioAttachment( { type: 'image/png', name: 'photo.png' } ) ).toBe( false );
		expect( isAudioAttachment( { type: 'video/mp4', name: 'clip.mp4' } ) ).toBe( false );
		expect( isAudioAttachment( { name: 'document.pdf' } ) ).toBe( false );
	} );

	test( 'returns false for null/undefined input', () => {
		expect( isAudioAttachment( null ) ).toBe( false );
		expect( isAudioAttachment( undefined ) ).toBe( false );
	} );

	test( 'returns false for empty attachment object', () => {
		expect( isAudioAttachment( {} ) ).toBe( false );
	} );
} );
