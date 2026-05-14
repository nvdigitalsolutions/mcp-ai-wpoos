"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.waitUntilActuallyResumed = void 0;
const log_js_1 = require("../log.js");
const waitUntilActuallyResumed = (audioContext, logLevel) => {
    return new Promise((resolve) => {
        const startCurrentTime = audioContext.currentTime;
        const start = audioContext.getOutputTimestamp();
        const startOutputPerformanceTime = start.performanceTime;
        const startWallClock = performance.now();
        const check = () => {
            var _a, _b;
            const { currentTime } = audioContext;
            const outputTimestamp = audioContext.getOutputTimestamp();
            const elapsedWallClock = performance.now() - startWallClock;
            if (startOutputPerformanceTime !== undefined &&
                outputTimestamp.performanceTime !== undefined &&
                outputTimestamp.performanceTime > startOutputPerformanceTime &&
                outputTimestamp.contextTime !== undefined &&
                outputTimestamp.contextTime > startCurrentTime) {
                log_js_1.Log.verbose({ logLevel, tag: 'audio' }, `waitUntilActuallyResumed: getOutputTimestamp.performanceTime advanced from ${startOutputPerformanceTime.toFixed(6)} to ${outputTimestamp.performanceTime.toFixed(6)} after ${elapsedWallClock.toFixed(1)}ms. currentTime=${currentTime.toFixed(6)} (advanced by ${(currentTime - startCurrentTime).toFixed(6)}), getOutputTimestamp.performanceTime=${(_b = (_a = outputTimestamp.performanceTime) === null || _a === void 0 ? void 0 : _a.toFixed(1)) !== null && _b !== void 0 ? _b : 'undefined'}`);
                resolve();
                return;
            }
            requestAnimationFrame(check);
        };
        requestAnimationFrame(check);
    });
};
exports.waitUntilActuallyResumed = waitUntilActuallyResumed;
