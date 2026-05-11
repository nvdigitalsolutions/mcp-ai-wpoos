import React from 'react';
import type { EffectsProp } from './effect-types.js';
export type SolidProps = {
    readonly color: string;
    readonly width: number;
    readonly height: number;
    readonly _experimentalEffects?: EffectsProp;
    readonly className?: string;
    readonly style?: React.CSSProperties;
    readonly pixelRatio?: number;
};
export declare const Solid: React.FC<SolidProps>;
