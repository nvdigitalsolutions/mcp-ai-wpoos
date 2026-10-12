// nvoos-audio-helper: ScreenCaptureKit system-audio capture for NV oOS
// Dictation meetings. Speaks NDJSON on stdout:
//   hello -> ready -> chunk... -> stopped | error
//
// Usage:
//   nvoos-audio-helper capture     capture until SIGINT/SIGTERM
//   nvoos-audio-helper --check     print hello/ready/stopped and exit
//                                  (binary-presence + framing smoke test)
import Foundation

@main
struct Main {
    static func main() async {
        Output.hello()

        if CommandLine.arguments.contains("--check") {
            Output.ready(sampleRate: 16_000, channels: 1)
            Output.stopped()
            return
        }

        // Graceful shutdown: emit `stopped` before exiting so the Rust core
        // can distinguish "user stopped" from a crash mid-stream.
        for signal in [SIGINT, SIGTERM] {
            signal(signal, SIG_IGN)
            let source = DispatchSource.makeSignalSource(signal: signal, queue: .main)
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
