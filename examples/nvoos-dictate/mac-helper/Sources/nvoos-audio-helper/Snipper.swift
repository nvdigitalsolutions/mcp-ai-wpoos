// Snippet OCR for NV oOS Dictation (Proposal 100, M3).
//
// Two one-shot operations speaking the helper's existing NDJSON stdout
// protocol (the long-lived `capture` audio stream is untouched):
//
//   screenshot          -> {"type":"image","png":"<b64>","width":..,"height":..}
//   ocr --png <b64>     -> {"type":"ocr_result","text":"…","words":[…]}
//
// Capture: ScreenCaptureKit's SCScreenshotManager (macOS 14+ — the
// sanctioned replacement for the deprecated CGWindowListCreateImage, which
// remains the macOS 13 fallback). The Screen Recording TCC grant is shared
// with the meetings system-audio path.
//
// OCR: Apple Vision VNRecognizeTextRequest (.accurate, language correction
// on) — the Live Text engine. v1 returns line-level text (Vision's public
// granularity); per-word boxes are not emitted.
import AppKit
import Foundation
import ScreenCaptureKit
import Vision

@available(macOS 13.0, *)
enum Snipper {
    // MARK: capture

    static func captureScreenshot() async throws -> (png: Data, width: Int, height: Int) {
        if #available(macOS 14.0, *) {
            return try await captureWithSCK()
        }
        return try captureWithCGWindowList()
    }

    @available(macOS 14.0, *)
    private static func captureWithSCK() async throws -> (png: Data, width: Int, height: Int) {
        let content: SCShareableContent
        do {
            content = try await SCShareableContent.excludingDesktopWindows(
                false, onScreenWindowsOnly: false)
        } catch {
            throw HelperError.shareableContentUnavailable
        }
        guard let display = content.displays.first else {
            throw HelperError.noDisplay
        }
        let filter = SCContentFilter(display: display, excludingWindows: [])
        let config = SCStreamConfiguration()
        config.width = Int(display.width)
        config.height = Int(display.height)
        config.showsCursor = false
        config.capturesAudio = false
        let image = try await SCScreenshotManager.captureImage(
            contentFilter: filter, configuration: config)
        return try pngData(from: image)
    }

    private static func captureWithCGWindowList() throws -> (png: Data, width: Int, height: Int) {
        // macOS 13 fallback (CGWindowListCreateImage is deprecated on 14+;
        // this branch only runs there — the deprecation warning is accepted
        // and documented).
        let options: CGWindowImageOption = [.boundsIgnoreFraming, .bestResolution]
        guard let image = CGWindowListCreateImage(
            .null, .optionOnScreenOnly, kCGNullWindowID, options)
        else {
            throw HelperError.screenshotFailed
        }
        return try pngData(from: image)
    }

    private static func pngData(from image: CGImage) throws -> (png: Data, width: Int, height: Int) {
        let rep = NSBitmapImageRep(cgImage: image)
        guard let png = rep.representation(using: .png, properties: [:]) else {
            throw HelperError.screenshotFailed
        }
        return (png, image.width, image.height)
    }

    // MARK: OCR

    static func recognize(pngData: Data, langs: [String]) throws -> (text: String, words: [[String: Any]]) {
        guard let rep = NSBitmapImageRep(data: pngData), let cgImage = rep.cgImage else {
            throw HelperError.imageDecodeFailed
        }
        let request = VNRecognizeTextRequest()
        request.recognitionLevel = .accurate
        request.usesLanguageCorrection = true
        if !langs.isEmpty {
            request.recognitionLanguages = langs
        }
        let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])
        try handler.perform([request])
        let observations = request.results ?? []
        // Reading order: Vision boxes are normalized, bottom-left origin —
        // sort top-to-bottom (descending midY), then left-to-right.
        let sorted = observations.sorted { a, b in
            let ay = a.boundingBox.midY
            let by = b.boundingBox.midY
            if abs(ay - by) > 0.02 {
                return ay > by
            }
            return a.boundingBox.minX < b.boundingBox.minX
        }
        let text = sorted
            .compactMap { $0.topCandidates(1).first?.string }
            .joined(separator: "\n")
        return (text, [])
    }

    /// CI smoke: run the Vision stack against a generated blank bitmap and
    /// return the (expected-empty) result — proves the OCR path loads
    /// without needing a real screenshot or TCC grant.
    static func smokeCheck() throws -> (text: String, words: [[String: Any]]) {
        let width = 96
        let height = 32
        let rep = NSBitmapImageRep(
            bitmapDataPlanes: nil, pixelsWide: width, pixelsHigh: height,
            bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false,
            colorSpaceName: .deviceRGB, bytesPerRow: width * 4, bitsPerPixel: 32)!
        NSGraphicsContext.saveGraphicsState()
        NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: rep)
        NSColor.white.setFill()
        NSRect(x: 0, y: 0, width: width, height: height).fill()
        NSGraphicsContext.restoreGraphicsState()
        guard let png = rep.representation(using: .png, properties: [:]) else {
            throw HelperError.imageDecodeFailed
        }
        return try recognize(pngData: png, langs: ["en"])
    }
}
