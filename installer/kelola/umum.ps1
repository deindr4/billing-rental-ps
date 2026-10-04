# Billing PS — fungsi bersama skrip pengelola (pasang, perbarui, copot, layanan).
# Struktur di PC rental:
#   <root>\app       aplikasi Laravel (+ .env)
#   <root>\runtime   php, apache, mariadb, nssm.exe
#   <root>\data      mysql (database), storage (unggahan, sesi, backup), tmp
#   <root>\logs      log pemasangan, apache, mariadb, layanan
#   <root>\kelola    skrip ini + konfigurasi.json
#   <root>\whatsapp  layanan WhatsApp (Node + Baileys, node.exe di runtime\node); sesi login di data\whatsapp-sesi

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$App = Join-Path $Root 'app'
$Runtime = Join-Path $Root 'runtime'
$Data = Join-Path $Root 'data'
$Logs = Join-Path $Root 'logs'
$Php = Join-Path $Runtime 'php\php.exe'
$Nssm = Join-Path $Runtime 'nssm.exe'
$FileKonfig = Join-Path $PSScriptRoot 'konfigurasi.json'
$Node = Join-Path $Runtime 'node\node.exe'
$WaFolder = Join-Path $Root 'whatsapp'

# PATH untuk semua proses PHP/Apache: DLL PHP (intl, openssl, sodium) & Apache harus ketemu
$PathRuntime = "$Runtime\php;$Runtime\apache\bin;$Runtime\mariadb\bin;$env:SystemRoot\System32;$env:SystemRoot"

$Layanan = @(
    @{ Nama = 'BillingPS-Database'; Judul = 'Billing PS - Database (MariaDB)' },
    @{ Nama = 'BillingPS-Web'; Judul = 'Billing PS - Web (Apache)' },
    @{ Nama = 'BillingPS-Realtime'; Judul = 'Billing PS - Realtime TV (Reverb)' },
    @{ Nama = 'BillingPS-Antrean'; Judul = 'Billing PS - Antrean tugas' },
    @{ Nama = 'BillingPS-Jadwal'; Judul = 'Billing PS - Jadwal (pembayaran, sync, backup)' },
    @{ Nama = 'BillingPS-WhatsApp'; Judul = 'Billing PS - WhatsApp (laporan & notifikasi)' },
    @{ Nama = 'BillingPS-Tunnel'; Judul = 'Billing PS - Cloudflare Tunnel (akses internet)' }
)
$Cloudflared = Join-Path $Runtime 'cloudflared\cloudflared.exe'
$TokenTunnel = Join-Path $Data 'cloudflared\token.txt'

function Tulis([string] $Pesan) {
    Write-Host ("[{0:HH:mm:ss}] {1}" -f (Get-Date), $Pesan)
}

function Baca-Konfig {
    if (Test-Path $FileKonfig) { return Get-Content $FileKonfig -Raw | ConvertFrom-Json }
    return $null
}

function Simpan-Konfig($Konfig) {
    $Konfig | ConvertTo-Json -Depth 5 | Set-Content -Path $FileKonfig -Encoding UTF8
}

# Isi placeholder {{NAMA}} dalam templat, tulis UTF-8 tanpa BOM (Apache/MariaDB/PHP tidak suka BOM)
function Isi-Templat([string] $Sumber, [string] $Tujuan, [hashtable] $Nilai) {
    $isi = [IO.File]::ReadAllText($Sumber)
    foreach ($k in $Nilai.Keys) { $isi = $isi.Replace("{{$k}}", [string] $Nilai[$k]) }
    if ($isi -match '\{\{[A-Z_]+\}\}') { throw "Templat $Sumber masih punya placeholder: $($Matches[0])" }
    [IO.File]::WriteAllText($Tujuan, $isi, (New-Object Text.UTF8Encoding $false))
}

