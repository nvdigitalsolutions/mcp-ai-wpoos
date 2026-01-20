"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _bbox = require('@turf/bbox');
var _bboxpolygon = require('@turf/bbox-polygon');
function envelope(geojson) {
  return _bboxpolygon.bboxPolygon.call(void 0, _bbox.bbox.call(void 0, geojson));
}
var index_default = envelope;



exports.default = index_default; exports.envelope = envelope;
//# sourceMappingURL=index.cjs.map