# Generate the raster-image fixtures for tests/document_images.rs
# (Proposal 109 follow-up 1). Windows-only generator (System.Drawing) -
# the same trick as the SAPI-TTS speech fixtures. Run from PowerShell:
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/make_image_fixtures.ps1
# The output PNG/JPG are gitignored at the repo level (.git/info/exclude),
# so committing new versions needs `git add -f`.
Add-Type -AssemblyName System.Drawing

$fixtures = "F:/GITHUB/worktrees/mcp-ai-wpoos/doc-ocr-m0/mcp-ai-wpoos/examples/nvoos-dictate/src-tauri/tests/fixtures"
$bmp = New-Object System.Drawing.Bitmap(600, 200)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.Color]::White)
$font = New-Object System.Drawing.Font("Arial", 36)
$g.DrawString("IMAGE OCR FIXTURE 123", $font, [System.Drawing.Brushes]::Black, 20, 60)
$bmp.Save("$fixtures/image-text.png", [System.Drawing.Imaging.ImageFormat]::Png)
$bmp.Save("$fixtures/image-text.jpg", [System.Drawing.Imaging.ImageFormat]::Jpeg)
$g.Dispose()
$bmp.Dispose()

# broken.png: non-image bytes under an image extension - the clean-error fixture.
Copy-Item "$fixtures/malformed.pdf" "$fixtures/broken.png" -Force
Write-Output "fixtures written"
