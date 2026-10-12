// ScreenCaptureKit audio capture (macOS 13+).
//
// Captures the system's audio output (the "other side of the call") as
// audio-only. On macOS 14.2+ the system presents its own sharing picker /
// consent UI the first time; on 13.x the Screen Recording permission prompt
// covers it. Each CMSampleBuffer is converted to mono 16 kHz Int16 PCM and
// emitted as a `chunk` NDJSON frame on stdout.
import AVFoundation
import CoreMedia
import Foundation
import ScreenCaptureKit

enum HelperError: LocalizedError {
    case noDisplay
    case shareableContentUnavailable
    case streamConfigurationFailed(String)

    var errorDescription: String? {
        switch self {
        case .noDisplay:
            return "no display available for audio capture"
        case .shareableContentUnavailable:
            return "ScreenCaptureKit content unavailable (Screen Recording permission?)"
        case .streamConfigurationFailed(let message):
            return "stream configuration failed: \(message)"
        }
    }
}

final class Capturer: NSObject, SCStreamOutput {
    private var stream: SCStream?
    private let queue = DispatchQueue(label: "com.nvdigitalsolutions.nvoos-dictate.helper.audio")
    private var seq: UInt64 = 0
    private let targetSampleRate = 16000

    func start() async throws {
        let content: SCShareableContent
        do {
            content = try await SCShareableContent.excludingDesktopWindows(
                false, onScreenWindowsOnly: true)
        } catch {
            throw HelperError.shareableContentUnavailable
        }
        guard let display = content.displays.first else {
            throw HelperError.noDisplay
        }

        // Audio-only capture still needs a display in the content filter on
        // macOS 13/14. Video dimensions are nominal (no video output is
        // requested, so no frames are delivered).
        let filter = SCContentFilter(display: display, excludingWindows: [])
        let config = SCStreamConfiguration()
        config.width = 2
        config.height = 2
        config.capturesAudio = true
        config.excludesCurrentProcessAudio = true

        let stream = SCStream(filter: filter, configuration: config, delegate: nil)
        do {
            try stream.addStreamOutput(self, type: .audio, sampleHandlerQueue: queue)
            try await stream.startCapture()
        } catch {
            throw HelperError.streamConfigurationFailed(error.localizedDescription)
        }
        self.stream = stream
    }

    func stop() {
        let stream = self.stream
        self.stream = nil
        Task {
            try? await stream?.stopCapture()
        }
    }

    // SCStreamOutput
    func stream(
        _ stream: SCStream, didOutputSampleBuffer sampleBuffer: CMSampleBuffer,
        of type: SCStreamOutputType
    ) {
        guard type == .audio, let pcm = Self.convert(sampleBuffer, to: targetSampleRate) else {
            return
        }
        Output.chunk(
            seq: seq, sampleRate: targetSampleRate, channels: 1,
            pcm16: pcm.base64EncodedString())
        seq &+= 1
    }

    /// CMSampleBuffer -> mono 16 kHz interleaved Int16 PCM bytes.
    private static func convert(_ buffer: CMSampleBuffer, to targetRate: Int) -> Data? {
        guard let format = CMSampleBufferGetFormatDescription(buffer),
            let asbd = CMAudioFormatDescriptionGetStreamBasicDescription(format)?.pointee
        else {
            return nil
        }
        let inputFormat = AVAudioFormat(
            standardFormatWithSampleRate: asbd.mSampleRate,
            channels: asbd.mChannelsPerFrame)
        guard
            let outputFormat = AVAudioFormat(
                commonFormat: .pcmFormatInt16, sampleRate: Double(targetRate),
                channels: 1, interleaved: true),
            let converter = AVAudioConverter(from: inputFormat, to: outputFormat)
        else {
            return nil
        }

        // Two-pass buffer-list extraction: query the size, then copy with a
        // retained block buffer so the samples outlive the callback.
        var sizeNeeded = 0
        guard
            CMSampleBufferGetAudioBufferListWithRetainedBlockBuffer(
                buffer, bufferListSizeNeededOut: &sizeNeeded, bufferListOut: nil,
                bufferListSize: 0, blockBufferAllocator: nil,
                blockBufferMemoryAllocator: nil, flags: 0, blockBufferOut: nil) == noErr,
            sizeNeeded > 0
        else {
            return nil
        }
        let raw = UnsafeMutableRawPointer.allocate(
            byteCount: sizeNeeded, alignment: MemoryLayout<AudioBufferList>.alignment)
        defer { raw.deallocate() }
        let bufferList = raw.bindMemory(to: AudioBufferList.self, capacity: 1)
        var retained: CMBlockBuffer?
        guard
            CMSampleBufferGetAudioBufferListWithRetainedBlockBuffer(
                buffer, bufferListSizeNeededOut: nil, bufferListOut: bufferList,
                bufferListSize: sizeNeeded, blockBufferAllocator: nil,
                blockBufferMemoryAllocator: nil, flags: 0, blockBufferOut: &retained)
                == noErr
        else {
            return nil
        }

        let inputPCM = AVAudioPCMBuffer(
            pcmFormat: inputFormat, bufferListNoCopy: bufferList)!
        let frameCapacity = AVAudioFrameCount(
            outputFormat.sampleRate * Double(inputPCM.frameLength) / inputFormat.sampleRate)
        guard let outputPCM = AVAudioPCMBuffer(
            pcmFormat: outputFormat, frameCapacity: frameCapacity)
        else {
            return nil
        }

        var conversionError: NSError?
        var fed = false
        let status = converter.convert(to: outputPCM, error: &conversionError) {
            _, outStatus in
            if fed {
                outStatus.pointee = .noDataNow
                return nil
            }
            fed = true
            outStatus.pointee = .haveData
            return inputPCM
        }
        guard status != .error, conversionError == nil, outputPCM.frameLength > 0,
            let channelData = outputPCM.int16ChannelData,
            let samples = channelData[0]
        else {
            return nil
        }
        return Data(
            bytes: samples, count: Int(outputPCM.frameLength) * MemoryLayout<Int16>.size)
    }
}
