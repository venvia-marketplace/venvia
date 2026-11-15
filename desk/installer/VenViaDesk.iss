; VenVia Desk diagnostic preview - no system changes
[Setup]
AppId=VenVia.Desk.DiagnosticPreview
AppName=VenVia Desk - Anteprima diagnostica
AppVersion=0.4.0-alpha.2
DefaultDirName={localappdata}\VenVia\DeskPreview
PrivilegesRequired=lowest
DisableProgramGroupPage=yes
Uninstallable=no
CreateAppDir=no
OutputDir=output
OutputBaseFilename=VenVia-Desk-Setup-0.4.0-alpha.2-preview
WizardStyle=modern
Compression=lzma2
SolidCompression=yes
[Languages]
Name: "italian"; MessagesFile: "compiler:Languages\Italian.isl"
[Code]
var
  ReportPage: TOutputMsgMemoWizardPage;
  Version: TWindowsVersion;
  FreeMB, TotalMB: Cardinal;
  Report: String;
procedure InitializeWizard;
begin
  GetWindowsVersionEx(Version);
  Report := 'Versione Windows: ' + IntToStr(Version.Major) + '.' +
    IntToStr(Version.Minor) + ' (build ' + IntToStr(Version.Build) + ')' + #13#10;
  if IsWin64 then
    Report := Report + 'Windows a 64 bit' + #13#10
  else
    Report := Report + 'Windows a 32 bit' + #13#10;
  GetSpaceOnDisk(ExpandConstant('{localappdata}'), True, FreeMB, TotalMB);
  Report := Report + 'Spazio disponibile (MB): ' + IntToStr(FreeMB) + #13#10 +
    'Spazio totale (MB): ' + IntToStr(TotalMB) + #13#10#13#10 +
    'Controlli RAM, servizi, porte e compatibilita: non ancora implementati.' + #13#10 +
    'Nessun servizio verra installato. Nessuno script PowerShell verra avviato.';
  ReportPage := CreateOutputMsgMemoPage(wpWelcome, 'Diagnostica del computer',
    'VenVia Desk 0.4 - Windows', 'Risultati preliminari (sola lettura):', Report);
end;
