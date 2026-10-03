package id.rentalps.tvagent.core

import android.content.Context
import android.os.Build
import android.util.Log
import id.rentalps.tvagent.BuildConfig
import id.rentalps.tvagent.data.Api
import id.rentalps.tvagent.data.ApiError
import id.rentalps.tvagent.data.JsonApi
import id.rentalps.tvagent.data.Penyimpanan
import id.rentalps.tvagent.data.PerintahRemote
import id.rentalps.tvagent.data.PerluPairing
import id.rentalps.tvagent.data.Realtime
import id.rentalps.tvagent.data.StatusTv
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonPrimitive
import java.io.IOException

/** Tahap aplikasi */
sealed interface Tahap {
    /** Alamat server belum diisi */
    data object IsiServer : Tahap

    /** Menampilkan kode pairing */
    data class Pairing(val kode: String?, val sampaiMs: Long, val pesan: String? = null) : Tahap

    /** Sudah terdaftar */
    data object Aktif : Tahap
}

/** Peringatan sisa waktu untuk bunyi & banner di atas tampilan PS */
data class Peringatan(val jenis: Jenis, val menit: Int, val bunyi: Boolean) {
    /** MULAI = waktu pilih game selesai, waktu sewa mulai berjalan */
    enum class Jenis { MULAI, SISA, SATU_MENIT, HABIS }
}

data class Keadaan(
    val tahap: Tahap = Tahap.IsiServer,
    val status: StatusTv? = null,
    /** Tampilan yang sedang berlaku (server + timer lokal + bypass/darurat lokal) */
    val layar: String = "memuat",
    val offline: Boolean = false,
    val realtime: Boolean = false,
    val daruratSampaiMs: Long = 0,
    val pesan: String? = null,
    /** Server yang sedang dipakai: "lokal" | "cloud" */
    val server: String = "lokal",
    /** Pindah ke HDMI gagal: tampilkan layar TV Agent + pilihan input, bukan aplikasi lain */
    val hdmiGagal: Boolean = false,
    /** Respon ke server (ms): -1 = tidak terjangkau, null = belum diukur / cloud tidak diatur */
    val pingLokalMs: Long? = null,
    val pingCloudMs: Long? = null,
)

/**
 * Otak TV Agent. Satu instance per aplikasi (AgentApp), dijalankan AgentService.
 *
 * - Status diambil dari server (polling + sinyal realtime), disimpan untuk dipakai saat offline
 * - Setiap detik tampilan dihitung ulang dari timer lokal, jadi paket habis tetap mengunci
 *   walau server/jaringan mati
 */
class Agent(private val ctx: Context) {
    val simpan = Penyimpanan(ctx)
    val api = Api(simpan)
    val updater = Updater(ctx, api)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val _keadaan = MutableStateFlow(Keadaan())
    val keadaan: StateFlow<Keadaan> = _keadaan.asStateFlow()

    private val _peringatan = MutableSharedFlow<Peringatan>(extraBufferCapacity = 4)
    val peringatan: SharedFlow<Peringatan> = _peringatan.asSharedFlow()

    /** Kasir memindah HDMI saat TV terbuka: id input yang harus dibuka sekarang (dijalankan AgentService) */
    private val _pindahHdmi = MutableSharedFlow<String>(extraBufferCapacity = 2)
    val pindahHdmi: SharedFlow<String> = _pindahHdmi.asSharedFlow()

    /** Pemberitahuan dari kasir untuk ditampilkan di tengah layar (AgentService → OverlayPemberitahuan) */
    private val _pemberitahuan = MutableSharedFlow<id.rentalps.tvagent.data.Pemberitahuan>(extraBufferCapacity = 4)
    val pemberitahuan: SharedFlow<id.rentalps.tvagent.data.Pemberitahuan> = _pemberitahuan.asSharedFlow()
    private val peringatanTerkirim = mutableSetOf<String>()

    private val sinyal = Channel<String>(Channel.CONFLATED)
    private val sinyalHeartbeat = Channel<Unit>(Channel.CONFLATED)
    private val realtime = Realtime(
        api, scope,
        onSegarkan = { sinyal.trySend(it) },
        onTersambung = { ok -> _keadaan.update { it.copy(realtime = ok) } },
        onPerintah = { jalankanPerintah(it) },
    )

    private var jobUtama: Job? = null
    private var jobDetik: Job? = null
    private var diagnostikTerakhir: String? = null
    private var terakhirKirimDiagnostikMs = 0L

