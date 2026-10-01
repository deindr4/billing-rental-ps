<#
  Rollback APK TV Agent ("downgrade paksa").

  Android menolak memasang versionCode yang lebih kecil, jadi rollback = build ULANG kode versi lama
  dengan versionCode BARU (lebih besar dari rilis bermasalah), lalu unggah sebagai rilis wajib & push.

  Pemakaian (dari folder tv-agent):
    powershell -ExecutionPolicy Bypass -File build-rollback.ps1 -Dari 0.3.0 -Kode 5 -Nama 0.3.2

    -Dari  versi lama yang ingin dipakai lagi (tag git "apk-0.3.0"), atau hash commit
    -Kode  versionCode baru: harus > kode rilis terbesar (lihat Admin → Rilis APK TV)
    -Nama  versionName baru, mis. 0.3.2

  Hasil: tv-agent\rilis\tv-agent-<Nama>.apk  → Admin → Rilis APK TV → Unggah rilis baru (centang Wajib) → Push paksa.
#>
param(
    [Parameter(Mandatory = $true)][string]$Dari,
    [Parameter(Mandatory = $true)][int]$Kode,
    [Parameter(Mandatory = $true)][string]$Nama
)

$ErrorActionPreference = 'Stop'
$akar = Split-Path -Parent $PSScriptRoot          # folder repo billing
$ref = if (git -C $akar rev-parse -q --verify "refs/tags/apk-$Dari" 2>$null) { "apk-$Dari" } else { $Dari }
$kerja = Join-Path $env:TEMP "tv-agent-rollback-$Kode"

if (-not $env:JAVA_HOME) { $env:JAVA_HOME = 'C:\Program Files\Java\jdk-24' }
if (-not $env:ANDROID_HOME) { $env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk" }
if (-not $env:GRADLE_USER_HOME) { $env:GRADLE_USER_HOME = 'E:\.gradle' }

Write-Host "Ambil kode $ref ..."
if (Test-Path $kerja) { git -C $akar worktree remove --force $kerja }
git -C $akar worktree add --detach $kerja $ref | Out-Null

try {
    # Ganti nomor versi di salinan kode lama
    $gradle = Join-Path $kerja 'tv-agent\app\build.gradle.kts'
    $isi = Get-Content $gradle -Raw
    $isi = $isi -replace 'versionCode = \d+', "versionCode = $Kode" -replace 'versionName = "[^"]*"', "versionName = `"$Nama`""
    Set-Content -Path $gradle -Value $isi -Encoding utf8

    # Pakai local.properties (lokasi SDK) dari folder kerja sekarang
    Copy-Item (Join-Path $PSScriptRoot 'local.properties') (Join-Path $kerja 'tv-agent\local.properties') -ErrorAction SilentlyContinue

    Write-Host "Build $Nama (kode $Kode) dari $ref ..."
    Push-Location (Join-Path $kerja 'tv-agent')
    & .\gradlew.bat assembleDebug -q
    if ($LASTEXITCODE -ne 0) { throw "Build gagal" }
    Pop-Location

    $keluar = Join-Path $PSScriptRoot 'rilis'
    New-Item -ItemType Directory -Force $keluar | Out-Null
    $apk = Join-Path $keluar "tv-agent-$Nama.apk"
    Copy-Item (Join-Path $kerja 'tv-agent\app\build\outputs\apk\debug\app-debug.apk') $apk -Force

    Write-Host ""
    Write-Host "Selesai: $apk"
    Write-Host "Berikutnya: Admin -> Rilis APK TV -> Unggah rilis baru (versi $Nama, kode $Kode, centang Wajib) -> Push update (pasang sekarang juga)."
}
finally {
    if ((Get-Location).Path -like "$kerja*") { Pop-Location }
    git -C $akar worktree remove --force $kerja
}
