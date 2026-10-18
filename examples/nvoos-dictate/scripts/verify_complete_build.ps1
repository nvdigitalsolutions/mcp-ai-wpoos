# Verify the "complete" nvoos-dictate.exe feature union.
# Scans the PE binary for feature markers and reports counts.
param(
    [string]$Exe = "src-tauri\target\debug\nvoos-dictate.exe"
)

$bytes = [System.IO.File]::ReadAllBytes((Resolve-Path $Exe))
$text  = [System.Text.Encoding]::ASCII.GetString($bytes)

$markers = @(
    @{ name = "parakeet";        re = "parakeet" },
    @{ name = "fastconformer";   re = "fastconformer" },
    @{ name = "whisper";         re = "whisper" },
    @{ name = "sherpa-onnx";     re = "sherpa-onnx" },
    @{ name = "silero-vad";      re = "silero-vad" },
    @{ name = "vision (YOLO)";   re = "yolo" },
    @{ name = "vision (ort)";    re = "ort" },
    @{ name = "camera (nokhwa)"; re = "nokhwa" },
    @{ name = "agent";           re = "nvoos-agent" },
    @{ name = "mcp";             re = "mcp" },
    @{ name = "Assistant SPA";   re = "Assistant" },
    @{ name = "moonshine (should be ABSENT)"; re = "moonshine" }
)

Write-Output ("Exe size: {0:N1} MB" -f ($bytes.Length / 1MB))
Write-Output ("Exe path: {0}" -f (Resolve-Path $Exe))
Write-Output "--- marker scan ---"
foreach ($m in $markers) {
    $count = ([regex]::Matches($text, $m.re, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)).Count
    Write-Output ("{0,-36} {1,5}  {2}" -f $m.name, $count, $(if ($count -gt 0) { "PRESENT" } else { "absent" }))
}
