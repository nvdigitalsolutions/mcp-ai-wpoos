"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _booleandisjoint = require('@turf/boolean-disjoint');
var _meta = require('@turf/meta');
function booleanIntersects(feature1, feature2, {
  ignoreSelfIntersections = true
} = {}) {
  let bool = false;
  _meta.flattenEach.call(void 0, feature1, (flatten1) => {
    _meta.flattenEach.call(void 0, feature2, (flatten2) => {
      if (bool === true) {
        return true;
      }
      bool = !_booleandisjoint.booleanDisjoint.call(void 0, flatten1.geometry, flatten2.geometry, {
        ignoreSelfIntersections
      });
    });
  });
  return bool;
}
var index_default = booleanIntersects;



exports.booleanIntersects = booleanIntersects; exports.default = index_default;
//# sourceMappingURL=index.cjs.map