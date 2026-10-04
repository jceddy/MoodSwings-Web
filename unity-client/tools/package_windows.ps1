# Packages the Windows player (Build/Windows/MoodSwings, from MoodSwings.Editor.WindowsBuild.Build)
# for handing to testers: always a zip, and a MoodSwings-Setup-<version>.exe installer too when
# Inno Setup 6 (https://jrsoftware.org/isinfo.php) is installed.
#
#   pwsh tools/package_windows.ps1 [-Version 1.0.0]
param([string]$Version = "")

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$folder = "Build/Windows/MoodSwings"
if (-not (Test-Path "$folder/MoodSwings.exe")) {
    throw "No build at $folder -- build it first (MoodSwings > Build Windows Player, or the -executeMethod in README.md)."
}

if (-not $Version) {
    $Version = (Select-String -Path "ProjectSettings/ProjectSettings.asset" -Pattern "^\s*bundleVersion:\s*(.+)$").Matches[0].Groups[1].Value.Trim()
}

$zip = "Build/Windows/MoodSwings-$Version-win64.zip"
if (Test-Path $zip) { Remove-Item $zip }
# The "BackUpThisFolder" folder holds debug symbols for crash reports; it isn't for testers.
$items = Get-ChildItem $folder | Where-Object { $_.Name -notlike "*_BackUpThisFolder_*" }
Compress-Archive -Path $items.FullName -DestinationPath $zip
Write-Host "Zip:       $zip"

$iscc = @(
    (Get-Command iscc -ErrorAction SilentlyContinue).Source,
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

if ($iscc) {
    & $iscc "/DAppVersion=$Version" "tools/windows-installer.iss"
    if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed." }
    Write-Host "Installer: Build/Windows/MoodSwings-Setup-$Version.exe"
} else {
    Write-Host "Installer: skipped (Inno Setup 6 isn't installed; testers can unzip and run MoodSwings.exe)."
}
