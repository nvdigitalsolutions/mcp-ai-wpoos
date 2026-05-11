export type NumberFieldSchema = {
    type: 'number';
    min?: number;
    max?: number;
    step?: number;
    default: number | undefined;
    description?: string;
};
export type BooleanFieldSchema = {
    type: 'boolean';
    default: boolean;
    description?: string;
};
export type RotationFieldSchema = {
    type: 'rotation';
    step?: number;
    default: string | undefined;
    description?: string;
};
export type TranslateFieldSchema = {
    type: 'translate';
    step?: number;
    default: string | undefined;
    description?: string;
};
export type EnumFieldSchema = {
    type: 'enum';
    default: string;
    description?: string;
    variants: Record<string, SequenceSchema>;
};
export type SequenceFieldSchema = NumberFieldSchema | BooleanFieldSchema | RotationFieldSchema | TranslateFieldSchema | EnumFieldSchema;
export type SequenceSchema = {
    [key: string]: SequenceFieldSchema;
};
export type SchemaKeysRecord<S extends SequenceSchema> = Record<keyof S, unknown>;
export declare const sequenceStyleSchema: {
    readonly 'style.translate': {
        readonly type: "translate";
        readonly step: 1;
        readonly default: "0px 0px";
        readonly description: "Offset";
    };
    readonly 'style.scale': {
        readonly type: "number";
        readonly min: 0.05;
        readonly max: 100;
        readonly step: 0.01;
        readonly default: 1;
        readonly description: "Scale";
    };
    readonly 'style.rotate': {
        readonly type: "rotation";
        readonly step: 1;
        readonly default: "0deg";
        readonly description: "Rotation";
    };
    readonly 'style.opacity': {
        readonly type: "number";
        readonly min: 0;
        readonly max: 1;
        readonly step: 0.01;
        readonly default: 1;
        readonly description: "Opacity";
    };
};
export declare const sequenceSchema: {
    readonly layout: {
        readonly type: "enum";
        readonly default: "absolute-fill";
        readonly description: "Layout";
        readonly variants: {
            readonly 'absolute-fill': {
                readonly 'style.translate': {
                    readonly type: "translate";
                    readonly step: 1;
                    readonly default: "0px 0px";
                    readonly description: "Offset";
                };
                readonly 'style.scale': {
                    readonly type: "number";
                    readonly min: 0.05;
                    readonly max: 100;
                    readonly step: 0.01;
                    readonly default: 1;
                    readonly description: "Scale";
                };
                readonly 'style.rotate': {
                    readonly type: "rotation";
                    readonly step: 1;
                    readonly default: "0deg";
                    readonly description: "Rotation";
                };
                readonly 'style.opacity': {
                    readonly type: "number";
                    readonly min: 0;
                    readonly max: 1;
                    readonly step: 0.01;
                    readonly default: 1;
                    readonly description: "Opacity";
                };
            };
            readonly none: {};
        };
    };
};
export declare const sequenceSchemaDefaultLayoutNone: SequenceSchema;
