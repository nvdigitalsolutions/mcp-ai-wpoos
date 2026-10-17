// NDJSON framing to stdout — the protocol the Rust core parses
// (`src-tauri/src/meetings/sidecar_protocol.rs`). One JSON object per line;
// `chunk` frames carry little-endian interleaved Int16 PCM at 16 kHz mono.
import Foundation

enum Output {
    static func send(_ object: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: object),
              let line = String(data: data, encoding: .utf8)
        else { return }
        print(line)
        fflush(stdout)
    }

    static func hello() {
        send(["type": "hello", "protocol": 1])
    }

    static func ready(sampleRate: Int, channels: Int) {
        send(["type": "ready", "sample_rate": sampleRate, "channels": channels])
    }

    static func chunk(seq: UInt64, sampleRate: Int, channels: Int, pcm16: String) {
        send([
            "type": "chunk",
            "seq": seq,
            "sample_rate": sampleRate,
            "channels": channels,
            "pcm16": pcm16,
        ])
    }

    static func stopped() {
        send(["type": "stopped"])
    }

    static func error(_ message: String) {
        send(["type": "error", "message": message])
    }
}
