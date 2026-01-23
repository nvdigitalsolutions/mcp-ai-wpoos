/**
 * Generates a pegasus/restli diff for two Objects.
 * For more information about the format, read:
 * https://github.com/linkedin/rest.li/wiki/Rest.li-Protocol#partial-update
 *
 * @method getDiff
 * @param {Object} original
 * @param {Object} modified
 * @return {Object}
 */
export declare function getPatchObject(original: any, modified: any): {
    patch: any;
};
