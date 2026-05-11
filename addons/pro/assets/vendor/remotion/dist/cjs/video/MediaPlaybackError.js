"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.MediaPlaybackError = void 0;
class MediaPlaybackError extends Error {
    constructor({ message, src }) {
        super(message);
        this.name = 'MediaPlaybackError';
        this.src = src;
    }
}
exports.MediaPlaybackError = MediaPlaybackError;
