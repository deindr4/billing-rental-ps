# Billing PS — bangun installer .exe (jalankan di PC pengembang, folder repo):
#   powershell -ExecutionPolicy Bypass -File installer\build.ps1 [-Versi 2026.10.01] [-LewatiAset]
# Hasil: installer\keluaran\BillingPS-Setup-<versi>.exe
#
# Bahan (lihat installer\bahan.json):
#   php     : PHP 8.4 Thread Safe (punya php8apache2_4.dll) — disalin dari folder lokal (Laragon)
#   apache, mariadb, nssm, vc_redist, cacert : diunduh sekali ke installer\bahan\unduhan
#   (Apache Lounge VS18 butuh VC++ runtime 14.50+ → vc_redist dari aka.ms/vc14)
param(
    [string] $Versi = (Get-Date -Format 'yyyy.MM.dd'),
    [switch] $LewatiAset,  # lewati npm run build (aset sudah dibangun)
    [switch] $PaketCloud   # hanya aplikasi -> keluaran\BillingPS-cloud-<versi>.tar.gz (VPS / CloudPanel)
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Installer = $PSScriptRoot
$Repo = Split-Path -Parent $Installer
$Bahan = Get-Content (Join-Path $Installer 'bahan.json') -Raw | ConvertFrom-Json
$Unduhan = Join-Path $Installer 'bahan\unduhan'
$Staging = Join-Path $Installer 'staging'
$Keluaran = Join-Path $Installer 'keluaran'

function Tulis([string] $p) { Write-Host ("[{0:HH:mm:ss}] {1}" -f (Get-Date), $p) -ForegroundColor Cyan }

function Unduh([string] $Url, [string] $Nama) {
    $tujuan = Join-Path $Unduhan $Nama
    if (-not (Test-Path $tujuan)) {
        Tulis "Mengunduh $Nama..."
        New-Item -ItemType Directory -Force -Path $Unduhan | Out-Null
        & curl.exe -sSLf -A 'Mozilla/5.0' --retry 3 -o $tujuan $Url   # Apache Lounge menolak tanpa user-agent
        if ($LASTEXITCODE -ne 0) { Remove-Item -Force -ErrorAction SilentlyContinue $tujuan; throw "Gagal mengunduh $Url" }
    }
    return $tujuan
}

# Ekstrak zip lewat .NET (Expand-Archive PS 5.1 bisa >20 menit untuk ~1000 file); folder setengah jadi diulang
function Ekstrak([string] $Zip, [string] $Ke) {
    $tanda = Join-Path $Ke '.selesai'
    if (Test-Path $tanda) { return }
    if (Test-Path $Ke) { Remove-Item -Recurse -Force $Ke }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($Zip, $Ke)
    New-Item -ItemType File -Path $tanda | Out-Null
}

function Salin([string] $Dari, [string] $Ke, [string[]] $KecualiFolder = @(), [string[]] $KecualiFile = @()) {
    $arg = @($Dari, $Ke, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    if ($KecualiFolder.Count) { $arg += '/XD'; $arg += $KecualiFolder }
    if ($KecualiFile.Count) { $arg += '/XF'; $arg += $KecualiFile }
    & robocopy @arg | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy $Dari -> $Ke gagal (kode $LASTEXITCODE)" }
}

# ---------------- Persiapan ----------------
$iscc = @("$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe", "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe") |
    Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'Inno Setup 6 belum terpasang (ISCC.exe tidak ditemukan).' }
if (-not (Test-Path $Bahan.php)) { throw "Folder PHP tidak ada: $($Bahan.php) (atur di installer\bahan.json)" }
if (-not (Test-Path (Join-Path $Bahan.php 'php8apache2_4.dll'))) { throw 'PHP harus versi Thread Safe (php8apache2_4.dll tidak ada).' }

$kotor = & git -C $Repo status --porcelain
if ($kotor) { Write-Warning 'Ada perubahan yang belum di-commit; ikut masuk installer.' }

if (Test-Path $Staging) { Remove-Item -Recurse -Force $Staging }
New-Item -ItemType Directory -Force -Path $Staging, $Keluaran | Out-Null

# ---------------- 1. Aplikasi ----------------
if (-not $LewatiAset) {
    Tulis 'Membangun aset (npm run build)...'
    Push-Location $Repo
    & cmd.exe /c 'npm run build 2>&1' | Out-Null   # lewat cmd: pembungkus npm.ps1 & stderr bermasalah di Windows PowerShell 5.1
    if ($LASTEXITCODE -ne 0) { Pop-Location; throw 'npm run build gagal' }
    Pop-Location
}

Tulis 'Menyalin aplikasi...'
$app = Join-Path $Staging 'app'
$kecuali = '^(tests|tv-agent|installer|docs|whatsapp-service|storage|node_modules|\.github)/|^(phpunit\.xml|\.env(?!\.example$).*|\.editorconfig|\.gitattributes|package(-lock)?\.json|vite\.config\.js)$'
$daftar = & git -C $Repo ls-files --cached --others --exclude-standard | Where-Object { $_ -notmatch $kecuali }
foreach ($f in $daftar) {
    $sumber = Join-Path $Repo $f
    if (-not (Test-Path $sumber -PathType Leaf)) { continue }   # file terhapus tapi belum di-commit
    $tujuan = Join-Path $app $f
    New-Item -ItemType Directory -Force -Path (Split-Path $tujuan) | Out-Null
    Copy-Item $sumber $tujuan
}
Salin (Join-Path $Repo 'public\build') (Join-Path $app 'public\build')
if (Test-Path (Join-Path $app 'public\hot')) { Remove-Item (Join-Path $app 'public\hot') }

Tulis 'composer install --no-dev...'
# Folder storage sementara supaya artisan bisa jalan saat composer (data asli di folder data PC rental)
foreach ($d in @('storage\framework\cache\data', 'storage\framework\views', 'storage\framework\sessions', 'storage\logs')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $app $d) | Out-Null
}
# vendor repo disalin dulu: composer cukup membuang paket dev (ekstraksi massal sering dikunci antivirus/indexer)
Salin (Join-Path $Repo 'vendor') (Join-Path $app 'vendor')
Push-Location $app
for ($coba = 1; $coba -le 3; $coba++) {
    # lewat cmd: composer menulis progres ke stderr, yang di PowerShell 5.1 + ErrorAction Stop dianggap error
    & cmd.exe /c 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1' | Out-Host
    $kode = $LASTEXITCODE
    if ($kode -eq 0) { break }
    Write-Warning "composer gagal (percobaan $coba), diulang..."
    Start-Sleep -Seconds 5
}
Pop-Location
if ($kode -ne 0) { throw 'composer install gagal' }
Remove-Item -Recurse -Force (Join-Path $app 'storage')
Get-ChildItem (Join-Path $app 'bootstrap\cache') -Filter '*.php' | Remove-Item -Force