function Path-Maju([string] $p) { return $p.Replace('\', '/') }

function Port-Terpakai([int] $Port) {
    return [bool] (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

function Port-Bebas([int[]] $Calon) {
    foreach ($p in $Calon) { if (-not (Port-Terpakai $p)) { return $p } }
    throw "Tidak ada port bebas di antara: $($Calon -join ', ')"
}

# Alamat IPv4 LAN utama (adaptor yang punya gateway)
function Ip-Lan {
    $ip = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } |
        ForEach-Object { $_.IPv4Address.IPAddress } | Select-Object -First 1
    if (-not $ip) {
        $ip = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
            Select-Object -First 1 -ExpandProperty IPAddress
    }
    if ($ip) { return $ip } else { return '127.0.0.1' }
}

# Jalankan program native, gabungkan stderr ke keluaran (teks). Di PowerShell 5.1, stderr + ErrorAction Stop
# langsung dianggap error (httpd -t, mysql, nssm menulis ke stderr walau sukses) — kode keluar di $LASTEXITCODE.
function Jalankan([string] $File, [string[]] $Argumen) {
    $ErrorActionPreference = 'Continue'
    & $File @Argumen 2>&1 | ForEach-Object { "$_" }
}

function Acak([int] $Panjang = 24) {
    $huruf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] $Panjang
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $huruf[$_ % $huruf.Length] })
}

# Jalankan php / artisan dengan PATH runtime; gagal = lempar error (pesan ikut log)
function Jalankan-Php([string[]] $Argumen, [switch] $BolehGagal) {
    $lama = $env:PATH
    $env:PATH = $PathRuntime
    try {
        Push-Location $App
        $hasil = Jalankan $Php $Argumen
        $kode = $LASTEXITCODE
    } finally {
        Pop-Location
        $env:PATH = $lama
    }
    $hasil | ForEach-Object { Write-Host "    $_" }
    # Hanya 2 argumen pertama di pesan (argumen lain bisa berisi password owner)
    if ($kode -ne 0 -and -not $BolehGagal) { throw "php $(($Argumen | Select-Object -First 2) -join ' ') gagal (kode $kode)" }
    return $kode
}

function Artisan([string[]] $Argumen, [switch] $BolehGagal) {
    return Jalankan-Php (@('artisan') + $Argumen + @('--no-interaction')) -BolehGagal:$BolehGagal
}

function Nssm([string[]] $Argumen) {
    $out = Jalankan $Nssm $Argumen
    if ($LASTEXITCODE -ne 0) { throw "nssm $($Argumen -join ' ') gagal: $out" }
}

function Ada-Layanan([string] $Nama) {
    return [bool] (Get-Service -Name $Nama -ErrorAction SilentlyContinue)
}

# Daftarkan layanan Windows lewat NSSM (otomatis menyala saat PC dinyalakan, log dirotasi)
function Pasang-Layanan([string] $Nama, [string] $Judul, [string] $Exe, [string] $Argumen, [string] $Folder, [string[]] $Bergantung = @(), [string[]] $EnvTambahan = @()) {
    if (Ada-Layanan $Nama) {
        Jalankan $Nssm @('stop', $Nama) | Out-Null
        Nssm @('remove', $Nama, 'confirm')
    }
    Nssm @('install', $Nama, $Exe)
    # PowerShell 5.1 membuang argumen string kosong ke program native → nssm menolak "set ... AppParameters" tanpa nilai
    if ($Argumen) { Nssm @('set', $Nama, 'AppParameters', $Argumen) }
    Nssm @('set', $Nama, 'AppDirectory', $Folder)
    Nssm @('set', $Nama, 'DisplayName', $Judul)
    Nssm @('set', $Nama, 'Description', 'Billing Rental PS')
    Nssm @('set', $Nama, 'Start', 'SERVICE_AUTO_START')
    Nssm (@('set', $Nama, 'AppEnvironmentExtra', "PATH=$PathRuntime") + $EnvTambahan)
    Nssm @('set', $Nama, 'AppStdout', (Join-Path $Logs "$Nama.log"))
    Nssm @('set', $Nama, 'AppStderr', (Join-Path $Logs "$Nama.log"))
    Nssm @('set', $Nama, 'AppRotateFiles', '1')
    Nssm @('set', $Nama, 'AppRotateOnline', '1')
    Nssm @('set', $Nama, 'AppRotateBytes', '5242880')
    Nssm @('set', $Nama, 'AppExit', 'Default', 'Restart')
    Nssm @('set', $Nama, 'AppRestartDelay', '3000')
    if ($Bergantung.Count -gt 0) { Nssm (@('set', $Nama, 'DependOnService') + $Bergantung) }
}

