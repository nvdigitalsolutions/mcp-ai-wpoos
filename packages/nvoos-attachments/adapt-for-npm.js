// Adaptation script: Convert WordPress chat-attachments-service.js to a standalone NPM package.
// Source is a clean utility object — only the IIFE wrapper and the global export need stripping.

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-attachments-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'chat-attachments-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Strip IIFE opener.
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');

// Step 2: Drop the global export + IIFE close.
code = code.replace(
	/\s*\/\/ Expose service globally\s*\n\s*window\.wpMcpAiChatAttachments = wpMcpAiChatAttachments;\s*\n\s*\}\)\(\);\s*$/,
	''
);

// Step 3: Rename the variable so the public surface doesn't carry the WP prefix.
console.log('   → Renaming wpMcpAiChatAttachments → AttachmentsService');
code = code.replace(/const wpMcpAiChatAttachments =/, 'const AttachmentsService =');

// Step 4: ES module exports. Re-export every method individually so consumers can
// tree-shake / cherry-pick, plus a default export of the whole service.
const exportBlock = `

// ES Module exports
export const getFileExtension          = AttachmentsService.getFileExtension.bind(AttachmentsService);
export const isFileTypeAllowed         = AttachmentsService.isFileTypeAllowed.bind(AttachmentsService);
export const isRealAttachmentUrl       = AttachmentsService.isRealAttachmentUrl.bind(AttachmentsService);
export const getFileTypeInfo           = AttachmentsService.getFileTypeInfo.bind(AttachmentsService);
export const isVideoAttachment         = AttachmentsService.isVideoAttachment.bind(AttachmentsService);
export const isAudioAttachment         = AttachmentsService.isAudioAttachment.bind(AttachmentsService);
export const normaliseUploadResponse   = AttachmentsService.normaliseUploadResponse.bind(AttachmentsService);
export const normaliseAttachmentRecord = AttachmentsService.normaliseAttachmentRecord.bind(AttachmentsService);
export const buildAttachmentMeta       = AttachmentsService.buildAttachmentMeta.bind(AttachmentsService);
export const buildDisplayAttachment    = AttachmentsService.buildDisplayAttachment.bind(AttachmentsService);
export const buildFileDownloadUrl      = AttachmentsService.buildFileDownloadUrl.bind(AttachmentsService);
export const getAttachmentUrlFromRecord= AttachmentsService.getAttachmentUrlFromRecord.bind(AttachmentsService);
export const stripSegmentDisplayData   = AttachmentsService.stripSegmentDisplayData.bind(AttachmentsService);
export const createSegmentFromAttachment = AttachmentsService.createSegmentFromAttachment.bind(AttachmentsService);
export const addAttachmentMetadataToSegment = AttachmentsService.addAttachmentMetadataToSegment.bind(AttachmentsService);
export const createContentDispositionHeader = AttachmentsService.createContentDispositionHeader.bind(AttachmentsService);

export { AttachmentsService };
export default AttachmentsService;
`;

const finalCode = code.trim() + exportBlock;

const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

fs.writeFileSync(path.join(distDir, 'nvoos-attachments.js'), finalCode);
console.log('   → Generated dist/nvoos-attachments.js');

const dtsContent = `/**
 * File attachment helpers for AI chat: type detection, validation, normalisation,
 * and segment builders.
 *
 * @package @nvdigitalsolutions/nvoos-attachments
 */

export interface FileTypeInfo {
	icon: string;
	label: string;
}

export interface AttachmentLike {
	type?: string;
	mime?: string;
	mime_type?: string;
	name?: string;
	file_name?: string;
	url?: string;
	size?: number;
	id?: string | number;
	fileId?: string | number;
	attachment_id?: string | number;
	[key: string]: any;
}

/** Caller-provided chat-state shape. Only the listed keys are read. */
export interface ChatStateLike {
	allowedFileTypes?: string[];
	config?: {
		filesEndpoint?: string;
		[key: string]: any;
	};
	[key: string]: any;
}

export declare function getFileExtension(file: File | string): string;
export declare function isFileTypeAllowed(file: File, state: ChatStateLike): boolean;
export declare function isRealAttachmentUrl(url: string): boolean;
export declare function getFileTypeInfo(attachment: AttachmentLike): FileTypeInfo;
export declare function isVideoAttachment(attachment: AttachmentLike): boolean;
export declare function isAudioAttachment(attachment: AttachmentLike): boolean;
export declare function normaliseUploadResponse(data: any, file: File): AttachmentLike | null;
export declare function normaliseAttachmentRecord(raw: any): AttachmentLike | null;
export declare function buildAttachmentMeta(record: AttachmentLike): Record<string, any>;
export declare function buildDisplayAttachment(attachment: AttachmentLike, state: ChatStateLike): AttachmentLike | null;
export declare function buildFileDownloadUrl(state: ChatStateLike, fileId: string | number): string;
export declare function getAttachmentUrlFromRecord(record: AttachmentLike, state: ChatStateLike): string;
export declare function stripSegmentDisplayData(segment: any): any;
export declare function createSegmentFromAttachment(attachment: AttachmentLike): any;
export declare function addAttachmentMetadataToSegment(segment: any, attachment: AttachmentLike): any;
export declare function createContentDispositionHeader(filename: string): string;

/** The full service object, with all methods bound to itself. */
export declare const AttachmentsService: {
	getFileExtension: typeof getFileExtension;
	isFileTypeAllowed: typeof isFileTypeAllowed;
	isRealAttachmentUrl: typeof isRealAttachmentUrl;
	getFileTypeInfo: typeof getFileTypeInfo;
	isVideoAttachment: typeof isVideoAttachment;
	isAudioAttachment: typeof isAudioAttachment;
	normaliseUploadResponse: typeof normaliseUploadResponse;
	normaliseAttachmentRecord: typeof normaliseAttachmentRecord;
	buildAttachmentMeta: typeof buildAttachmentMeta;
	buildDisplayAttachment: typeof buildDisplayAttachment;
	buildFileDownloadUrl: typeof buildFileDownloadUrl;
	getAttachmentUrlFromRecord: typeof getAttachmentUrlFromRecord;
	stripSegmentDisplayData: typeof stripSegmentDisplayData;
	createSegmentFromAttachment: typeof createSegmentFromAttachment;
	addAttachmentMetadataToSegment: typeof addAttachmentMetadataToSegment;
	createContentDispositionHeader: typeof createContentDispositionHeader;
};

export default AttachmentsService;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-attachments.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
