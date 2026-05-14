import type { SequenceSchema } from './sequence-field-schema.js';
export type CanUpdateSequencePropStatus = {
    canUpdate: true;
    codeValue: unknown;
} | {
    canUpdate: false;
    reason: 'computed';
};
export type DragOverrides = Record<string, Record<string, unknown>>;
export type CodeValues = Record<string, Record<string, CanUpdateSequencePropStatus>>;
export declare const computeEffectiveSchemaValuesDotNotation: ({ schema, currentValue, overrideValues, propStatus, }: {
    schema: SequenceSchema;
    currentValue: Record<string, unknown>;
    overrideValues: Record<string, unknown>;
    propStatus: Record<string, CanUpdateSequencePropStatus> | undefined;
}) => Record<string, unknown>;