# Ubah / tambah satu baris KUNCI=nilai di .env Laravel (UTF-8 tanpa BOM)
function Atur-Env([string] $Kunci, [string] $Nilai) {
    $file = Join-Path $App '.env'
    $baris = [Collections.Generic.List[string]] [IO.File]::ReadAllLines($file)
    $i = $baris.FindIndex({ param($b) $b -match "^$Kunci=" })
    if ($i -ge 0) { $baris[$i] = "$Kunci=$Nilai" } else { $baris.Add("$Kunci=$Nilai") }
    [IO.File]::WriteAllLines($file, $baris, (New-Object Text.UTF8Encoding $false))
}

# Layanan WhatsApp (pemasangan baru & update dari versi tanpa WhatsApp): port & token dibuat sekali,
# disimpan di konfigurasi.json & .env Laravel. Hanya mendengarkan 127.0.0.1 (tanpa aturan firewall).
function Siapkan-WhatsApp($Konfig) {
    if (-not (Test-Path $Node) -or -not (Test-Path (Join-Path $WaFolder 'index.js'))) {
        Tulis 'Layanan WhatsApp tidak ada di paket ini; dilewati'
        return
    }
    if (-not $Konfig.port_wa) {
        $Konfig | Add-Member -NotePropertyName port_wa -NotePropertyValue (Port-Bebas @(3001, 3002, 3003, 3011)) -Force
    }
    if (-not $Konfig.wa_token) {
        $Konfig | Add-Member -NotePropertyName wa_token -NotePropertyValue (Acak 40) -Force
    }
    Simpan-Konfig $Konfig

    $sesi = Join-Path $Data 'whatsapp-sesi'
    New-Item -ItemType Directory -Force -Path $sesi | Out-Null
    Pasang-Layanan 'BillingPS-WhatsApp' 'Billing PS - WhatsApp (laporan & notifikasi)' $Node 'index.js' $WaFolder @() @(
        "PORT=$($Konfig.port_wa)", "WA_TOKEN=$($Konfig.wa_token)", "FOLDER_SESI=$sesi"
    )
    Atur-Env 'WA_SERVICE_URL' "http://127.0.0.1:$($Konfig.port_wa)"
    Atur-Env 'WA_SERVICE_TOKEN' $Konfig.wa_token
    Tulis "Layanan WhatsApp siap (port $($Konfig.port_wa))"
}

