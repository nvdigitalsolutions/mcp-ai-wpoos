# Fetch + verify the pinned PDFium runtime for the document pipeline
# (Proposal 109, bundle-resource provisioning). Run once per machine, or in
# the publish workflow before bundling. The staged dll is gitignored — never
# committed. Requires Windows 10 1803+ (bsdtar) and network access.
#
# The sha256 pin is on the EXTRACTED dll (bin/pdfium.dll, 7,211,520 bytes)
# — the binary the pipeline was tested against — not on the tgz archive,
# which bblanchon may re-pack between releases.
$ErrorActionPreference = "Stop"

$Sha256 = "79d4676b656cfb1abcea88f9ade3b4b0826c5200382db5f4ec72a636c598c118"
$Url    = "https://github.com/bblanchon/pdfium-binaries/releases/download/chromium%2F7881/pdfium-win-x64.tgz"

$Root    = Split-Path -Parent $PSScriptRoot          # examples/nvoos-dictate
$DestDir = Join-Path $Root "src-tauri/resources"
$Tmp     = Join-Path ([System.IO.Path]::GetTempPath()) ("pdfium-win-x64-" + [Guid]::NewGuid().ToString("N") + ".tgz")
$Extract = Join-Path ([System.IO.Path]::GetTempPath()) ("pdfium-extract-" + [Guid]::NewGuid().ToString("N"))

New-Item -ItemType Directory -Force -Path $DestDir | Out-Null

Write-Output "Downloading $Url"
Invoke-WebRequest -Uri $Url -OutFile $Tmp -UseBasicParsing

# Windows ships bsdtar; the archive layout is bin/pdfium.dll (NOT lib/).
New-Item -ItemType Directory -Force -Path $Extract | Out-Null
# Explicit System32 path: an MSYS/Git-Bash PATH can shadow `tar` with
# GNU tar, which misreads `C:` temp paths as remote-host syntax.
& "$env:SystemRoot/System32/tar.exe" -xzf $Tmp -C $Extract bin/pdfium.dll
$DllPath = Join-Path $Extract "bin/pdfium.dll"

$Hash = (Get-FileHash -Algorithm SHA256 $DllPath).Hash.ToLowerInvariant()
if ($Hash -ne $Sha256) {
    Remove-Item $Tmp -Force -ErrorAction SilentlyContinue
    Remove-Item $Extract -Recurse -Force -ErrorAction SilentlyContinue
    throw "pdfium.dll sha256 mismatch: got $Hash, want $Sha256"
}
Write-Output "sha256 verified"

Copy-Item $DllPath (Join-Path $DestDir "pdfium.dll") -Force
Remove-Item $Tmp -Force
Remove-Item $Extract -Recurse -Force

$Dll = Get-Item (Join-Path $DestDir "pdfium.dll")
Write-Output ("pdfium.dll staged at " + $Dll.FullName + " (" + $Dll.Length + " bytes)")
