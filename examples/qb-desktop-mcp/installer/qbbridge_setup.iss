; Inno Setup script for QBBridge.
;
; Build order (see installer/README.md):
;   1. PyInstaller builds dist/qbbridge-mcp.exe and dist/configure_claude_desktop.exe
;   2. build_skill_zip.py packages skills/qbbridge-bookkeeping/ into a
;      ready-to-upload zip (Claude's Skills upload expects a zip whose top
;      level is the skill's own folder, not SKILL.md at the zip root).
;   3. This script packages everything into one installer. It auto-registers
;      the MCP server with Claude Desktop's config (no hand-editing JSON),
;      but CANNOT auto-install the skill itself -- confirmed there is no
;      documented file-location, API, CLI, or URI-scheme path for that;
;      Claude Desktop requires a manual upload through its own UI for
;      anything that shapes its behavior. NEXT_STEPS.txt, opened
;      automatically at the end of setup, walks the user through that one
;      remaining manual step with the exact file path to upload.

#define MyAppName "QBBridge"
#define MyAppVersion "0.2.0"
#define MyAppPublisher "QBBridge"
#define MyAppURL "https://github.com/lilfroog/qbbridge"
#define MyAppExeName "qbbridge-mcp.exe"

[Setup]
AppId={{5B0663E4-AE4C-4107-8B6F-68EF6115B16D}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
DefaultDirName={autopf}\QBBridge
DefaultGroupName=QBBridge
DisableProgramGroupPage=yes
; NOTE: this installer is not yet code-signed. On first run, Windows
; SmartScreen will show an "Unknown publisher" warning. Get a code-signing
; certificate and sign both the PyInstaller exes and this installer output
; before distributing broadly -- see installer/README.md.
OutputBaseFilename=QBBridgeSetup
Compression=lzma
SolidCompression=yes
WizardStyle=modern
; 32-bit on purpose: qbbridge-mcp.exe is a 32-bit (x86) build, because
; QBXMLRP2 of 32-bit QuickBooks (2021 and older, e.g. Pro 2016) only works
; from a 32-bit process. No ArchitecturesInstallIn64BitMode: installs into
; Program Files (x86) on 64-bit Windows; runs on x86, x64 and ARM64 Windows.

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "dist\qbbridge-mcp.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "dist\configure_claude_desktop.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\skills\qbbridge-bookkeeping\SKILL.md"; DestDir: "{app}\skill"; Flags: ignoreversion
Source: "qbbridge-bookkeeping.zip"; DestDir: "{app}\skill"; Flags: ignoreversion
Source: "NEXT_STEPS.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\README.md"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\QBBridge Skill Folder"; Filename: "{app}\skill"
Name: "{group}\Next Steps"; Filename: "{app}\NEXT_STEPS.txt"
Name: "{group}\Uninstall QBBridge"; Filename: "{uninstallexe}"

[Run]
; Runs automatically once, right after files are copied -- this is what
; replaces "hand-edit a JSON config file" with "click Next a few times."
Filename: "{app}\configure_claude_desktop.exe"; Parameters: """{app}\{#MyAppExeName}"""; \
    Description: "Register QBBridge with Claude Desktop"; Flags: runasoriginaluser waituntilterminated
; Opens the one remaining manual step (uploading the skill zip) with exact
; instructions, right after Finish -- this is as close to automatic as
; Claude Desktop's design allows for anything that shapes its behavior.
Filename: "{app}\NEXT_STEPS.txt"; Description: "View the one remaining setup step (adding the QBBridge skill)"; \
    Flags: postinstall shellexec skipifsilent

[UninstallDelete]
; Best-effort cleanup; does not touch the user's other MCP server entries.
; The "qbbridge" entry in claude_desktop_config.json is left in place
; (removing it is not automated; delete it by hand if wanted).
Type: files; Name: "{app}\*.exe"
