import React from 'react';
import type { LoopDisplay, SequenceControls } from './CompositionManager.js';
import type { EffectsProp } from './effects/effect-types.js';
import type { BasicMediaInTimelineReturnType } from './use-media-in-timeline.js';
export type AbsoluteFillLayout = {
    layout?: 'absolute-fill';
    premountFor?: number;
    postmountFor?: number;
    style?: React.CSSProperties;
    styleWhilePremounted?: React.CSSProperties;
    styleWhilePostmounted?: React.CSSProperties;
    className?: string;
};
export type LayoutAndStyle = AbsoluteFillLayout | {
    layout: 'none';
};
export type SequencePropsWithoutDuration = {
    readonly children: React.ReactNode;
    readonly width?: number;
    readonly height?: number;
    readonly from?: number;
    readonly name?: string;
    readonly showInTimeline?: boolean;
    readonly _experimentalControls?: SequenceControls;
    readonly _experimentalEffects?: EffectsProp;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalLoopDisplay?: LoopDisplay;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalPremountDisplay?: number | null;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalPostmountDisplay?: number | null;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalStack?: string;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalIsPremounting?: boolean;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalIsPostmounting?: boolean;
    /**
     * @deprecated For internal use only.
     */
    readonly _remotionInternalIsMedia?: {
        type: 'video' | 'audio';
        data: BasicMediaInTimelineReturnType;
    };
} & LayoutAndStyle;
export type SequenceProps = {
    readonly durationInFrames?: number;
} & SequencePropsWithoutDuration;
export declare const Sequence: React.ComponentType<SequenceProps & React.RefAttributes<HTMLDivElement>>;
