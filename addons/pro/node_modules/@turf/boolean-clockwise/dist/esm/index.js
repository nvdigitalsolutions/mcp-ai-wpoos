// index.ts
import { getCoords } from "@turf/invariant";
function booleanClockwise(line) {
  const ring = getCoords(line);
  let sum = 0;
  let i = 1;
  let prev;
  let cur;
  while (i < ring.length) {
    prev = cur || ring[0];
    cur = ring[i];
    sum += (cur[0] - prev[0]) * (cur[1] + prev[1]);
    i++;
  }
  return sum > 0;
}
var index_default = booleanClockwise;
export {
  booleanClockwise,
  index_default as default
};
//# sourceMappingURL=index.js.map