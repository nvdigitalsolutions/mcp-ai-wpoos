"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.useMediaInTimeline = exports.useImageInTimeline = exports.useBasicMediaInTimeline = void 0;
const react_1 = require("react");
const use_audio_frame_js_1 = require("./audio/use-audio-frame.js");
const get_asset_file_name_js_1 = require("./get-asset-file-name.js");
const get_timeline_duration_js_1 = require("./get-timeline-duration.js");
const nonce_js_1 = require("./nonce.js");
const SequenceContext_js_1 = require("./SequenceContext.js");
const SequenceManager_js_1 = require("./SequenceManager.js");
const timeline_position_state_js_1 = require("./timeline-position-state.js");
const use_remotion_environment_js_1 = require("./use-remotion-environment.js");
const use_video_config_js_1 = require("./use-video-config.js");
const volume_prop_js_1 = require("./volume-prop.js");
const didWarn = {};
const warnOnce = (message) => {
    if (didWarn[message]) {
        return;
    }
    // eslint-disable-next-line no-console
    console.warn(message);
    didWarn[message] = true;
};
const useBasicMediaInTimeline = ({ volume, mediaVolume, mediaType, src, displayName, trimBefore, trimAfter, playbackRate, sequenceDurationInFrames, mediaStartsAt, loop, }) => {
    var _a;
    if (!src) {
        throw new Error('No src passed');
    }
    const parentSequence = (0, react_1.useContext)(SequenceContext_js_1.SequenceContext);
    const [initialVolume] = (0, react_1.useState)(() => volume);
    const duration = (0, get_timeline_duration_js_1.getTimelineDuration)({
        compositionDurationInFrames: sequenceDurationInFrames,
        playbackRate,
        trimBefore,
        trimAfter,
        parentSequenceDurationInFrames: (_a = parentSequence === null || parentSequence === void 0 ? void 0 : parentSequence.durationInFrames) !== null && _a !== void 0 ? _a : null,
        loop,
    });
    const volumes = (0, react_1.useMemo)(() => {
        if (typeof volume === 'number') {
            return volume;
        }
        return new Array(Math.floor(Math.max(0, duration + mediaStartsAt)))
            .fill(true)
            .map((_, i) => {
            return (0, volume_prop_js_1.evaluateVolume)({
                frame: i + mediaStartsAt,
                volume,
                mediaVolume,
            });
        })
            .join(',');
    }, [duration, mediaStartsAt, volume, mediaVolume]);
    (0, react_1.useEffect)(() => {
        if (typeof volume === 'number' && volume !== initialVolume) {
            warnOnce(`Remotion: The ${mediaType} with src ${src} has changed it's volume. Prefer the callback syntax for setting volume to get better timeline display: https://www.remotion.dev/docs/audio/volume`);
        }
    }, [initialVolume, mediaType, src, volume]);
    const doesVolumeChange = typeof volume === 'function';
    const nonce = (0, nonce_js_1.useNonce)();
    const { rootId } = (0, timeline_position_state_js_1.useTimelineContext)();
    const startMediaFrom = 0 - mediaStartsAt + (trimBefore !== null && trimBefore !== void 0 ? trimBefore : 0);
    const memoizedResult = (0, react_1.useMemo)(() => {
        return {
            volumes,
            duration,
            doesVolumeChange,
            nonce,
            rootId,
            finalDisplayName: displayName !== null && displayName !== void 0 ? displayName : (0, get_asset_file_name_js_1.getAssetDisplayName)(src),
            startMediaFrom,
            src,
            playbackRate,
        };
    }, [
        volumes,
        duration,
        doesVolumeChange,
        nonce,
        rootId,
        displayName,
        src,
        startMediaFrom,
        playbackRate,
    ]);
    return memoizedResult;
};
exports.useBasicMediaInTimeline = useBasicMediaInTimeline;
const useImageInTimeline = ({ src, displayName, id, stack, showInTimeline, premountDisplay, postmountDisplay, loopDisplay, controls, }) => {
    const parentSequence = (0, react_1.useContext)(SequenceContext_js_1.SequenceContext);
    const { registerSequence, unregisterSequence } = (0, react_1.useContext)(SequenceManager_js_1.SequenceManager);
    const { durationInFrames } = (0, use_video_config_js_1.useVideoConfig)();
    const mediaStartsAt = (0, use_audio_frame_js_1.useMediaStartsAt)();
    const { duration, nonce, rootId, finalDisplayName } = (0, exports.useBasicMediaInTimeline)({
        volume: undefined,
        mediaVolume: 0,
        mediaType: 'image',
        src,
        displayName,
        trimAfter: undefined,
        trimBefore: undefined,
        playbackRate: 1,
        sequenceDurationInFrames: durationInFrames,
        mediaStartsAt,
        loop: false,
    });
    const { isStudio } = (0, use_remotion_environment_js_1.useRemotionEnvironment)();
    (0, react_1.useEffect)(() => {
        var _a, _b, _c;
        if (!src) {
            throw new Error('No src passed');
        }
        if (!isStudio && ((_b = (_a = window.process) === null || _a === void 0 ? void 0 : _a.env) === null || _b === void 0 ? void 0 : _b.NODE_ENV) !== 'test') {
            return;
        }
        if (!showInTimeline) {
            return;
        }
        registerSequence({
            type: 'image',
            src,
            id,
            duration,
            from: 0,
            parent: (_c = parentSequence === null || parentSequence === void 0 ? void 0 : parentSequence.id) !== null && _c !== void 0 ? _c : null,
            displayName: finalDisplayName,
            rootId,
            showInTimeline: true,
            nonce: nonce.get(),
            loopDisplay,
            stack,
            premountDisplay,
            postmountDisplay,
            controls,
            effects: [],
        });
        return () => {
            unregisterSequence(id);
        };
    }, [
        duration,
        id,
        parentSequence,
        src,
        registerSequence,
        unregisterSequence,
        nonce,
        stack,
        showInTimeline,
        premountDisplay,
        postmountDisplay,
        isStudio,
        loopDisplay,
        rootId,
        finalDisplayName,
        controls,
    ]);
};
exports.useImageInTimeline = useImageInTimeline;
const useMediaInTimeline = ({ volume, mediaVolume, src, mediaType, playbackRate, displayName, id, stack, showInTimeline, premountDisplay, postmountDisplay, loopDisplay, }) => {
    const parentSequence = (0, react_1.useContext)(SequenceContext_js_1.SequenceContext);
    const startsAt = (0, use_audio_frame_js_1.useMediaStartsAt)();
    const { registerSequence, unregisterSequence } = (0, react_1.useContext)(SequenceManager_js_1.SequenceManager);
    const { durationInFrames } = (0, use_video_config_js_1.useVideoConfig)();
    const mediaStartsAt = (0, use_audio_frame_js_1.useMediaStartsAt)();
    const { volumes, duration, doesVolumeChange, nonce, rootId, finalDisplayName } = (0, exports.useBasicMediaInTimeline)({
        volume,
        mediaVolume,
        mediaType,
        src,
        displayName,
        trimAfter: undefined,
        trimBefore: undefined,
        playbackRate,
        sequenceDurationInFrames: durationInFrames,
        mediaStartsAt,
        loop: false,
    });
    const { isStudio } = (0, use_remotion_environment_js_1.useRemotionEnvironment)();
    (0, react_1.useEffect)(() => {
        var _a, _b, _c;
        if (!src) {
            throw new Error('No src passed');
        }
        if (!isStudio && ((_b = (_a = window.process) === null || _a === void 0 ? void 0 : _a.env) === null || _b === void 0 ? void 0 : _b.NODE_ENV) !== 'test') {
            return;
        }
        if (!showInTimeline) {
            return;
        }
        registerSequence({
            type: mediaType,
            src,
            id,
            duration,
            from: 0,
            parent: (_c = parentSequence === null || parentSequence === void 0 ? void 0 : parentSequence.id) !== null && _c !== void 0 ? _c : null,
            displayName: finalDisplayName,
            rootId,
            volume: volumes,
            showInTimeline: true,
            nonce: nonce.get(),
            startMediaFrom: 0 - startsAt,
            doesVolumeChange,
            loopDisplay,
            playbackRate,
            stack,
            premountDisplay,
            postmountDisplay,
            controls: null,
            effects: [],
        });
        return () => {
            unregisterSequence(id);
        };
    }, [
        duration,
        id,
        parentSequence,
        src,
        registerSequence,
        unregisterSequence,
        volumes,
        doesVolumeChange,
        nonce,
        mediaType,
        startsAt,
        playbackRate,
        stack,
        showInTimeline,
        premountDisplay,
        postmountDisplay,
        loopDisplay,
        rootId,
        finalDisplayName,
        isStudio,
    ]);
};
exports.useMediaInTimeline = useMediaInTimeline;
