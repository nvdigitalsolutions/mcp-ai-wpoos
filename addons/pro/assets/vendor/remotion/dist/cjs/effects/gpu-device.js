"use strict";
// Singleton accessor for the WebGPU device.
//
// `navigator.gpu.requestAdapter()` and `adapter.requestDevice()` are async and
// non-trivially expensive (~10-100ms on first call). The device is cached
// globally so every webgpu effect / chain shares the same one.
//
// `GPUDevice` is intentionally typed as `unknown` here to avoid pulling
// `@webgpu/types` into core; effects targeting webgpu narrow the type
// themselves.
Object.defineProperty(exports, "__esModule", { value: true });
exports._resetGpuDeviceForTesting = exports.getGpuDevice = void 0;
let devicePromise = null;
const getGpuDevice = () => {
    if (devicePromise) {
        return devicePromise;
    }
    devicePromise = (async () => {
        if (typeof navigator === 'undefined' || !('gpu' in navigator)) {
            throw new Error('WebGPU is not available in this environment');
        }
        const { gpu } = navigator;
        const adapter = (await gpu.requestAdapter());
        if (!adapter) {
            throw new Error('No WebGPU adapter available');
        }
        return adapter.requestDevice();
    })();
    return devicePromise;
};
exports.getGpuDevice = getGpuDevice;
// Test-only: reset the cached device. Not exported from `remotion`.
const _resetGpuDeviceForTesting = () => {
    devicePromise = null;
};
exports._resetGpuDeviceForTesting = _resetGpuDeviceForTesting;
