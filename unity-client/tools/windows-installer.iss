; Inno Setup script for the Windows player. Built by tools/package_windows.ps1, which passes
; /DAppVersion=<version> and runs from the unity-client folder.
#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif

[Setup]
AppId={{6D0F6E43-3B1C-4C57-9E7D-5C0A5D8C2B11}
AppName=MoodSwings
AppVersion={#AppVersion}
AppPublisher=MoodSwings
; Installs for the current user only: no administrator prompt, and the uninstaller lives alongside it.
PrivilegesRequired=lowest
DefaultDirName={autopf}\MoodSwings
DefaultGroupName=MoodSwings
DisableProgramGroupPage=yes
UninstallDisplayIcon={app}\MoodSwings.exe
OutputDir=..\Build\Windows
OutputBaseFilename=MoodSwings-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern

[Files]
Source: "..\Build\Windows\MoodSwings\*"; DestDir: "{app}"; Excludes: "*_BackUpThisFolder_*"; Flags: recursesubdirs createallsubdirs ignoreversion

[Icons]
Name: "{group}\MoodSwings"; Filename: "{app}\MoodSwings.exe"
Name: "{autodesktop}\MoodSwings"; Filename: "{app}\MoodSwings.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; Flags: unchecked

[Run]
Filename: "{app}\MoodSwings.exe"; Description: "Start MoodSwings"; Flags: nowait postinstall skipifsilent
