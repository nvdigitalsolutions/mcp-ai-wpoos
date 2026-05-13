export declare const getTimelineDuration: ({ compositionDurationInFrames, playbackRate, trimBefore, trimAfter, parentSequenceDurationInFrames, loop, }: {
    compositionDurationInFrames: number;
    playbackRate: number;
    trimBefore: number | undefined;
    trimAfter: number | undefined;
    parentSequenceDurationInFrames: number | null;
    loop: boolean;
}) => number;
