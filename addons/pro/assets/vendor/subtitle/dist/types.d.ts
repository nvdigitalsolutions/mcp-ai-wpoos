export interface Timestamp {
    start: number;
    end: number;
    settings?: string;
}
export interface Caption extends Timestamp {
    text: string;
}
export declare type Captions = Caption[];
export interface FormatOptions {
    format: 'srt' | 'vtt';
}
