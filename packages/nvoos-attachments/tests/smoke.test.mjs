import svc, {
	getFileExtension, isFileTypeAllowed, isRealAttachmentUrl,
	getFileTypeInfo, isVideoAttachment, isAudioAttachment,
	buildFileDownloadUrl, createContentDispositionHeader,
	AttachmentsService,
} from '../dist/nvoos-attachments.js';

// getFileExtension
console.assert(getFileExtension('file.txt') === 'txt');
console.assert(getFileExtension('IMAGE.PNG') === 'png');
console.assert(getFileExtension({ name: 'doc.PDF' }) === 'pdf');
console.assert(getFileExtension('') === '');
console.assert(getFileExtension(null) === '');
console.assert(getFileExtension('noextension') === '');

// isFileTypeAllowed
console.assert(isFileTypeAllowed({ name: 'a.txt' }, { allowedFileTypes: ['txt', 'pdf'] }) === true);
console.assert(isFileTypeAllowed({ name: 'a.exe' }, { allowedFileTypes: ['txt', 'pdf'] }) === false);
console.assert(isFileTypeAllowed({ name: 'a.txt' }, { allowedFileTypes: [] }) === true, 'empty list = allow');
console.assert(isFileTypeAllowed(null, {}) === false);

// isRealAttachmentUrl
console.assert(isRealAttachmentUrl('https://example.com/x') === true);
console.assert(isRealAttachmentUrl('http://example.com/x') === true);
console.assert(isRealAttachmentUrl('blob:https://example.com/abc') === false);
console.assert(isRealAttachmentUrl('data:text/plain,hi') === false);
console.assert(isRealAttachmentUrl('javascript:alert(1)') === false);
console.assert(isRealAttachmentUrl('') === false);

// getFileTypeInfo
const img = getFileTypeInfo({ type: 'image/png', name: 'a.png' });
console.assert(img.label === 'Image');
const pdf = getFileTypeInfo({ name: 'doc.pdf' });
console.assert(pdf.label === 'PDF');
const code = getFileTypeInfo({ name: 'app.js' });
console.assert(code.label === 'Code');
const fallback = getFileTypeInfo({ name: 'mystery.xyz' });
console.assert(fallback.label === 'File');

// isVideoAttachment / isAudioAttachment
console.assert(isVideoAttachment({ type: 'video/mp4' }) === true);
console.assert(isVideoAttachment({ name: 'movie.webm' }) === true);
console.assert(isVideoAttachment({ name: 'song.mp3' }) === false);
console.assert(isAudioAttachment({ type: 'audio/mp3' }) === true);
console.assert(isAudioAttachment({ name: 'a.flac' }) === true);

// buildFileDownloadUrl
const url = buildFileDownloadUrl({ config: { filesEndpoint: '/api/files' } }, 42);
console.assert(url === '/api/files?file_id=42', 'url=' + url);
const url2 = buildFileDownloadUrl({ config: { filesEndpoint: '/api/files?ver=1' } }, 'abc def');
console.assert(url2 === '/api/files?ver=1&file_id=abc%20def', 'url2=' + url2);
console.assert(buildFileDownloadUrl({}, 99) === '');
console.assert(buildFileDownloadUrl({ config: { filesEndpoint: '/x' } }, null) === '');

// createContentDispositionHeader sanitises
const cd = createContentDispositionHeader('file with "quotes" & unicode 中.txt');
console.assert(cd.startsWith('attachment; filename="'), 'cd=' + cd);
console.assert(!cd.includes('"quotes"'), 'should strip quotes: ' + cd);

// Default export shape
console.assert(typeof svc.getFileExtension === 'function');
console.assert(svc === AttachmentsService);

console.log('All smoke tests passed.');
