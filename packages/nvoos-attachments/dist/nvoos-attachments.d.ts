/**
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
