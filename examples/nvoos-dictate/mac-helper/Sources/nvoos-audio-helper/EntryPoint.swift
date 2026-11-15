// nvoos-audio-helper: ScreenCaptureKit system-audio capture for NV oOS
// Dictation meetings. Speaks NDJSON on stdout:
//   hello -> ready -> chunk... -> stopped | error
//
// Usage:
//   nvoos-audio-helper capture     capture until SIGINT/SIGTERM
//   nvoos-audio-helper --check     print hello/ready/stopped and exit
//                                  (binary-presence + framing smoke test)
//
// NOTE: this file must NOT be named `main.swift` — SwiftPM treats a
// `main.swift` file as a top-level-code entry point, which conflicts with
// the `@main` attribute.
import Foundation

@main
struct EntryPoint {
    static func main() async {
        Output.hello()

        let args = CommandLine.arguments

        if args.contains("--check") {
            Output.ready(sampleRate: 16_000, channels: 1)
            // Snippet OCR smoke (Proposal 100): run the Vision stack against
            // a generated blank bitmap — proves the OCR path loads on CI
            // without a real screenshot or TCC grant.
            do {
                let (text, _) = try Snipper.smokeCheck()
                Output.ocrResult(text: text)
            } catch {
                Output.error("ocr smoke check failed: \(error.localizedDescription)")
            }
            Output.stopped()
            return
        }

        // Snippet OCR one-shot: screenshot -> {"type":"image",…} -> stopped.
        if args.contains("screenshot") {
            do {
                let result = try await Snipper.captureScreenshot()
                Output.image(
                    png: result.png.base64EncodedString(),
                    width: result.width,
                    height: result.height)
            } catch {
                Output.error(error.localizedDescription)
                exit(1)
            }
            Output.stopped()
            return
        }

        // Snippet OCR one-shot: ocr --png <b64> [--langs en,de].
        if args.contains("ocr") {
            guard let pngIdx = args.firstIndex(of: "--png"),
                pngIdx + 1 < args.count,
                let data = Data(base64Encoded: args[pngIdx + 1])
            else {
                Output.error("usage: ocr --png <base64> [--langs en,de]")
                exit(2)
            }
            var langs: [String] = []
            if let langsIdx = args.firstIndex(of: "--langs"), langsIdx + 1 < args.count {
                langs = args[langsIdx + 1].split(separator: ",").map(String.init)
            }
            do {
                let (text, words) = try Snipper.recognize(pngData: data, langs: langs)
                Output.ocrResult(text: text, words: words)
            } catch {
                Output.error(error.localizedDescription)
                exit(1)
            }
            Output.stopped()
            return
        }

        // Graceful shutdown: emit `stopped` before exiting so the Rust core
        // can distinguish "user stopped" from a crash mid-stream. (The loop
        // variable is `sig` — `signal` is the Darwin C function.)
        for sig in [SIGINT, SIGTERM] {
            _ = signal(sig, SIG_IGN)
            let source = DispatchSource.makeSignalSource(signal: sig, queue: .main)
            source.setEventHandler {
                Output.stopped()
                exit(0)
            }
            source.resume()
        }

        let capturer = Capturer()
        do {
            try await capturer.start()
        } catch {
            Output.error(error.localizedDescription)
            exit(1)
        }
        Output.ready(sampleRate: 16_000, channels: 1)
        dispatchMain()
    }
}
