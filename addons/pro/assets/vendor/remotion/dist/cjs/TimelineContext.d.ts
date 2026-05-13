import type { MutableRefObject } from 'react';
import React from 'react';
import { type PlayableMediaTag } from './timeline-position-state';
export type TimelineContextValue = {
    frame: Record<string, number>;
    playing: boolean;
    rootId: string;
    imperativePlaying: MutableRefObject<boolean>;
    audioAndVideoTags: MutableRefObject<PlayableMediaTag[]>;
};
export type PlaybackRateContextValue = {
    playbackRate: number;
    setPlaybackRate: (u: React.SetStateAction<number>) => void;
};
export type SetTimelineContextValue = {
    setFrame: (u: React.SetStateAction<Record<string, number>>) => void;
    setPlaying: (u: React.SetStateAction<boolean>) => void;
};
export declare const SetTimelineContext: React.Context<SetTimelineContextValue>;
export declare const TimelineContext: React.Context<TimelineContextValue | null>;
export declare const PlaybackRateContext: React.Context<PlaybackRateContextValue | null>;
export declare const AbsoluteTimeContext: React.Context<TimelineContextValue | null>;
export declare const TimelineContextProvider: React.FC<{
    readonly children: React.ReactNode;
    readonly frameState: Record<string, number> | null;
}>;
