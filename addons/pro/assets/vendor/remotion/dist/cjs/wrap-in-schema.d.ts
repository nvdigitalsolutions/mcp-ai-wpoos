import React from 'react';
import type { SequenceControls } from './CompositionManager.js';
import type { SequenceSchema } from './sequence-field-schema.js';
export declare const getNestedValue: (obj: Record<string, unknown>, key: string) => unknown;
export declare const readValuesFromProps: (props: Record<string, unknown>, keys: string[]) => Record<string, unknown>;
export declare const selectActiveKeys: (schema: SequenceSchema, values: Record<string, unknown>) => string[];
export declare const mergeValues: ({ props, valuesDotNotation, schemaKeys, }: {
    props: Record<string, unknown>;
    valuesDotNotation: Record<string, unknown>;
    schemaKeys: string[];
}) => Record<string, unknown>;
export declare const wrapInSchema: <S extends SequenceSchema, Props extends object>(Component: React.ComponentType<Props & {
    readonly _experimentalControls: SequenceControls | undefined;
}>, schema: S) => React.ComponentType<Props>;