    /* ================= Siklus hidup ================= */

    fun mulai() {
        if (jobDetik == null) {
            jobDetik = scope.launch { loopDetik() }
        }
        mulaiUlang()
    }

    private fun mulaiUlang() {
        jobUtama?.cancel()
        realtime.hentikan()

        jobUtama = scope.launch {
            when {
                simpan.serverUrl.isNullOrBlank() -> _keadaan.update { Keadaan(tahap = Tahap.IsiServer) }
                simpan.token.isNullOrBlank() -> loopPairing()
                else -> loopAktif()
            }
        }
    }

    fun setelServer(alamat: String) {
        var url = alamat.trim()
        if (!url.startsWith("http://") && !url.startsWith("https://")) url = "http://$url"
        simpan.serverUrl = url
        simpan.hapusKredensial()
        simpan.serverAktif = url
        mulaiUlang()
    }

    /** Dari menu staf: ganti server / pasangkan ulang */
    fun lupakanServer() {
        simpan.hapusKredensial()
        simpan.serverUrl = null
        mulaiUlang()
    }

    fun segarkanSekarang() {
        sinyal.trySend("manual")
    }

    /* ================= Pairing ================= */

    private suspend fun loopPairing() {
        while (scope.isActive) {
            try {
                val mulai = api.mulaiPairing(
                    mapOf(
                        "android_id" to android.provider.Settings.Secure.getString(ctx.contentResolver, android.provider.Settings.Secure.ANDROID_ID),
                        "merek" to Build.MANUFACTURER.replaceFirstChar { it.uppercase() },
                        "model" to Build.MODEL,
                        "versi_android" to Build.VERSION.RELEASE,
                        "versi_app" to BuildConfig.VERSION_NAME,
                    )
                )
                val sampai = System.currentTimeMillis() + mulai.kedaluwarsaDetik * 1000L
                _keadaan.update { Keadaan(tahap = Tahap.Pairing(mulai.kode, sampai)) }

                while (System.currentTimeMillis() < sampai) {
                    delay(mulai.intervalCekDetik * 1000L)
                    val hasil = api.cekPairing(mulai.kunci)

                    when (hasil.status) {
                        "berhasil" -> {
                            simpan.token = hasil.token
                            simpan.rahasiaOffline = hasil.rahasiaOffline
                            simpan.perangkatId = hasil.perangkatId
                            simpan.serverPairing = simpan.serverDipakai()
                            loopAktif()
                            return
                        }
                        "menunggu" -> Unit
                        else -> break // kedaluwarsa / tidak dikenal -> kode baru
                    }
                }
            } catch (e: Exception) {
                Log.w(TAG, "Pairing: ${e.message}")
                _keadaan.update { Keadaan(tahap = Tahap.Pairing(null, 0, pesanError(e))) }
                delay(5_000)
            }
        }
    }

    /* ================= Aktif ================= */

    /** Heartbeat & update berjalan sebagai anak pekerjaan ini, jadi ikut berhenti saat pairing ulang */
    private suspend fun loopAktif() = coroutineScope {
        // Tampilkan status tersimpan dulu (TV baru nyala / server belum bisa dihubungi)
        val cadangan = simpan.statusTerakhir?.let { runCatching { JsonApi.decodeFromString(StatusTv.serializer(), it) }.getOrNull() }
        _keadaan.update { Keadaan(tahap = Tahap.Aktif, status = cadangan, offline = cadangan != null, daruratSampaiMs = simpan.daruratSampaiMs) }

        launch { loopHeartbeat() }
        launch { loopUpdate() }
        launch { loopKembaliKeLokal() }
        launch { loopPingCloud() }

        while (isActive) {
            val jeda = ambilStatus()
            // Tunggu sinyal realtime atau jatuh tempo polling
            val alasan = withTimeoutOrNull(jeda) { sinyal.receive() }
            if (alasan == "update") launch { cekUpdate() }
        }
    }

