// index.ts
import { bearing } from "@turf/bearing";
import { destination } from "@turf/destination";
import { distance } from "@turf/distance";
function midpoint(point1, point2) {
  const dist = distance(point1, point2);
  const heading = bearing(point1, point2);
  const midpoint2 = destination(point1, dist / 2, heading);
  return midpoint2;
}
var index_default = midpoint;
export {
  index_default as default,
  midpoint
};
//# sourceMappingURL=index.js.map