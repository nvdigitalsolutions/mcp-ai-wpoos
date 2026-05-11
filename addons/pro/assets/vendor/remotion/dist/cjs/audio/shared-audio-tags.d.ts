import { type AudioHTMLAttributes } from 'react';
import React from 'react';
import type { SharedElementSourceNode } from './shared-element-source-node.js';
/**
 * This functionality of Remotion will keep a certain amount
 * of <audio> tags pre-mounted and by default filled with an empty audio track.
 * If the user interacts, the empty audio will be played.
 * If one of Remotions <Html5Audio /> tags get mounted, the audio will not be rendered at this location, but into one of the prerendered audio tags.
 *
 * This helps with autoplay issues on iOS Safari and soon other browsers,
 * which only allow audio playback upon user interaction.
 *
 * The behavior can be disabled by passing `0` as the number of shared audio tracks.
 */
type AudioElem = {
    id: number;
    props: AudioHTMLAttributes<HTMLAudioElement>;
    el: React.RefObject<HTMLAudioElement | null>;
    audioId: string;
    mediaElementSourceNode: SharedElementSourceNode | null;
    premounting: boolean;
    postmounting: boolean;
    audioMounted: boolean;
    cleanupOnMediaTagUnmount: () => void;
};
export type ScheduleAudioNodeResult = {
    type: 'started';
    scheduledTime: number;
} | {
    type: 'not-started';
    reason: string;
};
export type ScheduleAudioNodeOptions = {
    readonly node: AudioBufferSourceNode;
    readonly mediaTimestamp: number;
    readonly currentTime: number;
    readonly scheduledTime: number;
    readonly originalUnloopedMediaTimestamp: number;
    readonly duration: number;
    readonly offset: number;
};
export type AudioSyncAnchorEvent = 'changed';
export type AudioSyncAnchorListener = (event: AudioSyncAnchorEvent) => void;
export type AudioSyncAnchorEmitter = {
    dispatch: (event: AudioSyncAnchorEvent) => void;
    subscribe: (listener: AudioSyncAnchorListener) => {
        remove: () => void;
    };
};
type SharedAudioContextValue = {
    audioContext: AudioContext | null;
    gainNode: GainNode | null;
    audioSyncAnchor: {
        value: number;
    };
    audioSyncAnchorEmitter: AudioSyncAnchorEmitter;
    scheduleAudioNode: (options: ScheduleAudioNodeOptions) => ScheduleAudioNodeResult;
    resume: () => Promise<void>;
    suspend: () => void;
    getIsResumingAudioContext: () => Promise<void> | null;
    unscheduleAudioNode: (node: AudioBufferSourceNode) => void;
};
type SharedAudioTagsContextValue = {
    registerAudio: (options: {
        aud: AudioHTMLAttributes<HTMLAudioElement>;
        audioId: string;
        premounting: boolean;
        postmounting: boolean;
    }) => AudioElem;
    unregisterAudio: (id: number) => void;
    updateAudio: (options: {
        id: number;
        aud: AudioHTMLAttributes<HTMLAudioElement>;
        audioId: string;
        premounting: boolean;
        postmounting: boolean;
    }) => void;
    playAllAudios: () => void;
    numberOfAudioTags: number;
};
export declare const SharedAudioContext: React.Context<SharedAudioContextValue | null>;
export declare const SharedAudioTagsContext: React.Context<SharedAudioTagsContextValue | null>;
export declare const SharedAudioContextProvider: React.FC<{
    readonly children: React.ReactNode;
    readonly audioLatencyHint: AudioContextLatencyCategory;
    readonly audioEnabled: boolean;
}>;
export declare const SharedAudioTagsContextProvider: React.FC<{
    readonly numberOfAudioTags: number;
    readonly children: React.ReactNode;
}>;
export declare const useSharedAudio: ({ aud, audioId, premounting, postmounting, }: {
    aud: AudioHTMLAttributes<HTMLAudioElement>;
    audioId: string;
    premounting: boolean;
    postmounting: boolean;
}) => AudioElem;
export {};