    /** Return jeda polling berikutnya (ms) */
    private suspend fun ambilStatus(): Long {
        return try {
            // Respon polling rutin = ping ke server yang dipakai (tanpa permintaan tambahan)
            val mulai = System.nanoTime()
            val (status, mentah) = api.status()
            val ms = (System.nanoTime() - mulai) / 1_000_000
            terapkanStatus(status, mentah)
            catatPing(simpan.serverDipakai(), ms)
            gagalBeruntun = 0
            // QRIS sedang ditampilkan: cek lebih sering supaya TV terbuka beberapa detik setelah pelanggan bayar
            if (status.bayarMandiri?.tagihan != null) 4_000L else status.pollDetik.coerceIn(5, 120) * 1000L
        } catch (e: PerluPairing) {
            // Hanya server tempat TV dipasangkan yang boleh mencabut TV. Server lain (mis. cloud yang
            // belum tersinkron) menolak token -> anggap tidak terjangkau, pakai server lain.
            if (bolehDicabutOleh(simpan.serverDipakai())) {
                Log.i(TAG, "Token ditolak server pairing, kembali ke pairing")
                simpan.hapusKredensial()
                mulaiUlang()
                Long.MAX_VALUE
            } else {
                gagal("Server ${labelServer(simpan.serverDipakai())} belum mengenal TV ini")
            }
        } catch (e: Exception) {
            Log.w(TAG, "Status: ${e.message}")
            gagal(pesanError(e))
        }
    }

    /** Server tidak menjawab: setelah 3x beruntun (±30 detik) pindah ke server lain (lokal ↔ cloud) */
    private suspend fun gagal(pesan: String): Long {
        gagalBeruntun++
        _keadaan.update { it.copy(offline = true, pesan = pesan) }
        catatPing(simpan.serverDipakai(), -1)

        if (gagalBeruntun >= 3 && pindahServer()) {
            gagalBeruntun = 0
            return 1_000L
        }
        return 10_000L // offline: coba lagi lebih sering
    }

    private fun terapkanStatus(status: StatusTv, mentah: String) {
        Jam.setel(status.serverTimeMs)
        simpan.statusTerakhir = mentah
        status.server?.let { srv ->
            // Admin yang menentukan: alamat dikosongkan di admin = dihapus juga di TV
            simpan.serverLokal = srv.lokal
            simpan.serverCloud = srv.cloud
        }
        // TV yang dipasangkan sebelum ada failover: server yang pertama menjawab dianggap server pairing
        if (simpan.serverPairing == null) simpan.serverPairing = simpan.serverDipakai()
        _keadaan.update {
            it.copy(
                status = status, offline = false, pesan = null, server = labelServer(simpan.serverDipakai()),
                pingCloudMs = if (simpan.serverCloud == null) null else it.pingCloudMs,
            )
        }
        realtime.pastikan(status.realtime)
        status.perintah.forEach { jalankanPerintah(it) } // cadangan jika websocket putus

        // TV hanya punya satu input HDMI: langsung dipakai tanpa perlu dipilih
        if (status.pengaturan.inputHdmi == null) {
            Hdmi.daftarInput(ctx).singleOrNull()?.let { scope.launch { pilihInputHdmi(it) } }
        }
    }

    /* ================= Failover lokal ↔ cloud ================= */

    private var gagalBeruntun = 0

    /** Urutan server: lokal (utama) → cloud (cadangan) → alamat saat setup */
    private fun daftarServer(): List<String> =
        listOfNotNull(simpan.serverLokal, simpan.serverCloud, simpan.serverUrl).map { it.trimEnd('/') }.distinct()

    fun labelServer(url: String?): String {
        val u = url?.trimEnd('/') ?: return "-"
        return when (u) {
            simpan.serverCloud?.trimEnd('/') -> "cloud"
            simpan.serverLokal?.trimEnd('/'), simpan.serverUrl?.trimEnd('/') -> "lokal"
            else -> "lain"
        }
    }

    private fun bolehDicabutOleh(url: String?): Boolean {
        val pairing = simpan.serverPairing ?: return true // TV lama (sebelum ada failover)
        return url == pairing
    }

    /** Coba server lain secara berurutan. Return true jika berhasil pindah. */
    private suspend fun pindahServer(): Boolean {
        val aktif = simpan.serverDipakai()
        for (kandidat in daftarServer().filter { it != aktif }) {
            try {
                val mulai = System.nanoTime()
                val (status, mentah) = api.statusDari(kandidat)
                val ms = (System.nanoTime() - mulai) / 1_000_000
                Log.i(TAG, "Failover: $aktif -> $kandidat (${labelServer(kandidat)})")
                simpan.serverAktif = kandidat
                terapkanStatus(status, mentah)
                catatPing(kandidat, ms)
                return true
            } catch (e: Exception) {
                Log.w(TAG, "Failover: $kandidat tidak bisa dipakai (${e.message})")
                catatPing(kandidat, -1)
            }
        }
        return false
    }

