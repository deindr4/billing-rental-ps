<?php

namespace App\Http\Controllers\Api\Tv;

use App\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Services\PinService;
use App\Services\Tv\BypassTvService;
use App\Services\Tv\RilisApkService;
use App\Services\Tv\StatusTvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Endpoint untuk TV yang sudah terdaftar (middleware tv).
 */
class TvController extends Controller
{
    public function __construct(private StatusTvService $status) {}

    private function perangkat(Request $request): PerangkatTv
    {
        return $request->attributes->get('perangkat');
    }

    /** GET /api/tv/status */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->status->untuk($this->perangkat($request)));
    }

    /** POST /api/tv/heartbeat — info perangkat & tampilan yang sedang tampil */
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'versi_app' => 'nullable|string|max:20',
            'versi_android' => 'nullable|string|max:20',
            'layar' => 'nullable|string|max:20',
            'volume' => 'nullable|integer|min:0|max:100',
            'senyap' => 'nullable|boolean',
            'layar_hidup' => 'nullable|boolean',
            'diagnostik' => 'nullable|array|max:40',
        ]);

        $ubah = array_filter($data, fn ($v) => $v !== null && ! is_array($v)) + ['terakhir_online' => now(), 'ip' => $request->ip()];

        // Diagnostik hanya dikirim TV saat berubah / tiap 10 menit
        if (! empty($data['diagnostik'])) {
            $ubah['diagnostik'] = json_encode($data['diagnostik'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ubah['diagnostik_pada'] = now();
        }

        PerangkatTv::withoutGlobalScopes()->whereKey($this->perangkat($request)->id)->update($ubah);

        return response()->json(['ok' => true, 'server_time_ms' => now()->getTimestampMs()]);
    }

    /** POST /api/tv/bypass  body: { pin } — buka TV sementara tanpa sesi, disetujui PIN */
    public function bypass(Request $request, PinService $pin, BypassTvService $bypass): JsonResponse
    {
        $data = $request->validate([
            'pin' => 'required|string|max:10',
            'menit' => 'nullable|integer|min:5|max:1440',
        ]);
        $perangkat = $this->perangkat($request);

        try {
            $penyetuju = $pin->setujui($data['pin'], 'tv.bypass', $perangkat->tenant_id);
        } catch (BillingException $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        $bypass->mulai($perangkat, $penyetuju, 'tv', menit: $data['menit'] ?? null);

        return response()->json($this->status->untuk($perangkat->refresh()));
    }

    /**
     * POST /api/tv/verifikasi-pin  body: { pin }
     * Gerbang menu staf di TV (ganti server, izin, input HDMI): PIN pemilik izin tv.bypass.
     */
    public function verifikasiPin(Request $request, PinService $pin): JsonResponse
    {
        $data = $request->validate(['pin' => 'required|string|max:10']);
        $perangkat = $this->perangkat($request);

        try {
            $user = $pin->setujui($data['pin'], 'tv.bypass', $perangkat->tenant_id);
        } catch (BillingException $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        LogTv::catat($perangkat, 'menu_staf', [], $user);

        return response()->json(['ok' => true, 'nama' => $user->name]);
    }

    /** POST /api/tv/bypass/akhiri */
    public function akhiriBypass(Request $request, BypassTvService $bypass): JsonResponse
    {
        $perangkat = $bypass->akhiri($this->perangkat($request), 'tv');

        return response()->json($this->status->untuk($perangkat->refresh()));
    }

    /** POST /api/tv/input-hdmi  body: { id, label } — staf memilih HDMI tempat PS tersambung di TV */
    public function simpanInputHdmi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|string|max:191',
            'label' => 'nullable|string|max:60',
        ]);

        $perangkat = $this->perangkat($request);
        $perangkat->update(['input_hdmi' => $data['id'], 'input_hdmi_label' => $data['label'] ?? null]);

        LogTv::catat($perangkat, 'input_hdmi', ['label' => $data['label'] ?? $data['id'], 'lewat' => 'tv']);

        return response()->json($this->status->untuk($perangkat->refresh()));
    }

    /** POST /api/tv/panggil-kasir — pelanggan menekan "Panggil Kasir" di TV */
    public function panggilKasir(Request $request): JsonResponse
    {
        $perangkat = $this->perangkat($request);

        // Cegah dipencet berulang: satu panggilan per 30 detik per TV
        $baru = LogTv::withoutGlobalScopes()
            ->where('perangkat_id', $perangkat->id)
            ->where('jenis', 'panggil_kasir')
            ->where('created_at', '>', now()->subSeconds(30))
            ->doesntExist();

        if ($baru) {
            LogTv::catat($perangkat, 'panggil_kasir', ['layar' => $perangkat->layar]);
        }

        return response()->json(['ok' => true, 'pesan' => 'Kasir sudah dipanggil, mohon tunggu sebentar.']);
    }

    /** GET /api/tv/update?versi_kode=3 — ada APK lebih baru? */
    public function cekUpdate(Request $request): JsonResponse
    {
        $data = $request->validate(['versi_kode' => 'required|integer|min:0']);
        $rilis = RilisApk::terbaruSetelah((int) $data['versi_kode']);

        if (! $rilis) {
            return response()->json(['ada_update' => false]);
        }

        return response()->json([
            'ada_update' => true,
            'versi_nama' => $rilis->versi_nama,
            'versi_kode' => $rilis->versi_kode,
            'ukuran' => $rilis->ukuran,
            'sha256' => $rilis->sha256,
            'wajib' => $rilis->wajib,
            'catatan' => $rilis->catatan,
            'url' => route('tv.update.unduh', $rilis->id),
        ]);
    }

    /** GET /api/tv/update/{rilis}/unduh — file APK (hanya untuk TV terdaftar) */
    public function unduhUpdate(string $rilis): BinaryFileResponse
    {
        $rilis = RilisApk::aktif()->findOrFail($rilis);
        $disk = Storage::disk(RilisApkService::DISK);

        abort_unless($disk->exists($rilis->file), 404);

        return response()->download($disk->path($rilis->file), $rilis->namaFileUnduh(), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'X-Checksum-Sha256' => $rilis->sha256,
        ]);
    }

    /**
     * POST /api/tv/broadcasting/auth  body: { socket_id, channel_name }
     * Otorisasi channel Reverb: TV hanya boleh mendengar channel miliknya.
     */
    public function authBroadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => 'required|string|max:100',
        ]);

        if ($data['channel_name'] !== 'private-'.$this->perangkat($request)->channel()) {
            return response()->json(['pesan' => 'Channel tidak diizinkan.'], 403);
        }

        $koneksi = config('broadcasting.connections.reverb');
        $tanda = hash_hmac('sha256', $data['socket_id'].':'.$data['channel_name'], (string) $koneksi['secret']);

        return response()->json(['auth' => $koneksi['key'].':'.$tanda]);
    }
}
