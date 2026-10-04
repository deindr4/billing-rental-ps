# Billing PS - terbitkan rilis di GitHub (dipakai fitur "Update aplikasi" di Admin > Pemeliharaan sistem).
#   powershell -ExecutionPolicy Bypass -File installer\rilis.ps1 [-Versi 2026.10.04] [-Repo pemilik/nama]
# Syarat: build.ps1 (exe) & build.ps1 -PaketCloud (tar.gz) sudah dijalankan untuk versi ini, kode sudah di-push.
# Token: dari Git Credential Manager (akun GitHub yang login di git), tidak ditampilkan / disimpan.
# Catatan rilis: entri changelogbill.txt & changelog.txt (APK) bertanggal versi ini.
param(
    [string] $Versi = '',
    [string] $Repo = 'deindr4/billing-rental-ps',
    [switch] $Uji  # hanya tampilkan catatan rilis, tanpa menghubungi GitHub
)

$ErrorActionPreference = 'Stop'
$Akar = Split-Path -Parent $PSScriptRoot
if (-not $Versi) { $Versi = (Get-Content (Join-Path $Akar 'VERSION') -Raw).Trim() }
$Tag = "v$Versi"
$Keluaran = Join-Path $PSScriptRoot 'keluaran'

$aset = @(
    (Join-Path $Keluaran "BillingPS-Setup-$Versi.exe"),
    (Join-Path $Keluaran "BillingPS-cloud-$Versi.tar.gz")
)
foreach ($f in $aset) { if (-not (Test-Path $f)) { throw "File belum dibuat: $f (jalankan build.ps1 dulu)" } }

# APK TV ikut dilampirkan dengan nama berversi
$gradle = Get-Content (Join-Path $Akar 'tv-agent\app\build.gradle.kts') -Raw
$versiApk = [regex]::Match($gradle, 'versionName\s*=\s*"([^"]+)"').Groups[1].Value
$apk = Join-Path $Akar 'tv-agent\app\build\outputs\apk\debug\app-debug.apk'
if (Test-Path $apk) {
    $apkRilis = Join-Path $env:TEMP "tv-agent-$versiApk.apk"
    Copy-Item $apk $apkRilis -Force
    $aset += $apkRilis
}

# Catatan rilis dari changelog: blok yang diawali tanggal versi (YYYY-MM-DD)
$tanggal = ($Versi -split '\.')[0..2] -join '-'
function Ambil-Catatan([string] $File, [string] $Pola) {
    $isi = [IO.File]::ReadAllText($File)
    $blok = [regex]::Matches($isi, "(?ms)^$Pola.*?(?=^\S[^\r\n]*\r?\n-{10,}|\z)")
    return ($blok | ForEach-Object { $_.Value.Trim() }) -join "`n`n"
}
$catatanBill = Ambil-Catatan (Join-Path $Akar 'docs\changelogbill.txt') ([regex]::Escape($tanggal) + ' \(')
$catatanApk = Ambil-Catatan (Join-Path $Akar 'docs\changelog.txt') ('\S+ \(kode \d+\) - ' + [regex]::Escape($tanggal))
$body = "## Aplikasi billing`n`n``````text`n$catatanBill`n```````n"
if ($catatanApk) { $body += "`n## APK TV $versiApk`n`n``````text`n$catatanApk`n```````n" }
$body += "`n## File`n- **BillingPS-Setup-$Versi.exe** - PC rental Windows (pasang baru / update otomatis)`n" +
    "- **BillingPS-cloud-$Versi.tar.gz** - server cloud / CloudPanel (lihat docs/cloudpanel.md)`n" +
    "- **tv-agent-$versiApk.apk** - unggah di Admin > Rilis APK lalu Push update ke TV`n"

if ($Uji) { Write-Host $body; Write-Host "Aset:"; $aset | ForEach-Object { Write-Host "  $_" }; exit 0 }

# Token GitHub dari Git Credential Manager
# (baris per baris; stderr git di PS 5.1 + ErrorAction Stop dianggap error, jadi sementara Continue)
# Masukan lewat file ber-akhiran LF: pipa PowerShell mengirim CRLF yang ditolak git ("missing protocol field")
$masukan = Join-Path $env:TEMP 'billingps-kred.txt'
[IO.File]::WriteAllText($masukan, "protocol=https`nhost=github.com`n`n")
$ErrorActionPreference = 'Continue'
$kred = & cmd.exe /c "git credential fill < `"$masukan`" 2>NUL"
$ErrorActionPreference = 'Stop'
Remove-Item $masukan -Force
$token = ($kred | Where-Object { $_ -like 'password=*' }) -replace '^password=', ''
if (-not $token) { throw 'Akun GitHub belum login di git (Git Credential Manager).' }
$h = @{ Authorization = "Bearer $token"; Accept = 'application/vnd.github+json'; 'User-Agent' = 'BillingPS-Rilis' }

$ada = $null
try { $ada = Invoke-RestMethod -Headers $h "https://api.github.com/repos/$Repo/releases/tags/$Tag" } catch { }
if ($ada) { throw "Rilis $Tag sudah ada: $($ada.html_url). Naikkan VERSION (mis. $Versi.1) untuk rilis baru." }

$data = @{ tag_name = $Tag; target_commitish = (git -C $Akar rev-parse HEAD).Trim(); name = "Billing PS $Versi"; body = $body } | ConvertTo-Json
$rilis = Invoke-RestMethod -Method Post -Headers $h -ContentType 'application/json; charset=utf-8' `
    -Body ([Text.Encoding]::UTF8.GetBytes($data)) "https://api.github.com/repos/$Repo/releases"
Write-Host "Rilis dibuat: $($rilis.html_url)"

foreach ($f in $aset) {
    $nama = [IO.Path]::GetFileName($f)
    Write-Host ("Mengunggah {0} ({1:N0} MB)..." -f $nama, ((Get-Item $f).Length / 1MB))
    # curl.exe: unggah file besar tanpa memuat ke memori PowerShell
    & curl.exe -sS --fail -X POST -H "Authorization: Bearer $token" -H 'Content-Type: application/octet-stream' `
        --data-binary "@$f" "https://uploads.github.com/repos/$Repo/releases/$($rilis.id)/assets?name=$nama" -o NUL
    if ($LASTEXITCODE -ne 0) { throw "Unggah $nama gagal" }
}
Write-Host "Selesai: $($rilis.html_url)"