    /** Saat memakai server cadangan, cek server lokal tiap 2 menit & kembali jika sudah hidup */
    private suspend fun loopKembaliKeLokal() {
        while (true) {
            delay(120_000)
            val lokal = (simpan.serverLokal ?: simpan.serverUrl)?.trimEnd('/') ?: continue
            if (simpan.serverDipakai() == lokal) continue

            try {
                val mulai = System.nanoTime()
                val (status, mentah) = api.statusDari(lokal)
                val ms = (System.nanoTime() - mulai) / 1_000_000
                Log.i(TAG, "Server lokal hidup lagi, kembali ke $lokal")
                simpan.serverAktif = lokal
                gagalBeruntun = 0
                terapkanStatus(status, mentah)
                catatPing(lokal, ms)
            } catch (e: Exception) {
                // masih mati, tetap di cloud
                catatPing(lokal, -1)
            }
        }
    }

    /**
     * Saat memakai server lokal: cek server cloud tiap 5 menit lewat GET /api/ping (ringan, tanpa token).
     * Saat memakai cloud, ping cloud diukur dari polling rutin & lokal dari [loopKembaliKeLokal].
     */
    private suspend fun loopPingCloud() {
        delay(15_000)
        while (true) {
            val cloud = simpan.serverCloud?.trimEnd('/')
            if (cloud == null) {
                _keadaan.update { it.copy(pingCloudMs = null) }
            } else if (simpan.serverDipakai()?.trimEnd('/') != cloud) {
                val ms = try { api.ping(cloud) } catch (e: Exception) { -1L }
                catatPing(cloud, ms)
            }
            delay(INTERVAL_PING_CLOUD_MS)
        }
    }

    /** Simpan respon (ms, -1 = tidak terjangkau) untuk baris info di timer & laporan heartbeat */
    private fun catatPing(url: String?, ms: Long) {
        if (url == null) return
        val nilai = if (ms < 0) -1L else ms.coerceAtMost(60_000)
        if (labelServer(url.trimEnd('/')) == "cloud") {
            _keadaan.update { it.copy(pingCloudMs = nilai) }
        } else {
            _keadaan.update { it.copy(pingLokalMs = nilai) }
        }
    }

    /** Perintah remote dari kasir: sekali saja per id, abaikan yang lebih dari 2 menit */
    @Synchronized
    private fun jalankanPerintah(p: PerintahRemote) {
        if (simpan.sudahDijalankan(p.id)) return
        simpan.tandaiDijalankan(p.id)

        if (p.waktuMs > 0 && Jam.sekarang() - p.waktuMs > 120_000) return

        val app = ctx.applicationContext as id.rentalps.tvagent.AgentApp

        // Lock dari kasir: bypass sudah diakhiri server; batalkan izin keluar / status ditutup & tampilkan layar kunci
        if (p.perintah == "kunci") {
            app.kunciLagi()
            akhiriDarurat()
            Remote.laporan = "dikunci dari kasir"
            segarkanSekarang()
            runCatching {
                ctx.startActivity(
                    android.content.Intent(ctx, id.rentalps.tvagent.ui.MainActivity::class.java).addFlags(
                        android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or
                            android.content.Intent.FLAG_ACTIVITY_SINGLE_TOP,
                    ),
                )
            }
            sinyalHeartbeat.trySend(Unit)
            return
        }

        // Tutup aplikasi dari kasir: TV bebas (layar Google TV) sampai Lock / sesi berikutnya
        if (p.perintah == "tutup_aplikasi") {
            app.tutupAplikasi() // layar TV Agent pindah sendiri ke Google TV
            Remote.laporan = "aplikasi ditutup dari kasir"
            sinyalHeartbeat.trySend(Unit)
            return
        }

        // Pindah HDMI dari kasir (TV berisi beberapa konsol): simpan pilihan; TV yang sedang terbuka langsung pindah
        if (p.perintah == "pindah_hdmi") {
            val id = p.data?.get("id")?.let { runCatching { it.jsonPrimitive.content }.getOrNull() }
            if (!id.isNullOrBlank()) {
                simpan.inputHdmi = id
                if (_keadaan.value.layar in LAYAR_TERBUKA) _pindahHdmi.tryEmit(id)
                Remote.laporan = "pindah HDMI: " + (p.data?.get("label")?.let { runCatching { it.jsonPrimitive.content }.getOrNull() } ?: id)
                segarkanSekarang() // ambil status dengan input_hdmi baru
            }
            sinyalHeartbeat.trySend(Unit)
            return
        }

        // Pemberitahuan dari kasir: tampil di tengah layar beberapa detik
        if (p.perintah == "pemberitahuan") {
            val isi = p.data?.let { runCatching { JsonApi.decodeFromJsonElement(id.rentalps.tvagent.data.Pemberitahuan.serializer(), it) }.getOrNull() }
            if (isi != null && isi.teks.isNotBlank()) {
                _pemberitahuan.tryEmit(isi)
                Remote.laporan = "pemberitahuan ditampilkan"
            }
            return
        }

        // Push update dari admin: "paksa" dipasang walau TV sedang dipakai
        if (p.perintah == "update_aplikasi" || p.perintah == "update_aplikasi_paksa") {
            val paksa = p.perintah == "update_aplikasi_paksa"
            Remote.laporan = "update diminta admin" + if (paksa) " (sekarang)" else " (saat TV kosong)"
            scope.launch { cekUpdate(paksa) }
            return
        }

        Remote.jalankan(ctx, p.perintah)
        sinyalHeartbeat.trySend(Unit) // laporkan volume/layar terbaru ke kasir
    }