if ($PaketCloud) {
    # Kerangka storage kosong (di PC rental storage ada di folder data; di VPS di dalam folder aplikasi)
    foreach ($d in @('storage\app\public', 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $app $d) | Out-Null
        [IO.File]::WriteAllText((Join-Path $app "$d\.gitignore"), "*`n!.gitignore`n")
    }
    if (Test-Path (Join-Path $app 'public\storage')) { Remove-Item -Recurse -Force (Join-Path $app 'public\storage') }
    $tar = Join-Path $Keluaran "BillingPS-cloud-$Versi.tar.gz"
    if (Test-Path $tar) { Remove-Item -Force $tar }
    # tar.exe bawaan Windows 10+: jalur pakai "/" (Compress-Archive PS 5.1 memakai "\" yang rusak di Linux)
    & tar.exe -czf $tar -C $app .
    if ($LASTEXITCODE -ne 0) { throw 'Membuat paket cloud gagal' }
    Tulis ("Selesai: {0} ({1:N0} MB)" -f $tar, ((Get-Item $tar).Length / 1MB))
    exit 0
}

# ---------------- 2. Runtime ----------------
$runtime = Join-Path $Staging 'runtime'
Tulis 'Menyalin PHP...'
Salin $Bahan.php (Join-Path $runtime 'php') @('logs', 'tmp', 'sessions') @('php.ini', 'php.ini-development')
$cacert = Unduh $Bahan.cacert 'cacert.pem'
New-Item -ItemType Directory -Force -Path (Join-Path $runtime 'php\extras\ssl') | Out-Null
Copy-Item $cacert (Join-Path $runtime 'php\extras\ssl\cacert.pem')

