export declare class MediaPlaybackError extends Error {
    readonly src: string;
    constructor({ message, src }: {
        message: string;
        src: string;
    });
}
