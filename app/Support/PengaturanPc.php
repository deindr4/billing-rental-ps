<?php

namespace App\Support;

use App\Models\Pengaturan;

/**
 * Pengaturan agen kiosk PC Windows per cabang (Admin → Pengaturan Operasional → Rental PC).
 * Dikirim ke PC lewat GET /api/tv/status (blok "pc"), lihat docs/pc-agent.md.
 */
final class PengaturanPc
{
    /** Yang dilakukan agen saat sesi selesai / waktu habis (layar kiosk selalu terkunci lagi) */
    public const AKHIR_SESI = [
        'kunci' => 'Kunci saja (aplikasi dibiarkan terbuka)',
        'tutup_aplikasi' => 'Kunci + tutup semua aplikasi pemain',
        'logoff' => 'Keluar (log off) akun Windows pemain',
        'restart' => 'Restart PC',
    ];

    /** Proteksi kiosk => [label, bawaan] */
    public const PROTEKSI = [
        'task_manager' => ['Blok Task Manager (bisa dibuka sementara dari kasir / PIN staf di PC)', true],
        'cmd_regedit' => ['Blok Command Prompt, PowerShell & Registry Editor', true],
        'pengaturan' => ['Blok Settings & Control Panel', true],
        'tombol_windows' => ['Blok tombol Windows, Alt+Tab keluar kiosk & Ctrl+Alt+Del (kecuali Task Manager yang diizinkan)', true],
        'usb' => ['Blok flashdisk / penyimpanan USB', false],
        'unduhan' => ['Hapus folder Downloads & Desktop pemain saat sesi selesai', false],
    ];

    public const APLIKASI_DEFAULT = [
        ['nama' => 'Steam', 'path' => 'C:\\Program Files (x86)\\Steam\\steam.exe'],
    ];

    /** @return array{akhir_sesi:string, task_manager_menit:int, proteksi:array<string,bool>, aplikasi:array<int,array{nama:string,path:string}>} */
    public static function ambil(?string $cabangId): array
    {
        $akhir = (string) Pengaturan::ambil('pc.akhir_sesi', 'tutup_aplikasi', $cabangId);
        $proteksi = (array) Pengaturan::ambil('pc.proteksi', [], $cabangId);

        return [
            'akhir_sesi' => isset(self::AKHIR_SESI[$akhir]) ? $akhir : 'tutup_aplikasi',
            'task_manager_menit' => max(1, min(60, (int) Pengaturan::ambil('pc.task_manager_menit', 5, $cabangId))),
            'proteksi' => collect(self::PROTEKSI)->map(fn ($p, $k) => (bool) ($proteksi[$k] ?? $p[1]))->all(),
            'aplikasi' => self::aplikasi($cabangId),
        ];
    }

    /** Aplikasi / launcher game yang tampil di kiosk PC (selain itu tidak bisa dibuka pemain) */
    public static function aplikasi(?string $cabangId): array
    {
        $daftar = Pengaturan::ambil('pc.aplikasi', self::APLIKASI_DEFAULT, $cabangId);

        return array_values(array_filter((array) $daftar, fn ($a) => ! empty($a['nama']) && ! empty($a['path'])));
    }

    public static function simpan(array $data, string $cabangId): void
    {
        Pengaturan::simpan('pc.akhir_sesi', isset(self::AKHIR_SESI[$data['akhir_sesi'] ?? '']) ? $data['akhir_sesi'] : 'tutup_aplikasi', $cabangId);
        Pengaturan::simpan('pc.task_manager_menit', max(1, min(60, (int) ($data['task_manager_menit'] ?? 5))), $cabangId);
        Pengaturan::simpan('pc.proteksi', collect(self::PROTEKSI)->map(fn ($p, $k) => (bool) ($data['proteksi'][$k] ?? $p[1]))->all(), $cabangId);
        Pengaturan::simpan('pc.aplikasi', array_values(array_map(
            fn ($a) => ['nama' => trim($a['nama']), 'path' => trim($a['path'])],
            array_filter($data['aplikasi'] ?? [], fn ($a) => trim($a['nama'] ?? '') !== '' && trim($a['path'] ?? '') !== '')
        )), $cabangId);
    }
}
