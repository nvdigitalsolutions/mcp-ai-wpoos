import React from 'react';
import type { TSequence } from './CompositionManager.js';
import type { CanUpdateSequencePropStatus, CodeValues, DragOverrides } from './use-schema.js';
export type SequenceManagerContext = {
    registerSequence: (seq: TSequence) => void;
    unregisterSequence: (id: string) => void;
    sequences: TSequence[];
};
export declare const SequenceManager: React.Context<SequenceManagerContext>;
export type SequenceVisibilityToggleState = {
    hidden: Record<string, boolean>;
    setHidden: React.Dispatch<React.SetStateAction<Record<string, boolean>>>;
};
export declare const SequenceVisibilityToggleContext: React.Context<SequenceVisibilityToggleState>;
export type VisualModeOverrides = {
    visualModeEnabled: boolean;
    dragOverrides: DragOverrides;
    setDragOverrides: (sequenceId: string, key: string, value: unknown) => void;
    clearDragOverrides: (sequenceId: string) => void;
    codeValues: CodeValues;
    setCodeValues: (sequenceId: string, values: Record<string, CanUpdateSequencePropStatus> | null) => void;
};
export declare const VisualModeOverridesContext: React.Context<VisualModeOverrides>;
export declare const SequenceManagerProvider: React.FC<{
    readonly children: React.ReactNode;
    readonly visualModeEnabled: boolean;
}>;