    private suspend fun loopHeartbeat() {
        while (true) {
            try {
                val data = buildJsonObject {
                    put("versi_app", JsonPrimitive(BuildConfig.VERSION_NAME))
                    put("versi_android", JsonPrimitive(Build.VERSION.RELEASE))
                    // "tutup" = aplikasi ditutup staf/kasir (TV bebas sampai Lock); ditampilkan di panel TV kasir
                    val tertutup = (ctx.applicationContext as id.rentalps.tvagent.AgentApp).tertutup.value
                    put("layar", JsonPrimitive(if (tertutup) "tutup" else _keadaan.value.layar))
                    // Respon ke server lokal & cloud (ms, -1 = tidak terjangkau) → Admin → Perangkat TV
                    val k = _keadaan.value
                    put("ping_lokal_ms", k.pingLokalMs?.let { JsonPrimitive(it) } ?: JsonNull)
                    put("ping_cloud_ms", k.pingCloudMs?.let { JsonPrimitive(it) } ?: JsonNull)
                    put("server_dipakai", JsonPrimitive(if (k.server == "cloud") "cloud" else "lokal"))
                    runCatching {
                        put("volume", JsonPrimitive(Remote.volumePersen(ctx)))
                        put("senyap", JsonPrimitive(Remote.senyap(ctx)))
                        put("layar_hidup", JsonPrimitive(Remote.layarHidup(ctx)))
                    }

                    // Diagnostik dikirim saat berubah, atau tiap 10 menit
                    val diag = Diagnostik.kumpulkan(ctx)
                    val teks = diag.toString()
                    if (teks != diagnostikTerakhir || System.currentTimeMillis() - terakhirKirimDiagnostikMs > 600_000) {
                        put("diagnostik", diag)
                        diagnostikTerakhir = teks
                        terakhirKirimDiagnostikMs = System.currentTimeMillis()
                    }
                }
                api.heartbeat(data)
            } catch (e: PerluPairing) {
                return
            } catch (e: Exception) {
                diagnostikTerakhir = null // kirim ulang saat tersambung lagi
            }
            // Tiap 30 detik, atau segera setelah perintah remote dijalankan
            withTimeoutOrNull(30_000) { sinyalHeartbeat.receive() }
            delay(500)
        }
    }

    private suspend fun loopUpdate() {
        delay(20_000)
        while (true) {
            cekUpdate()
            delay(6 * 60 * 60 * 1000L)
        }
    }

    /** Ada update yang ditunda karena TV sedang dipakai: dipasang begitu TV kembali terkunci */
    @Volatile
    private var updateTertunda = false

    /** Pasang update hanya saat TV terkunci (tidak mengganggu yang main), kecuali update wajib / dipaksa admin */
    private suspend fun cekUpdate(paksa: Boolean = false) {
        val info = updater.cek()

        if (info == null) {
            updateTertunda = false
            return
        }

        if (paksa || info.wajib || _keadaan.value.layar in LAYAR_TERKUNCI) {
            updateTertunda = false
            if (!updater.pasang(info)) updateTertunda = true // gagal unduh: coba lagi saat TV terkunci berikutnya
        } else {
            updateTertunda = true
        }
    }

