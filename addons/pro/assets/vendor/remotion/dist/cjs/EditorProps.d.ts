import type { SyntheticEvent } from 'react';
import React from 'react';
type Props = Record<string, Record<string, unknown>>;
export type EditorPropsContextType = {
    props: Props;
    updateProps: (options: {
        id: string;
        defaultProps: Record<string, unknown>;
        newProps: Record<string, unknown> | ((oldProps: Record<string, unknown>) => Record<string, unknown>);
    }) => void;
};
export declare const EditorPropsContext: React.Context<EditorPropsContextType>;
export declare const timeValueRef: React.RefObject<{
    goToFrame: () => void;
    seek: (newFrame: number) => void;
    play: (e?: SyntheticEvent | PointerEvent) => void;
    pause: () => void;
    toggle: (e?: SyntheticEvent | PointerEvent) => void;
} | null>;
export declare const EditorPropsProvider: React.FC<{
    readonly children: React.ReactNode;
}>;
export {};
