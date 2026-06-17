"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getTimelineDuration = void 0;
const calculate_media_duration_js_1 = require("./calculate-media-duration.js");
const getTimelineDuration = ({ compositionDurationInFrames, playbackRate, trimBefore, trimAfter, parentSequenceDurationInFrames, loop, }) => {
    if (loop) {
        return compositionDurationInFrames;
    }
    const mediaDuration = (0, calculate_media_duration_js_1.calculateMediaDuration)({
        mediaDurationInFrames: compositionDurationInFrames * playbackRate + (trimBefore !== null && trimBefore !== void 0 ? trimBefore : 0),
        playbackRate,
        trimBefore,
        trimAfter,
    });
    if (parentSequenceDurationInFrames !== null) {
        return Math.floor(Math.min(parentSequenceDurationInFrames * playbackRate, mediaDuration));
    }
    return mediaDuration;
};
exports.getTimelineDuration = getTimelineDuration;
