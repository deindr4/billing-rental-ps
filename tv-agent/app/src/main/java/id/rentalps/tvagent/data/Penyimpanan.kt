package id.rentalps.tvagent.data

import android.content.Context
import android.content.SharedPreferences

/**
 * Data yang harus bertahan walau TV dimatikan: alamat server, token, rahasia kode darurat,
 * dan status terakhir (dipakai saat server tidak bisa dihubungi).
 * Disimpan di penyimpanan privat aplikasi (tidak bisa dibaca aplikasi lain).
 */
class Penyimpanan(context: Context) {
    private val prefs: SharedPreferences = context.getSharedPreferences("tv_agent", Context.MODE_PRIVATE)

    var serverUrl: String?
        get() = prefs.getString("server_url", null)
        set(nilai) = prefs.edit().putString("server_url", nilai?.trimEnd('/')).apply()

    /* Failover: alamat dari server (Admin → Operasional) + server yang sedang dipakai */

    var serverLokal: String?
        get() = prefs.getString("server_lokal", null)
        set(nilai) = prefs.edit().putString("server_lokal", nilai?.trimEnd('/')).apply()

    var serverCloud: String?
        get() = prefs.getString("server_cloud", null)
        set(nilai) = prefs.edit().putString("server_cloud", nilai?.trimEnd('/')).apply()

    /** Server yang sedang dipakai; kosong = alamat yang diisi saat setup */
    var serverAktif: String?
        get() = prefs.getString("server_aktif", null)
        set(nilai) = prefs.edit().putString("server_aktif", nilai?.trimEnd('/')).apply()

    /** Server tempat TV dipasangkan: hanya server ini yang boleh "mencabut" TV (401 → pairing ulang) */
    var serverPairing: String?
        get() = prefs.getString("server_pairing", null)
        set(nilai) = prefs.edit().putString("server_pairing", nilai?.trimEnd('/')).apply()

    fun serverDipakai(): String? = serverAktif ?: serverUrl

    var token: String?
        get() = prefs.getString("token", null)
        set(nilai) = prefs.edit().putString("token", nilai).apply()

    var rahasiaOffline: String?
        get() = prefs.getString("rahasia_offline", null)
        set(nilai) = prefs.edit().putString("rahasia_offline", nilai).apply()

    var perangkatId: String?
        get() = prefs.getString("perangkat_id", null)
        set(nilai) = prefs.edit().putString("perangkat_id", nilai).apply()

    /** JSON status terakhir dari server */
    var statusTerakhir: String?
        get() = prefs.getString("status_terakhir", null)
        set(nilai) = prefs.edit().putString("status_terakhir", nilai).apply()

    /** Input HDMI pilihan (id TvInput); null = HDMI pertama yang ditemukan */
    var inputHdmi: String?
        get() = prefs.getString("input_hdmi", null)
        set(nilai) = prefs.edit().putString("input_hdmi", nilai).apply()

    /** Batas waktu buka darurat (ms, jam server) setelah kode darurat benar */
    var daruratSampaiMs: Long
        get() = prefs.getLong("darurat_sampai", 0L)
        set(nilai) = prefs.edit().putLong("darurat_sampai", nilai).apply()

    /**
     * Id perintah remote yang sudah dijalankan (disimpan permanen supaya perintah restart
     * tidak dijalankan ulang setelah aplikasi hidup kembali). Hanya 30 terakhir.
     */
    fun sudahDijalankan(id: String): Boolean = id in (prefs.getString("perintah_selesai", "") ?: "").split(',')

    fun tandaiDijalankan(id: String) {
        val daftar = (prefs.getString("perintah_selesai", "") ?: "").split(',').filter { it.isNotBlank() }.takeLast(29) + id
        prefs.edit().putString("perintah_selesai", daftar.joinToString(",")).commit() // commit: harus tersimpan sebelum restart
    }

    fun terdaftar(): Boolean = !serverUrl.isNullOrBlank() && !token.isNullOrBlank()

    /** TV dicabut / dipasangkan ulang: hapus kredensial, alamat server tetap */
    fun hapusKredensial() {
        prefs.edit()
            .remove("token")
            .remove("rahasia_offline")
            .remove("perangkat_id")
            .remove("status_terakhir")
            .remove("darurat_sampai")
            .remove("server_aktif")
            .remove("server_pairing")
            .apply()
    }
}
