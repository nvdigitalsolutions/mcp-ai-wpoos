// index.ts
import { polygon } from "@turf/helpers";
import { coordEach } from "@turf/meta";
import concaveman from "concaveman";
function convex(geojson, options = {}) {
  options.concavity = options.concavity || Infinity;
  const points = [];
  coordEach(geojson, (coord) => {
    points.push([coord[0], coord[1]]);
  });
  if (!points.length) {
    return null;
  }
  const convexHull = concaveman(points, options.concavity);
  if (convexHull.length > 3) {
    return polygon([convexHull]);
  }
  return null;
}
var index_default = convex;
export {
  convex,
  index_default as default
};
//# sourceMappingURL=index.js.map