; Inno Setup script for the Windows player. Built by tools/package_windows.ps1, which passes
; /DAppVersion=<version> and runs from the unity-client folder.
#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif

[Setup]
AppId={{6D0F6E43-3B1C-4C57-9E7D-5C0A5D8C2B11}
AppName=MOOD
AppVersion={#AppVersion}
AppPublisher=MoodSwings
; Installs for the current user only: no administrator prompt, and the uninstaller lives alongside it.
PrivilegesRequired=lowest
DefaultDirName={autopf}\MOOD
DefaultGroupName=MOOD
DisableProgramGroupPage=yes
UninstallDisplayIcon={app}\MOOD.exe
OutputDir=..\Build\Windows
OutputBaseFilename=MOOD-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern

[Files]
Source: "..\Build\Windows\MOOD\*"; DestDir: "{app}"; Excludes: "*_BackUpThisFolder_*"; Flags: recursesubdirs createallsubdirs ignoreversion

[Icons]
Name: "{group}\MOOD"; Filename: "{app}\MOOD.exe"
Name: "{autodesktop}\MOOD"; Filename: "{app}\MOOD.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; Flags: unchecked

[Run]
Filename: "{app}\MOOD.exe"; Description: "Start MOOD"; Flags: nowait postinstall skipifsilent