Tulis 'Menyalin Apache...'
$apacheZip = Unduh $Bahan.apache ([IO.Path]::GetFileName($Bahan.apache))
$apacheEkstrak = Join-Path $Installer ('bahan\' + [IO.Path]::GetFileNameWithoutExtension($apacheZip))
Ekstrak $apacheZip $apacheEkstrak
$apacheSumber = (Get-ChildItem $apacheEkstrak -Recurse -Filter httpd.exe | Select-Object -First 1).Directory.Parent.FullName
Salin $apacheSumber (Join-Path $runtime 'apache') @((Join-Path $apacheSumber 'logs'), (Join-Path $apacheSumber 'htdocs'), (Join-Path $apacheSumber 'manual'), (Join-Path $apacheSumber 'cgi-bin'), (Join-Path $apacheSumber 'conf\extra'), (Join-Path $apacheSumber 'conf\original')) @('httpd.conf', '*.pid')
New-Item -ItemType Directory -Force -Path (Join-Path $runtime 'apache\logs') | Out-Null

Tulis 'Menyiapkan MariaDB...'
$zip = Unduh $Bahan.mariadb ([IO.Path]::GetFileName($Bahan.mariadb))
$sementara = Join-Path $Installer 'bahan\mariadb-ekstrak'
Ekstrak $zip $sementara
$akarMaria = Get-ChildItem $sementara -Directory | Select-Object -First 1
Salin $akarMaria.FullName (Join-Path $runtime 'mariadb') @('include', 'mysql-test', 'sql-bench', 'data') @('*.pdb', '*.lib')

$nssmZip = Unduh $Bahan.nssm 'nssm.zip'
$nssmEkstrak = Join-Path $Installer 'bahan\nssm-ekstrak'
Ekstrak $nssmZip $nssmEkstrak
Copy-Item (Get-ChildItem $nssmEkstrak -Recurse -Filter nssm.exe | Where-Object { $_.FullName -match 'win64' } | Select-Object -First 1).FullName (Join-Path $runtime 'nssm.exe')

Copy-Item (Unduh $Bahan.vc_redist 'vc_redist.x64.exe') (Join-Path $Staging 'vc_redist.x64.exe')

# Layanan WhatsApp (Node + Baileys): node.exe portabel + kode + node_modules siap pakai (PC rental tanpa npm)
Tulis 'Menyalin layanan WhatsApp...'
if (-not (Test-Path $Bahan.node)) { throw "node.exe tidak ada: $($Bahan.node) (atur di installer\bahan.json)" }
New-Item -ItemType Directory -Force -Path (Join-Path $runtime 'node') | Out-Null
Copy-Item $Bahan.node (Join-Path $runtime 'node\node.exe')
$waSumber = Join-Path $Repo 'whatsapp-service'
if (-not (Test-Path (Join-Path $waSumber 'node_modules'))) {
    Push-Location $waSumber
    & cmd.exe /c 'npm ci --omit=dev 2>&1' | Out-Host   # lewat cmd: lihat catatan npm run build
    Pop-Location
    if ($LASTEXITCODE -ne 0) { throw 'npm ci whatsapp-service gagal' }
}
Salin $waSumber (Join-Path $Staging 'whatsapp') @('sesi') @('.env')

# Cloudflare Tunnel (akses dari internet; dinyalakan dari Admin → Pengaturan → Cloudflare Tunnel)
New-Item -ItemType Directory -Force -Path (Join-Path $runtime 'cloudflared') | Out-Null
Copy-Item (Unduh $Bahan.cloudflared 'cloudflared.exe') (Join-Path $runtime 'cloudflared\cloudflared.exe')

# ---------------- 3. Skrip pengelola & templat ----------------
Salin (Join-Path $Installer 'kelola') (Join-Path $Staging 'kelola')
Salin (Join-Path $Installer 'templat') (Join-Path $Staging 'kelola\templat')

# ---------------- 4. APK TV ----------------
$apk = Join-Path $Repo 'tv-agent\app\build\outputs\apk\debug\app-debug.apk'
$gradle = Get-Content (Join-Path $Repo 'tv-agent\app\build.gradle.kts') -Raw
$nama = [regex]::Match($gradle, 'versionName\s*=\s*"([^"]+)"').Groups[1].Value
$kodeApk = [regex]::Match($gradle, 'versionCode\s*=\s*(\d+)').Groups[1].Value
New-Item -ItemType Directory -Force -Path (Join-Path $Staging 'apk') | Out-Null
if (Test-Path $apk) {
    Copy-Item $apk (Join-Path $Staging 'apk\tv-agent.apk')
    "$nama $kodeApk" | Set-Content (Join-Path $Staging 'apk\versi.txt') -Encoding ASCII
    Tulis "APK TV $nama (kode $kodeApk) ikut dibundel"
} else {
    Write-Warning 'APK TV belum di-build (tv-agent\app\build\outputs\apk\debug\app-debug.apk); installer tanpa APK.'
    'tanpa apk' | Set-Content (Join-Path $Staging 'apk\README.txt')
}

Copy-Item (Join-Path $Repo 'public\favicon.ico') (Join-Path $Staging 'ikon.ico')

# ---------------- 5. Kompilasi ----------------
$ukuran = (Get-ChildItem $Staging -Recurse -File | Measure-Object Length -Sum).Sum / 1MB
Tulis ("Staging {0:N0} MB. Mengompilasi installer (beberapa menit)..." -f $ukuran)
& $iscc "/DVersi=$Versi" "/DStaging=$Staging" "/DKeluaran=$Keluaran" (Join-Path $Installer 'setup.iss') | Out-Host
if ($LASTEXITCODE -ne 0) { throw 'Kompilasi Inno Setup gagal' }

$hasil = Join-Path $Keluaran "BillingPS-Setup-$Versi.exe"
Tulis ("Selesai: {0} ({1:N0} MB)" -f $hasil, ((Get-Item $hasil).Length / 1MB))
