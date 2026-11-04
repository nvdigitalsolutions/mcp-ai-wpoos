# Builds the 32-bit exes and the installer. Run from the repo root in Windows:
#   powershell -ExecutionPolicy Bypass -File installer\build_win32.ps1 -Python C:\py32\python.exe -Iscc "C:\inno\ISCC.exe"
param([string]$Python = 'C:\py32\python.exe', [string]$Iscc = '')
$ErrorActionPreference = 'Stop'
# a failing native command does not stop the script by itself
function Run { & $args[0] @($args | Select-Object -Skip 1); if ($LASTEXITCODE -ne 0) { throw "failed ($LASTEXITCODE): $args" } }
$bits = & $Python -c "import struct; print(struct.calcsize('P') * 8)"
if ($bits -ne '32') { throw "Python at $Python is $bits-bit; QuickBooks 2016 needs a 32-bit build." }
Run $Python -m pip install --disable-pip-version-check -q -r installer\requirements-win32.txt
Push-Location installer
try {
    Run $Python -m PyInstaller --noconfirm --onefile --name qbbridge-mcp --paths ..\src `
        --hidden-import win32timezone --hidden-import win32com --collect-submodules win32com entrypoint.py
    Run $Python -m PyInstaller --noconfirm --onefile --name configure_claude_desktop configure_claude_desktop.py
    Run $Python build_skill_zip.py
    if ($Iscc) { Run $Iscc qbbridge_setup.iss }
} finally { Pop-Location }
