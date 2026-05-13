"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.sequenceSchemaDefaultLayoutNone = exports.sequenceSchema = exports.sequenceStyleSchema = void 0;
exports.sequenceStyleSchema = {
    'style.translate': {
        type: 'translate',
        step: 1,
        default: '0px 0px',
        description: 'Offset',
    },
    'style.scale': {
        type: 'number',
        min: 0.05,
        max: 100,
        step: 0.01,
        default: 1,
        description: 'Scale',
    },
    'style.rotate': {
        type: 'rotation',
        step: 1,
        default: '0deg',
        description: 'Rotation',
    },
    'style.opacity': {
        type: 'number',
        min: 0,
        max: 1,
        step: 0.01,
        default: 1,
        description: 'Opacity',
    },
};
exports.sequenceSchema = {
    layout: {
        type: 'enum',
        default: 'absolute-fill',
        description: 'Layout',
        variants: {
            'absolute-fill': exports.sequenceStyleSchema,
            none: {},
        },
    },
};
exports.sequenceSchemaDefaultLayoutNone = {
    ...exports.sequenceSchema,
    layout: {
        ...exports.sequenceSchema.layout,
        default: 'none',
    },
};
