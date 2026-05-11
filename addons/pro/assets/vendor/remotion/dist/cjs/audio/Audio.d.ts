import React from 'react';
import type { RemotionMainAudioProps } from './props.js';
/**
 * @description With this component, you can add audio to your video. All audio formats which are supported by Chromium are supported by the component.
 * @see [Documentation](https://remotion.dev/docs/html5-audio)
 */
export declare const Html5Audio: React.ForwardRefExoticComponent<Omit<import("./props.js").NativeAudioProps & {
    name?: string | undefined;
    volume?: import("../volume-prop.js").VolumeProp | undefined;
    playbackRate?: number | undefined;
    acceptableTimeShiftInSeconds?: number | undefined;
    allowAmplificationDuringRender?: boolean | undefined;
    _remotionInternalNeedsDurationCalculation?: boolean | undefined;
    _remotionInternalNativeLoopPassed?: boolean | undefined;
    toneFrequency?: number | undefined;
    useWebAudioApi?: boolean | undefined;
    pauseWhenBuffering?: boolean | undefined;
    showInTimeline?: boolean | undefined;
    delayRenderTimeoutInMilliseconds?: number | undefined;
    delayRenderRetries?: number | undefined;
    loopVolumeCurveBehavior?: import("./use-audio-frame.js").LoopVolumeCurveBehavior | undefined;
    onError?: ((err: Error) => void) | undefined;
    audioStreamIndex?: number | undefined;
} & RemotionMainAudioProps & {
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string | undefined;
}, "ref"> & React.RefAttributes<HTMLAudioElement>>;
/**
 * @deprecated This component has been renamed to `Html5Audio`.
 * @see [Documentation](https://remotion.dev/docs/mediabunny/new-video)
 */
export declare const Audio: React.ForwardRefExoticComponent<Omit<import("./props.js").NativeAudioProps & {
    name?: string | undefined;
    volume?: import("../volume-prop.js").VolumeProp | undefined;
    playbackRate?: number | undefined;
    acceptableTimeShiftInSeconds?: number | undefined;
    allowAmplificationDuringRender?: boolean | undefined;
    _remotionInternalNeedsDurationCalculation?: boolean | undefined;
    _remotionInternalNativeLoopPassed?: boolean | undefined;
    toneFrequency?: number | undefined;
    useWebAudioApi?: boolean | undefined;
    pauseWhenBuffering?: boolean | undefined;
    showInTimeline?: boolean | undefined;
    delayRenderTimeoutInMilliseconds?: number | undefined;
    delayRenderRetries?: number | undefined;
    loopVolumeCurveBehavior?: import("./use-audio-frame.js").LoopVolumeCurveBehavior | undefined;
    onError?: ((err: Error) => void) | undefined;
    audioStreamIndex?: number | undefined;
} & RemotionMainAudioProps & {
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string | undefined;
}, "ref"> & React.RefAttributes<HTMLAudioElement>>;
