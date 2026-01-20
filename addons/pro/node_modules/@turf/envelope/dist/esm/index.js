// index.ts
import { bbox } from "@turf/bbox";
import { bboxPolygon } from "@turf/bbox-polygon";
function envelope(geojson) {
  return bboxPolygon(bbox(geojson));
}
var index_default = envelope;
export {
  index_default as default,
  envelope
};
//# sourceMappingURL=index.js.map