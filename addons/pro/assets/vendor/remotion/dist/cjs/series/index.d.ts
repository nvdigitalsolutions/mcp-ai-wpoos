import { type PropsWithChildren } from 'react';
import React from 'react';
import type { LayoutAndStyle, SequenceProps } from '../Sequence.js';
type SeriesSequenceProps = PropsWithChildren<{
    readonly durationInFrames: number;
    readonly offset?: number;
    readonly className?: string;
} & Pick<SequenceProps, 'layout' | 'name'> & LayoutAndStyle>;
declare const SeriesSequence: React.ForwardRefExoticComponent<SeriesSequenceProps & React.RefAttributes<HTMLDivElement>>;
type SeriesProps = SequenceProps;
/**
 * @description with this component, you can easily stitch together scenes that should play sequentially after another.
 * @see [Documentation](https://www.remotion.dev/docs/series)
 */
declare const Series: React.ComponentType<SeriesProps> & {
    Sequence: typeof SeriesSequence;
};
export { Series };
