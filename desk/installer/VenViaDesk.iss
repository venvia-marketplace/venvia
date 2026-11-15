; VenVia Desk diagnostic preview — not a server installer yet
[Setup]
AppId=VenVia.Desk.DiagnosticPreview
AppName=VenVia Desk - Anteprima diagnostica
AppVersion=0.4.0-alpha.1
DefaultDirName={localappdata}\VenVia\DeskPreview
PrivilegesRequired=lowest
DisableProgramGroupPage=yes
Uninstallable=no
CreateAppDir=no
OutputDir=output
OutputBaseFilename=VenVia-Desk-Setup-0.4.0-alpha.1-preview
WizardStyle=modern
Compression=lzma2
SolidCompression=yes
[Languages]
Name: "italian"; MessagesFile: "compiler:Languages\Italian.isl"
[Files]
Source: "preflight-windows.ps1"; DestDir: "{tmp}"; Flags: deleteafterinstall
Source: "install-windows.ps1"; DestDir: "{tmp}"; Flags: deleteafterinstall
[Code]
var
  ReportPage: TOutputMsgMemoWizardPage;
procedure InitializeWizard;
begin
  ReportPage := CreateOutputMsgMemoPage(wpWelcome, 'Anteprima diagnostica', 'VenVia Desk 0.4', 'Questa versione non installa PHP, MariaDB o altri servizi. Verranno controllate le risorse del computer.', 'Nessuna modifica ai servizi di sistema. Il report viene mostrato in una finestra separata.');
end;
[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -NoExit -File ""{tmp}\install-windows.ps1"" -Mode Plan"; Flags: waituntilterminated; StatusMsg: "Diagnostica del computer..."
