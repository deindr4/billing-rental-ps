# Billing PS — kelola layanan dari menu Start ("Kelola layanan Billing PS").
param([ValidateSet('menu', 'status', 'mulai', 'henti', 'ulang')][string] $Aksi = 'menu')

# Perlu hak administrator untuk menyalakan/mematikan layanan
$admin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] 'Administrator')
if (-not $admin) {
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Aksi $Aksi"
    exit
}

. (Join-Path $PSScriptRoot 'umum.ps1')
$ErrorActionPreference = 'Continue'

function Status {
    $konfig = Baca-Konfig
    Write-Host ''
    foreach ($l in $Layanan) {
        $s = Get-Service $l.Nama -ErrorAction SilentlyContinue
        $teks = if ($s) { $s.Status } else { 'Tidak terpasang' }
        $warna = if ($s -and $s.Status -eq 'Running') { 'Green' } else { 'Yellow' }
        Write-Host ("  {0,-52} {1}" -f $l.Judul, $teks) -ForegroundColor $warna
    }
    if ($konfig) {
        $port = if ($konfig.port_web -ne 80) { ':' + $konfig.port_web } else { '' }
        Write-Host "`n  Alamat: http://$(Ip-Lan)$port   (di PC ini: http://localhost$port)"
    }
}

function Mulai { foreach ($l in $Layanan) { Write-Host "  menyalakan $($l.Judul)..."; Mulai-Layanan $l.Nama } }
function Henti { foreach ($l in ($Layanan | Select-Object -Skip 1)) { Write-Host "  menghentikan $($l.Judul)..."; Henti-Layanan $l.Nama }; Henti-Layanan 'BillingPS-Database' }

switch ($Aksi) {
    'status' { Status }
    'mulai' { Mulai; Status }
    'henti' { Henti; Status }
    'ulang' { Henti; Mulai; Status }
    'menu' {
        while ($true) {
            Clear-Host
            Write-Host '=== Billing PS - Kelola layanan ===' -ForegroundColor Cyan
            Status
            Write-Host "`n  1. Nyalakan semua   2. Hentikan semua   3. Mulai ulang semua   4. Buka folder log   5. Keluar"
            switch (Read-Host '  Pilih') {
                '1' { Mulai; Read-Host '  Enter untuk kembali' | Out-Null }
                '2' { Henti; Read-Host '  Enter untuk kembali' | Out-Null }
                '3' { Henti; Mulai; Read-Host '  Enter untuk kembali' | Out-Null }
                '4' { Start-Process explorer.exe $Logs }
                default { exit }
            }
        }
    }
}
