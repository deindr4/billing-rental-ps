; Billing Rental PS — installer Windows (Inno Setup 6). Dibangun oleh installer\build.ps1:
;   ISCC.exe /DVersi=2026.10.01 /DStaging=<folder staging> /DKeluaran=<folder hasil> setup.iss
; Pertama kali: wizard data rental & owner -> kelola\pasang.ps1
; Sudah terpasang: update -> kelola\perbarui.ps1 (backup, migrasi, data & pengaturan tetap)

#ifndef Versi
  #define Versi "0.0.0"
#endif
#ifndef Staging
  #define Staging "staging"
#endif
#ifndef Keluaran
  #define Keluaran "keluaran"
#endif

#define AppId "{{6E5B7C1A-4D2F-4B8E-9A31-B1C2D3E4F501}"

[Setup]
AppId={#AppId}
AppName=Billing Rental PS
AppVersion={#Versi}
AppVerName=Billing Rental PS {#Versi}
AppPublisher=Billing Rental PS
DefaultDirName={sd}\BillingPS
DefaultGroupName=Billing PS
DisableProgramGroupPage=yes
UsePreviousAppDir=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir={#Keluaran}
OutputBaseFilename=BillingPS-Setup-{#Versi}
SetupIconFile={#Staging}\ikon.ico
UninstallDisplayIcon={app}\app\public\favicon.ico
UninstallDisplayName=Billing Rental PS
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
CloseApplications=no
RestartIfNeededByRun=no

[Languages]
Name: "id"; MessagesFile: "Indonesian.isl"

[Files]
Source: "{#Staging}\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Staging}\runtime\*"; DestDir: "{app}\runtime"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Staging}\kelola\*"; DestDir: "{app}\kelola"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Staging}\apk\*"; DestDir: "{app}\apk"; Flags: ignoreversion
Source: "{#Staging}\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall

; Update: bersihkan kode lama supaya file yang sudah dihapus di versi baru tidak tertinggal
; (.env tetap; data ada di folder data, tidak tersentuh)
[InstallDelete]
Type: filesandordirs; Name: "{app}\app\app"
Type: filesandordirs; Name: "{app}\app\bootstrap\cache"
Type: filesandordirs; Name: "{app}\app\config"
Type: filesandordirs; Name: "{app}\app\database"
Type: filesandordirs; Name: "{app}\app\resources"
Type: filesandordirs; Name: "{app}\app\routes"
Type: filesandordirs; Name: "{app}\app\vendor"
Type: filesandordirs; Name: "{app}\app\public\build"

[Dirs]
Name: "{app}\data"; Flags: uninsneveruninstall
Name: "{app}\logs"; Flags: uninsneveruninstall

[Icons]
Name: "{group}\Billing PS"; Filename: "{app}\Billing PS.url"; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Billing PS - Panel Admin"; Filename: "{app}\Billing PS - Admin.url"; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Kelola layanan Billing PS"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\kelola\layanan.ps1"""; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Folder log Billing PS"; Filename: "{app}\logs"
Name: "{group}\Uninstall Billing PS"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Billing PS"; Filename: "{app}\Billing PS.url"; IconFilename: "{app}\app\public\favicon.ico"

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\kelola\copot.ps1"""; Flags: runhidden waituntilterminated; RunOnceId: "CopotLayanan"

[Code]
var
  HalRental, HalOwner, HalPort: TInputQueryWizardPage;
  HalZona: TInputOptionWizardPage;
  ModeUpdate: Boolean;
  HasilPasang: String;

function KunciUninstall: String;
begin
  Result := 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{#AppId}_is1';
  StringChangeEx(Result, '{{', '{', True);
end;

{ Sudah pernah dipasang (ada konfigurasi pemasangan) -> mode update tanpa wizard isian }
function SudahTerpasang: Boolean;
var
  Lokasi: String;
begin
  Result := RegQueryStringValue(HKLM, KunciUninstall, 'InstallLocation', Lokasi)
    and FileExists(AddBackslash(Lokasi) + 'kelola\konfigurasi.json');
end;

procedure InitializeWizard;
begin
  ModeUpdate := SudahTerpasang;

  HalRental := CreateInputQueryPage(wpSelectDir, 'Data rental',
    'Nama rental & cabang yang tampil di aplikasi, struk, TV dan billboard.', '');
  HalRental.Add('Nama rental:', False);
  HalRental.Add('Nama cabang:', False);
  HalRental.Values[1] := 'Cabang Utama';

  HalZona := CreateInputOptionPage(HalRental.ID, 'Zona waktu',
    'Zona waktu lokasi rental (jam di aplikasi, struk & TV).', '', True, False);
  HalZona.Add('WIB  (Asia/Jakarta)  - Sumatra, Jawa, Kalimantan Barat & Tengah');
  HalZona.Add('WITA (Asia/Makassar) - Bali, NTB, NTT, Sulawesi, Kalimantan Timur & Selatan');
  HalZona.Add('WIT  (Asia/Jayapura) - Maluku, Papua');
  HalZona.SelectedValueIndex := 0;

  HalOwner := CreateInputQueryPage(HalZona.ID, 'Akun owner',
    'Akun pemilik rental untuk login aplikasi kasir & panel admin.',
    'Password minimal 8 karakter. PIN 4-6 angka dipakai untuk menyetujui aksi penting (batal transaksi, unlock TV).');
  HalOwner.Add('Nama owner:', False);
  HalOwner.Add('Email login:', False);
  HalOwner.Add('Password:', True);
  HalOwner.Add('Ulangi password:', True);
  HalOwner.Add('PIN (4-6 angka):', True);

  HalPort := CreateInputQueryPage(HalOwner.ID, 'Port web',
    'Alamat aplikasi di jaringan rental.',
    'Biarkan 80 (alamat cukup http://IP-PC). Ganti hanya bila port 80 sudah dipakai program lain.');
  HalPort.Add('Port web:', False);
  HalPort.Values[0] := '80';
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := ModeUpdate and ((PageID = HalRental.ID) or (PageID = HalZona.ID) or (PageID = HalOwner.ID) or (PageID = HalPort.ID));
end;

function SemuaAngka(S: String): Boolean;
var
  I: Integer;
begin
  Result := Length(S) > 0;
  for I := 1 to Length(S) do
    if (S[I] < '0') or (S[I] > '9') then Result := False;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Email: String;
begin
  Result := True;

  if CurPageID = HalRental.ID then
    if Length(Trim(HalRental.Values[0])) < 3 then begin
      MsgBox('Isi nama rental (minimal 3 huruf).', mbError, MB_OK);
      Result := False;
    end;

  if CurPageID = HalOwner.ID then begin
    Email := Trim(HalOwner.Values[1]);
    if (Pos('@', Email) < 2) or (Pos('.', Email) = 0) then begin
      MsgBox('Email login tidak valid.', mbError, MB_OK); Result := False;
    end else if Length(HalOwner.Values[2]) < 8 then begin
      MsgBox('Password minimal 8 karakter.', mbError, MB_OK); Result := False;
    end else if HalOwner.Values[2] <> HalOwner.Values[3] then begin
      MsgBox('Ulangi password tidak sama.', mbError, MB_OK); Result := False;
    end else if not SemuaAngka(HalOwner.Values[4]) or (Length(HalOwner.Values[4]) < 4) or (Length(HalOwner.Values[4]) > 6) then begin
      MsgBox('PIN harus 4-6 angka.', mbError, MB_OK); Result := False;
    end;
  end;

  if CurPageID = HalPort.ID then
    if not SemuaAngka(HalPort.Values[0]) or (StrToIntDef(HalPort.Values[0], 0) < 1) or (StrToIntDef(HalPort.Values[0], 0) > 65535) then begin
      MsgBox('Port harus angka 1-65535.', mbError, MB_OK); Result := False;
    end;
end;

function Json(S: String): String;
begin
  Result := S;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
  Result := '"' + Result + '"';
end;

function Zona: String;
begin
  case HalZona.SelectedValueIndex of
    1: Result := 'Asia/Makassar';
    2: Result := 'Asia/Jayapura';
  else
    Result := 'Asia/Jakarta';
  end;
end;

function PowerShell(Skrip, Argumen: String): Integer;
var
  Kode: Integer;
begin
  if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
    '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\kelola\') + Skrip + '" ' + Argumen,
    ExpandConstant('{app}\kelola'), SW_HIDE, ewWaitUntilTerminated, Kode) then
    Kode := -1;
  Result := Kode;
end;

function BacaHasil: String;
var
  Isi: AnsiString;
begin
  if LoadStringFromFile(ExpandConstant('{app}\logs\hasil-pasang.txt'), Isi) then
    Result := String(Isi)
  else
    Result := '';
end;

{ Update: backup database & hentikan layanan sebelum file diganti (memakai skrip versi lama) }
function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := '';
  if ModeUpdate and FileExists(ExpandConstant('{app}\kelola\perbarui.ps1')) then begin
    WizardForm.PreparingLabel.Caption := 'Backup database & menghentikan layanan...';
    if PowerShell('perbarui.ps1', '-Tahap sebelum') <> 0 then
      Result := 'Gagal menyiapkan update (backup / hentikan layanan). Lihat log di folder ' + ExpandConstant('{app}\logs');
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Isian, Berkas: String;
  Kode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;

  WizardForm.StatusLabel.Caption := 'Menyiapkan database, aplikasi & layanan (beberapa menit)...';
  WizardForm.ProgressGauge.Style := npbstMarquee;

  if ModeUpdate then
    Kode := PowerShell('perbarui.ps1', '-Tahap sesudah')
  else begin
    Isian := '{' +
      '"rental":' + Json(Trim(HalRental.Values[0])) + ',' +
      '"cabang":' + Json(Trim(HalRental.Values[1])) + ',' +
      '"zona":' + Json(Zona) + ',' +
      '"owner_nama":' + Json(Trim(HalOwner.Values[0])) + ',' +
      '"owner_email":' + Json(Trim(HalOwner.Values[1])) + ',' +
      '"owner_password":' + Json(HalOwner.Values[2]) + ',' +
      '"pin":' + Json(HalOwner.Values[4]) + ',' +
      '"port_web":' + Json(Trim(HalPort.Values[0])) + ',' +
      '"vc_redist":' + Json(ExpandConstant('{tmp}\vc_redist.x64.exe')) +
      '}';
    Berkas := ExpandConstant('{tmp}\isian.json');
    SaveStringToFile(Berkas, Isian, False);
    Kode := PowerShell('pasang.ps1', '-Isian "' + Berkas + '"');
    DeleteFile(Berkas); { berisi password owner }
  end;

  WizardForm.ProgressGauge.Style := npbstNormal;
  HasilPasang := BacaHasil;

  if Kode <> 0 then
    MsgBox('Pemasangan belum selesai:' + #13#10 + HasilPasang + #13#10#13#10 +
      'Log lengkap ada di folder ' + ExpandConstant('{app}\logs') + '. Jalankan installer ini lagi setelah masalah diperbaiki.',
      mbError, MB_OK);
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if (CurPageID = wpFinished) and (HasilPasang <> '') then
    WizardForm.FinishedLabel.Caption := HasilPasang + #13#10#13#10 +
      'Buka aplikasi dari ikon "Billing PS" di Desktop. Kelola layanan lewat menu Start > Billing PS.';
end;

{ Uninstall: tanya apakah data (database, foto, backup) ikut dihapus }
procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  Root: String;
begin
  if CurUninstallStep <> usPostUninstall then Exit;
  Root := ExpandConstant('{app}');

  if MsgBox('Hapus juga SEMUA DATA Billing PS?' + #13#10#13#10 +
    '- Database (transaksi, member, laporan)' + #13#10 +
    '- Foto, logo & file backup' + #13#10#13#10 +
    'Pilih "No" untuk menyimpan data di ' + Root + '\data supaya bisa dipakai lagi saat pasang ulang.',
    mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then begin
    DelTree(Root + '\data', True, True, True);
    DelTree(Root + '\logs', True, True, True);
    DelTree(Root + '\kelola', True, True, True);
    DelTree(Root + '\app', True, True, True);
    DelTree(Root + '\runtime', True, True, True);
    RemoveDir(Root);
  end;
end;
