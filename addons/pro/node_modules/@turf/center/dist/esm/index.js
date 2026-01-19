// index.ts
import { bbox } from "@turf/bbox";
import { point } from "@turf/helpers";
function center(geojson, options = {}) {
  const ext = bbox(geojson);
  const x = (ext[0] + ext[2]) / 2;
  const y = (ext[1] + ext[3]) / 2;
  return point([x, y], options.properties, options);
}
var index_default = center;
export {
  center,
  index_default as default
};
//# sourceMappingURL=index.js.map