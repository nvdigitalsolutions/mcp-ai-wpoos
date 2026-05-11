import React from 'react';
/**
 * @description Wraps the native `<video>` element to include video in your component that is synchronized with Remotion's time.
 * @see [Documentation](https://www.remotion.dev/docs/html5-video)
 */
export declare const Html5Video: React.ForwardRefExoticComponent<Omit<import("./props").NativeVideoProps & {
    name?: string | undefined;
    volume?: import("../volume-prop.js").VolumeProp | undefined;
    playbackRate?: number | undefined;
    acceptableTimeShiftInSeconds?: number | undefined;
    allowAmplificationDuringRender?: boolean | undefined;
    useWebAudioApi?: boolean | undefined;
    toneFrequency?: number | undefined;
    pauseWhenBuffering?: boolean | undefined;
    showInTimeline?: boolean | undefined;
    delayRenderTimeoutInMilliseconds?: number | undefined;
    loopVolumeCurveBehavior?: import("../index.js").LoopVolumeCurveBehavior | undefined;
    delayRenderRetries?: number | undefined;
    onError?: ((err: Error) => void) | undefined;
    onAutoPlayError?: (() => void) | null | undefined;
    audioStreamIndex?: number | undefined;
} & Partial<import("./props").CommonVideoProps> & {
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string | undefined;
}, "ref"> & React.RefAttributes<HTMLVideoElement>>;
/**
 * @deprecated This component has been renamed to `Html5Video`.
 * @see [Documentation](https://www.remotion.dev/docs/html5-video)
 */
export declare const Video: React.ForwardRefExoticComponent<Omit<import("./props").NativeVideoProps & {
    name?: string | undefined;
    volume?: import("../volume-prop.js").VolumeProp | undefined;
    playbackRate?: number | undefined;
    acceptableTimeShiftInSeconds?: number | undefined;
    allowAmplificationDuringRender?: boolean | undefined;
    useWebAudioApi?: boolean | undefined;
    toneFrequency?: number | undefined;
    pauseWhenBuffering?: boolean | undefined;
    showInTimeline?: boolean | undefined;
    delayRenderTimeoutInMilliseconds?: number | undefined;
    loopVolumeCurveBehavior?: import("../index.js").LoopVolumeCurveBehavior | undefined;
    delayRenderRetries?: number | undefined;
    onError?: ((err: Error) => void) | undefined;
    onAutoPlayError?: (() => void) | null | undefined;
    audioStreamIndex?: number | undefined;
} & Partial<import("./props").CommonVideoProps> & {
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string | undefined;
}, "ref"> & React.RefAttributes<HTMLVideoElement>>;