    /* ================= Timer lokal ================= */

    private suspend fun loopDetik() {
        while (scope.isActive) {
            val sebelum = _keadaan.value.layar
            val layar = hitungLayar(_keadaan.value)
            if (layar != sebelum) {
                // Status gagal HDMI hanya berlaku untuk sesi yang sedang dibuka
                _keadaan.update { it.copy(layar = layar, hdmiGagal = it.hdmiGagal && layar in LAYAR_TERBUKA) }

                // Sesi selesai & TV kosong lagi: pasang update yang tadi ditunda
                if (updateTertunda && layar in LAYAR_TERKUNCI && sebelum !in LAYAR_TERKUNCI) {
                    updateTertunda = false
                    scope.launch { delay(3_000); cekUpdate() }
                }
            }
            periksaPeringatan(sebelum, layar)
            delay(1_000)
        }
    }

    /**
     * Sekali per sesi & jam berakhir: masuk batas peringatan (mis. 5 menit), sisa 1 menit, dan waktu habis.
     * Tambah waktu mengubah jam berakhir, jadi peringatan berikutnya tetap muncul.
     */
    private fun periksaPeringatan(sebelum: String, layar: String) {
        val s = _keadaan.value.status ?: return
        val sesi = s.sesi ?: return
        val bunyi = s.pengaturan.suaraAktif
        val sekarang = Jam.sekarang()

        fun kirim(jenis: Peringatan.Jenis, menit: Int, kunci: Long) {
            if (peringatanTerkirim.add("${sesi.id}|$kunci|$jenis")) {
                _peringatan.tryEmit(Peringatan(jenis, menit, bunyi))
            }
        }

        // Waktu pilih game: tidak ada peringatan; saat lewat -> "waktu bermain dimulai"
        if (layar == "main" && sekarang < sesi.mulaiMs) {
            peringatanTerkirim.add("${sesi.id}|${sesi.mulaiMs}|pilih") // tanda: sesi ini punya waktu pilih game
            return
        }
        if (layar == "main" && "${sesi.id}|${sesi.mulaiMs}|pilih" in peringatanTerkirim && sekarang - sesi.mulaiMs < 10_000) {
            kirim(Peringatan.Jenis.MULAI, 0, sesi.mulaiMs)
        }

        val berakhir = sesi.berakhirMs ?: return
        if (sesi.mode != "paket") return

        val menitPeringatan = s.pengaturan.peringatanMenit
        val sisa = (berakhir - sekarang) / 1000

        fun kirimSisa(jenis: Peringatan.Jenis, menit: Int) = kirim(jenis, menit, berakhir)

        when {
            sebelum == "main" && layar == "habis" -> kirimSisa(Peringatan.Jenis.HABIS, 0)
            layar != "main" -> Unit
            sisa in 1..60 -> kirimSisa(Peringatan.Jenis.SATU_MENIT, 1)
            menitPeringatan > 1 && sisa in 61..(menitPeringatan * 60L) -> kirimSisa(Peringatan.Jenis.SISA, menitPeringatan)
        }
    }

    private fun hitungLayar(k: Keadaan): String {
        if (k.tahap != Tahap.Aktif) return "memuat"
        val s = k.status ?: return "memuat"
        val sekarang = Jam.sekarang()
        var layar = s.layar

        // Paket habis dihitung lokal (tetap terkunci walau server tidak bisa dihubungi)
        val sesi = s.sesi
        if (layar == "main" && sesi?.mode == "paket" && sesi.berakhirMs != null && sekarang >= sesi.berakhirMs) {
            layar = "habis"
        }

        // Bypass kedaluwarsa
        if (layar == "bypass" && (s.perangkat.bypassSampaiMs ?: 0) <= sekarang) {
            layar = "kunci"
        }

        // Buka darurat (kode offline benar)
        if (layar in LAYAR_TERKUNCI && k.daruratSampaiMs > sekarang) {
            layar = "darurat"
        }

        return layar
    }

    /* ================= Aksi staf ================= */

    /** Return pesan error, atau null jika berhasil */
    suspend fun bypass(pin: String, menit: Int? = null): String? = try {
        val (status, mentah) = api.bypass(pin, menit)
        Jam.setel(status.serverTimeMs)
        simpan.statusTerakhir = mentah
        _keadaan.update { it.copy(status = status, offline = false) }
        null
    } catch (e: Exception) {
        pesanError(e)
    }