# Layanan Cloudflare Tunnel: dipasang tapi mati sampai owner mengisi token di Admin → Pengaturan → Cloudflare Tunnel
# (aplikasi menulis data\cloudflared\token.txt lalu menyalakan layanan lewat nssm).
function Siapkan-Tunnel {
    if (-not (Test-Path $Cloudflared)) { return }
    $folder = Split-Path $TokenTunnel
    New-Item -ItemType Directory -Force -Path $folder | Out-Null
    # Token = rahasia: hanya Administrator & SYSTEM (Apache/aplikasi berjalan sebagai SYSTEM)
    & icacls $folder /inheritance:r /grant:r 'Administrators:(OI)(CI)F' 'SYSTEM:(OI)(CI)F' | Out-Null

    $ada = (Test-Path $TokenTunnel) -and (Get-Item $TokenTunnel).Length -gt 0
    # Lokasi token lewat variabel lingkungan (argumen berkutip rusak saat diteruskan PowerShell 5.1)
    Pasang-Layanan 'BillingPS-Tunnel' 'Billing PS - Cloudflare Tunnel (akses internet)' $Cloudflared `
        'tunnel --no-autoupdate run' (Split-Path $Cloudflared) @() @("TUNNEL_TOKEN_FILE=$TokenTunnel")
    if (-not $ada) { Nssm @('set', 'BillingPS-Tunnel', 'Start', 'SERVICE_DEMAND_START') }

    # Lewat tunnel, permintaan datang dari cloudflared (127.0.0.1, atau IP LAN PC ini bila Service di Cloudflare diisi
    # http://192.168.x.x): percayai keduanya supaya HTTPS & IP asli pengunjung (batas login) terbaca. Tanpa ini halaman
    # https memuat aset/Livewire lewat http → diblokir browser → login gagal. Isian lain dari pengguna tidak ditimpa.
    $isiEnv = [IO.File]::ReadAllText((Join-Path $App '.env'))
    if ($isiEnv -match '(?m)^TRUSTED_PROXIES=(\s*|127\.0\.0\.1,::1\s*)$' -or $isiEnv -notmatch '(?m)^TRUSTED_PROXIES=') {
        Atur-Env 'TRUSTED_PROXIES' '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
    }
}

function Mulai-Layanan([string] $Nama) {
    # Tunnel tanpa token tidak dinyalakan (akan gagal & diulang terus)
    if ($Nama -eq 'BillingPS-Tunnel' -and -not ((Test-Path $TokenTunnel) -and (Get-Item $TokenTunnel).Length -gt 0)) { return }
    if (Ada-Layanan $Nama) {
        Start-Service -Name $Nama -ErrorAction SilentlyContinue
        (Get-Service $Nama).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    }
}

function Henti-Layanan([string] $Nama) {
    if ((Ada-Layanan $Nama) -and (Get-Service $Nama).Status -ne 'Stopped') {
        Stop-Service -Name $Nama -Force -ErrorAction SilentlyContinue
        (Get-Service $Nama).WaitForStatus('Stopped', [TimeSpan]::FromSeconds(60))
    }
}

function Tunggu-Port([int] $Port, [int] $Detik = 60) {
    for ($i = 0; $i -lt $Detik; $i++) {
        if (Port-Terpakai $Port) { return }
        Start-Sleep -Seconds 1
    }
    throw "Port $Port tidak aktif setelah $Detik detik"
}

function Mysql-Root([string] $Sql, $Konfig) {
    $client = Join-Path $Runtime 'mariadb\bin\mysql.exe'
    $out = Jalankan $client @("-uroot", "-p$($Konfig.db_root)", "-h127.0.0.1", "-P$($Konfig.port_db)", "-e", $Sql)
    if ($LASTEXITCODE -ne 0) { throw "SQL gagal: $(($out | Where-Object { $_ -notmatch 'password on the command line' }) -join ' ')" }
}

# Buka port di firewall Windows hanya untuk jaringan lokal (LAN)
function Pasang-Firewall($Konfig) {
    foreach ($a in @(@{ N = 'Billing PS - Web'; P = $Konfig.port_web }, @{ N = 'Billing PS - Realtime TV'; P = $Konfig.port_ws })) {
        Jalankan netsh @('advfirewall', 'firewall', 'delete', 'rule', "name=$($a.N)") | Out-Null
        $out = Jalankan netsh @('advfirewall', 'firewall', 'add', 'rule', "name=$($a.N)", 'dir=in', 'action=allow', 'protocol=TCP', "localport=$($a.P)", 'remoteip=localsubnet', 'profile=any')
        if ($LASTEXITCODE -ne 0) { throw "Aturan firewall gagal: $out" }
    }
}

function Copot-Firewall {
    foreach ($n in @('Billing PS - Web', 'Billing PS - Realtime TV')) {
        Jalankan netsh @('advfirewall', 'firewall', 'delete', 'rule', "name=$n") | Out-Null
    }
}

# Pintasan .url (Desktop & folder program) ke alamat aplikasi
function Tulis-Pintasan($Konfig) {
    $alamat = "http://localhost$(if ($Konfig.port_web -ne 80) { ':' + $Konfig.port_web })"
    $isi = @{ 'Billing PS.url' = "$alamat/"; 'Billing PS - Admin.url' = "$alamat/admin" }
    foreach ($nama in $isi.Keys) {
        $teks = "[InternetShortcut]`r`nURL=$($isi[$nama])`r`nIconFile=$App\public\favicon.ico`r`nIconIndex=0`r`n"
        [IO.File]::WriteAllText((Join-Path $Root $nama), $teks)
    }
}
