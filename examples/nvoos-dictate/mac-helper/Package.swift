// swift-tools-version:5.9
// The macOS system-audio sidecar for the NV oOS Dictation app (P5 port).
// Build: swift build -c release --package-path mac-helper
import PackageDescription

let package = Package(
    name: "nvoos-audio-helper",
    platforms: [
        .macOS(.v13)
    ],
    targets: [
        .executableTarget(
            name: "nvoos-audio-helper",
            path: "Sources/nvoos-audio-helper"
        )
    ]
)