    suspend fun akhiriBypass(): String? = try {
        val (status, mentah) = api.akhiriBypass()
        simpan.statusTerakhir = mentah
        _keadaan.update { it.copy(status = status) }
        null
    } catch (e: Exception) {
        pesanError(e)
    }

    /* ---------------- Input HDMI PS ---------------- */

    /** Input pilihan: dari server (diatur admin / saat setup), cadangan dari penyimpanan TV */
    fun inputHdmi(): String? = _keadaan.value.status?.pengaturan?.inputHdmi ?: simpan.inputHdmi

    /** Setup belum selesai: TV punya >1 input tapi belum ditentukan PS di mana */
    fun perluPilihHdmi(): Boolean = inputHdmi() == null && Hdmi.daftarInput(ctx).size > 1

    fun setHdmiGagal(gagal: Boolean) {
        _keadaan.update { it.copy(hdmiGagal = gagal) }
    }

    /** Simpan input PS di TV & server. Return pesan error, atau null jika berhasil */
    suspend fun pilihInputHdmi(input: Hdmi.Input): String? {
        simpan.inputHdmi = input.id
        return try {
            val (status, mentah) = api.simpanInputHdmi(input.id, input.label)
            simpan.statusTerakhir = mentah
            _keadaan.update { it.copy(status = status, hdmiGagal = false) }
            null
        } catch (e: Exception) {
            "Tersimpan di TV, tapi gagal dikirim ke server: ${pesanError(e)}"
        }
    }

    /** Return pesan untuk pelanggan (berhasil atau gagal) */
    suspend fun panggilKasir(): String = try {
        api.panggilKasir()
    } catch (e: Exception) {
        "Gagal memanggil kasir: ${pesanError(e)}. Silakan ke meja kasir."
    }

    /**
     * Gerbang aksi staf berbahaya (ganti server, izin, input HDMI).
     * Kode darurat dicek di TV (tetap bisa saat server mati); selain itu PIN dicek ke server.
     * Return pesan error, atau null jika diizinkan.
     */
    suspend fun verifikasiStaf(isian: String): String? {
        val rahasia = simpan.rahasiaOffline
        if (isian.length == 6 && rahasia != null && KodeDarurat.cocok(rahasia, isian, Jam.sekarang() / 1000)) {
            return null
        }
        return try {
            api.verifikasiPin(isian)
            null
        } catch (e: Exception) {
            pesanError(e)
        }
    }

    /** Kode darurat dicek di TV (tanpa server). Return true jika benar. */
    fun bukaDarurat(kode: String): Boolean {
        val rahasia = simpan.rahasiaOffline ?: return false
        val sekarang = Jam.sekarang()

        if (!KodeDarurat.cocok(rahasia, kode, sekarang / 1000)) return false

        val menit = _keadaan.value.status?.pengaturan?.durasiBypassMenit ?: 15
        val sampai = sekarang + menit * 60_000L
        simpan.daruratSampaiMs = sampai
        _keadaan.update { it.copy(daruratSampaiMs = sampai) }
        return true
    }

    fun akhiriDarurat() {
        simpan.daruratSampaiMs = 0
        _keadaan.update { it.copy(daruratSampaiMs = 0) }
    }

    /** Pesan singkat untuk layar (dilihat pelanggan); detail teknis cukup di log */
    private fun pesanError(e: Exception): String {
        Log.w(TAG, "Error: ${e.javaClass.simpleName}: ${e.message}")
        return when (e) {
            is ApiError -> (e.message ?: "Server menolak").take(80)
            is IOException -> "server tidak terjangkau"
            is kotlinx.serialization.SerializationException -> "jawaban server tidak lengkap, mencoba lagi"
            else -> "gangguan koneksi, mencoba lagi"
        }
    }

    companion object {
        const val TAG = "TvAgent"

        /** Cek ringan server cloud (cadangan) saat memakai server lokal */
        const val INTERVAL_PING_CLOUD_MS = 5 * 60 * 1000L

        /** Tampilan yang menutup layar (pelanggan tidak bisa main) */
        val LAYAR_TERKUNCI = setOf("kunci", "habis", "menunggu_bayar", "servis", "belum_ada_unit", "jeda")

        /** Tampilan yang membuka HDMI */
        val LAYAR_TERBUKA = setOf("main", "bypass", "darurat")
    }
}
