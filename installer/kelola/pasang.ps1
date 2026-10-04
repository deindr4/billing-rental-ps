# Billing PS — pemasangan pertama (dipanggil installer setelah file disalin).
# -Isian : file JSON dari wizard (rental, owner, port). Dihapus installer setelah selesai.
param([Parameter(Mandatory = $true)][string] $Isian)

. (Join-Path $PSScriptRoot 'umum.ps1')

New-Item -ItemType Directory -Force -Path $Logs, $Data, (Join-Path $Data 'tmp') | Out-Null
Start-Transcript -Path (Join-Path $Logs ("pasang-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))) | Out-Null

try {
    $w = Get-Content $Isian -Raw -Encoding UTF8 | ConvertFrom-Json
    $konfig = Baca-Konfig

    # ---------------- 1. Visual C++ runtime (wajib untuk PHP & Apache) ----------------
    $vc = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
    if (-not $vc -or $vc.Installed -ne 1 -or [int] $vc.Minor -lt 50) {   # Apache VS18 butuh 14.50+
        Tulis 'Memasang Visual C++ Redistributable...'
        $p = Start-Process -FilePath $w.vc_redist -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
        if ($p.ExitCode -notin 0, 1638, 3010) { throw "VC++ Redistributable gagal dipasang (kode $($p.ExitCode))" }
    }

    # ---------------- 2. Port & konfigurasi ----------------
    if (-not $konfig) {
        $portWeb = [int] $w.port_web
        if (Port-Terpakai $portWeb) { throw "Port web $portWeb sudah dipakai program lain. Pilih port lain saat memasang." }

        $konfig = [pscustomobject] @{
            port_web = $portWeb
            port_db = Port-Bebas @(3306, 3307, 3308, 3309, 3310)
            port_ws = Port-Bebas @(8080, 8081, 8082, 8083, 8090)
            db_root = Acak 24
            db_user = Acak 24
            ip = Ip-Lan
            zona = $w.zona
            dipasang = (Get-Date).ToString('s')
        }
        Simpan-Konfig $konfig
        # Konfigurasi berisi kata sandi database: hanya Administrator & SYSTEM
        & icacls $FileKonfig /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null
    }
    Tulis "Port web $($konfig.port_web), database $($konfig.port_db), realtime $($konfig.port_ws), IP $($konfig.ip)"

    $nilai = @{
        APP = Path-Maju $App; RUNTIME = Path-Maju $Runtime; DATA = Path-Maju $Data; LOGS = Path-Maju $Logs
        PORT_WEB = $konfig.port_web; PORT_DB = $konfig.port_db; ZONA = $konfig.zona
    }

    # ---------------- 3. PHP ----------------
    Isi-Templat (Join-Path $PSScriptRoot 'templat\php.ini') (Join-Path $Runtime 'php\php.ini') $nilai

    # ---------------- 4. Database MariaDB ----------------
    $mysqlData = Join-Path $Data 'mysql'
    if (-not (Test-Path (Join-Path $mysqlData 'mysql'))) {
        Tulis 'Menyiapkan database baru...'
        $init = Join-Path $Runtime 'mariadb\bin\mariadb-install-db.exe'
        Jalankan $init @("--datadir=$mysqlData", "--password=$($konfig.db_root)", "--port=$($konfig.port_db)") | ForEach-Object { Write-Host "    $_" }
        if ($LASTEXITCODE -ne 0) { throw 'Inisialisasi database gagal' }
    }
    Isi-Templat (Join-Path $PSScriptRoot 'templat\my.ini') (Join-Path $mysqlData 'my.ini') $nilai

    Pasang-Layanan 'BillingPS-Database' 'Billing PS - Database (MariaDB)' (Join-Path $Runtime 'mariadb\bin\mysqld.exe') "--defaults-file=`"$mysqlData\my.ini`"" (Join-Path $Runtime 'mariadb\bin')
    Mulai-Layanan 'BillingPS-Database'
    Tunggu-Port $konfig.port_db

    Tulis 'Membuat database & pengguna aplikasi...'
    Mysql-Root ("CREATE DATABASE IF NOT EXISTS billing_ps CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
        "CREATE USER IF NOT EXISTS 'billing'@'127.0.0.1' IDENTIFIED BY '$($konfig.db_user)'; " +
        "CREATE USER IF NOT EXISTS 'billing'@'localhost' IDENTIFIED BY '$($konfig.db_user)'; " +
        "GRANT ALL PRIVILEGES ON billing_ps.* TO 'billing'@'127.0.0.1'; GRANT ALL PRIVILEGES ON billing_ps.* TO 'billing'@'localhost'; FLUSH PRIVILEGES;") $konfig

    # ---------------- 5. Aplikasi ----------------
    foreach ($d in @('storage\app\public', 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $Data $d) | Out-Null
    }

    $fileEnv = Join-Path $App '.env'
    if (-not (Test-Path $fileEnv)) {
        Tulis 'Membuat .env...'
        $kunci = New-Object byte[] 32
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($kunci)
        $url = "http://$($konfig.ip)$(if ($konfig.port_web -ne 80) { ':' + $konfig.port_web })"
        Isi-Templat (Join-Path $PSScriptRoot 'templat\env') $fileEnv ($nilai + @{
            APP_KEY = 'base64:' + [Convert]::ToBase64String($kunci); APP_URL = $url; DB_PASSWORD = $konfig.db_user
            REVERB_ID = Get-Random -Minimum 100000 -Maximum 999999; REVERB_KEY = (Acak 20).ToLower(); REVERB_SECRET = (Acak 32).ToLower()
            PORT_WS = $konfig.port_ws
        })
        & icacls $fileEnv /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null
    }

    $pulihkan = $w.mode -eq 'pulihkan'
    $url = "http://$($konfig.ip)$(if ($konfig.port_web -ne 80) { ':' + $konfig.port_web })"
    $apk = Join-Path $Root 'apk\tv-agent.apk'

    Tulis 'Membuat tabel database...'
    Artisan @('migrate', '--force')

    if ($pulihkan) {
        # Pindah PC / pasang ulang: seluruh data (akun, transaksi, member, pengaturan, logo) dari file backup
        Tulis 'Memulihkan data dari backup...'
        if (-not (Test-Path $w.backup)) { throw "File backup tidak ditemukan: $($w.backup)" }
        $folderBackup = Join-Path $Data 'storage\app\private\backup'
        New-Item -ItemType Directory -Force -Path $folderBackup | Out-Null
        $namaBackup = 'backup-dipulihkan-{0:yyyyMMdd-HHmmss}.zip' -f (Get-Date)
        Copy-Item $w.backup (Join-Path $folderBackup $namaBackup)
        Artisan @('backup:pulihkan', $namaBackup, '--force')
        Artisan @('migrate', '--force')   # backup dari versi lama: lengkapi tabel/kolom baru
    }

    Artisan @('db:seed', '--class=HakAksesSeeder', '--force')

    if ($pulihkan) {
        Artisan @('sync', 'pasang-trigger')
        Artisan @('pasang:awal', "--url-lokal=$url", "--apk=$apk")
        $owner = Artisan-Keluaran @('pasang:awal', '--daftar-owner') | Where-Object { $_ -match '^OWNER: ' } | ForEach-Object { $_.Substring(7) }
    } else {
        Tulis 'Mengisi data rental & akun owner...'
        Artisan @('pasang:awal', "--rental=$($w.rental)", "--cabang=$($w.cabang)", "--zona=$($konfig.zona)",
            "--owner-nama=$($w.owner_nama)", "--owner-email=$($w.owner_email)", "--owner-password=$($w.owner_password)",
            "--pin=$($w.pin)", "--url-lokal=$url", "--apk=$apk")
    }

    Artisan @('storage:link', '--force') -BolehGagal
    Siapkan-WhatsApp $konfig   # sebelum optimize: WA_SERVICE_* masuk cache konfigurasi
    Siapkan-Tunnel             # TRUSTED_PROXIES juga sebelum optimize
    Artisan @('optimize')

    # ---------------- 6. Web & layanan pendukung ----------------
    Isi-Templat (Join-Path $PSScriptRoot 'templat\httpd.conf') (Join-Path $Runtime 'apache\conf\httpd.conf') $nilai
    $httpd = Join-Path $Runtime 'apache\bin\httpd.exe'
    $lama = $env:PATH; $env:PATH = $PathRuntime
    $cek = Jalankan $httpd @('-t'); $kodeCek = $LASTEXITCODE
    $env:PATH = $lama
    if ($kodeCek -ne 0) { throw "Konfigurasi Apache salah: $cek" }

    Pasang-Layanan 'BillingPS-Web' 'Billing PS - Web (Apache)' $httpd '' (Join-Path $Runtime 'apache\bin') @('BillingPS-Database')
    Pasang-Layanan 'BillingPS-Realtime' 'Billing PS - Realtime TV (Reverb)' $Php "artisan reverb:start --host=0.0.0.0 --port=$($konfig.port_ws)" $App @('BillingPS-Database')
    Pasang-Layanan 'BillingPS-Antrean' 'Billing PS - Antrean tugas' $Php 'artisan queue:work --sleep=1 --tries=3 --max-time=3600' $App @('BillingPS-Database')
    Pasang-Layanan 'BillingPS-Jadwal' 'Billing PS - Jadwal (pembayaran, sync, backup)' $Php 'artisan schedule:work' $App @('BillingPS-Database')

    foreach ($l in $Layanan) { Mulai-Layanan $l.Nama }
    Tunggu-Port $konfig.port_web

    # ---------------- 7. Firewall & pintasan ----------------
    Tulis 'Membuka firewall untuk jaringan lokal...'
    Pasang-Firewall $konfig
    Tulis-Pintasan $konfig

    # Password tidak ditulis ke file (installer menampilkannya langsung di layar selesai)
    $login = if ($pulihkan) {
        "Login owner (dari backup, password sama seperti sebelumnya):`r`n" + (($owner | ForEach-Object { "  - $_" }) -join "`r`n")
    } else {
        "Login owner:        $($w.owner_email)`r`nLogin super admin:  superadmin@billing.lokal (password sama dengan owner; ganti lewat: php artisan superadmin)"
    }
    $hasil = @"
Alamat aplikasi (kasir/tablet di Wi-Fi rental):  $url
Panel admin:                                     $url/admin
Unduh APK TV:                                    $url/apk
$login
"@
    if ($pulihkan) {
        $hasil += "`r`n`r`nDipulihkan dari backup. Isi ulang: API key payment gateway & token Telegram (Admin > Pengaturan); " +
            "WhatsApp: scan QR lagi. TV: bila IP PC berubah, ganti alamat server di TV (menu staf); " +
            "kode darurat TV muncul lagi setelah TV dipasangkan ulang."
    }
    [IO.File]::WriteAllText((Join-Path $Logs 'hasil-pasang.txt'), $hasil)
    Tulis 'Pemasangan selesai.'
    Write-Host $hasil
    exit 0
} catch {
    Write-Host "GAGAL: $($_.Exception.Message)"
    Write-Host $_.ScriptStackTrace
    [IO.File]::WriteAllText((Join-Path $Logs 'hasil-pasang.txt'), "GAGAL: $($_.Exception.Message)")
    exit 1
} finally {
    Stop-Transcript | Out-Null
}
