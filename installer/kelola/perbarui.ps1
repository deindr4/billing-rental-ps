# Billing PS — update ke versi baru (dipanggil installer).
#   -Tahap sebelum : (file lama masih ada) backup database & hentikan semua layanan
#   -Tahap sesudah : (file baru sudah disalin) migrasi, optimasi, nyalakan layanan
param([ValidateSet('sebelum', 'sesudah')][string] $Tahap = 'sesudah')

. (Join-Path $PSScriptRoot 'umum.ps1')

New-Item -ItemType Directory -Force -Path $Logs | Out-Null
Start-Transcript -Path (Join-Path $Logs ("perbarui-$Tahap-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))) | Out-Null

try {
    $konfig = Baca-Konfig
    if (-not $konfig) { throw 'konfigurasi.json tidak ditemukan; pemasangan sebelumnya tidak lengkap.' }

    if ($Tahap -eq 'sebelum') {
        Tulis 'Backup database sebelum update...'
        Mulai-Layanan 'BillingPS-Database'
        Tunggu-Port $konfig.port_db
        Artisan @('backup:buat') -BolehGagal | Out-Null

        Tulis 'Menghentikan layanan...'
        foreach ($l in ($Layanan | Select-Object -Skip 1)) { Henti-Layanan $l.Nama }
        Henti-Layanan 'BillingPS-Database'
        exit 0
    }

    $nilai = @{
        APP = Path-Maju $App; RUNTIME = Path-Maju $Runtime; DATA = Path-Maju $Data; LOGS = Path-Maju $Logs
        PORT_WEB = $konfig.port_web; PORT_DB = $konfig.port_db; ZONA = $konfig.zona
    }

    # Runtime bisa ikut diperbarui: tulis ulang konfigurasi dari templat terbaru
    Isi-Templat (Join-Path $PSScriptRoot 'templat\php.ini') (Join-Path $Runtime 'php\php.ini') $nilai
    Isi-Templat (Join-Path $PSScriptRoot 'templat\my.ini') (Join-Path $Data 'mysql\my.ini') $nilai
    Isi-Templat (Join-Path $PSScriptRoot 'templat\httpd.conf') (Join-Path $Runtime 'apache\conf\httpd.conf') $nilai

    Mulai-Layanan 'BillingPS-Database'
    Tunggu-Port $konfig.port_db

    Tulis 'Memperbarui database...'
    Artisan @('optimize:clear') -BolehGagal | Out-Null
    Artisan @('migrate', '--force')
    Artisan @('db:seed', '--class=HakAksesSeeder', '--force')
    Artisan @('sync', 'pasang-trigger')
    Artisan @('pasang:awal', "--apk=$(Join-Path $Root 'apk\tv-agent.apk')")
    Artisan @('storage:link', '--force') -BolehGagal | Out-Null
    # Versi lama tanpa WhatsApp: layanan & .env dilengkapi di sini (sebelum optimize)
    Siapkan-WhatsApp $konfig
    Siapkan-Tunnel
    Artisan @('optimize')

    Tulis 'Menyalakan layanan...'
    foreach ($l in $Layanan) { Mulai-Layanan $l.Nama }
    Artisan @('queue:restart') -BolehGagal | Out-Null
    Tunggu-Port $konfig.port_web
    Tulis-Pintasan $konfig

    [IO.File]::WriteAllText((Join-Path $Logs 'hasil-pasang.txt'), "Update selesai $(Get-Date -Format 'dd/MM/yyyy HH:mm'). Data & pengaturan tetap.")
    Tulis 'Update selesai.'
    exit 0
} catch {
    Write-Host "GAGAL: $($_.Exception.Message)"
    Write-Host $_.ScriptStackTrace
    [IO.File]::WriteAllText((Join-Path $Logs 'hasil-pasang.txt'), "GAGAL update: $($_.Exception.Message)")
    exit 1
} finally {
    Stop-Transcript | Out-Null
}
