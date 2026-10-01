# Billing PS — dipanggil uninstaller: hentikan & hapus layanan Windows dan aturan firewall.
# Data (database, unggahan, backup) dihapus/disimpan oleh uninstaller sesuai pilihan pengguna.
. (Join-Path $PSScriptRoot 'umum.ps1')

$ErrorActionPreference = 'Continue'

foreach ($l in ($Layanan | Select-Object -Skip 1)) { Henti-Layanan $l.Nama }
Henti-Layanan 'BillingPS-Database'

foreach ($l in $Layanan) {
    if (Ada-Layanan $l.Nama) { Jalankan $Nssm @('remove', $l.Nama, 'confirm') | Out-Null }
}

Copot-Firewall

# Lepas tautan public\storage -> data\storage (rmdir pada junction hanya menghapus tautannya, bukan isi data)
$tautan = Join-Path $App 'public\storage'
if (Test-Path $tautan) { & cmd.exe /c rmdir "$tautan" 2>&1 | Out-Null }
Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $Root 'Billing PS.url'), (Join-Path $Root 'Billing PS - Admin.url')
exit 0
