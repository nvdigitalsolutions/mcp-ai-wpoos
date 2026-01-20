"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createUrnFromAttrs = void 0;
function createUrnFromAttrs(type, id, namespace = 'li') {
    return `urn:${namespace}:${type}:${id}`;
}
exports.createUrnFromAttrs = createUrnFromAttrs;
//# sourceMappingURL=urn-utils.js.map