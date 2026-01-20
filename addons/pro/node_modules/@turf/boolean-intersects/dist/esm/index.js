// index.ts
import { booleanDisjoint } from "@turf/boolean-disjoint";
import { flattenEach } from "@turf/meta";
function booleanIntersects(feature1, feature2, {
  ignoreSelfIntersections = true
} = {}) {
  let bool = false;
  flattenEach(feature1, (flatten1) => {
    flattenEach(feature2, (flatten2) => {
      if (bool === true) {
        return true;
      }
      bool = !booleanDisjoint(flatten1.geometry, flatten2.geometry, {
        ignoreSelfIntersections
      });
    });
  });
  return bool;
}
var index_default = booleanIntersects;
export {
  booleanIntersects,
  index_default as default
};
//# sourceMappingURL=index.js.map