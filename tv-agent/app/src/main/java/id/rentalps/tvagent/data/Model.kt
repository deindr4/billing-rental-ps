package id.rentalps.tvagent.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject

/** Kontrak lengkap ada di docs/tv-agent-api.md (repo billing). */

val JsonApi = Json {
    ignoreUnknownKeys = true
    explicitNulls = false
    encodeDefaults = true
}

@Serializable
data class PairingMulai(
    val kode: String,
    val kunci: String,
    @SerialName("kedaluwarsa_detik") val kedaluwarsaDetik: Int,
    @SerialName("interval_cek_detik") val intervalCekDetik: Int = 3,
)

@Serializable
data class PairingHasil(
    val status: String,
    val token: String? = null,
    @SerialName("rahasia_offline") val rahasiaOffline: String? = null,
    @SerialName("perangkat_id") val perangkatId: String? = null,
    @SerialName("kedaluwarsa_detik") val kedaluwarsaDetik: Int? = null,
)

@Serializable
data class StatusTv(
    @SerialName("server_time_ms") val serverTimeMs: Long,
    @SerialName("poll_detik") val pollDetik: Int = 15,
    val layar: String,
    val perangkat: InfoPerangkat,
    val unit: InfoUnit? = null,
    val sesi: InfoSesi? = null,
    val pengaturan: Pengaturan,
    val tema: Tema,
    /** Teks berjalan di kaki layar (Admin → Operasional) */
    val pengumuman: String? = null,
    /** Running text promo dari kasir, melayang di atas layar kunci & game (null = mati) */
    @SerialName("running_text") val runningText: RunningText? = null,
    /** Alamat server lokal & cloud untuk failover */
    val server: InfoServer? = null,
    /** Perintah remote yang belum kedaluwarsa (cadangan jika websocket putus) */
    val perintah: List<PerintahRemote> = emptyList(),
    val realtime: InfoRealtime? = null,
    /** Bayar mandiri QRIS di TV (null = tidak tersedia: kas tutup, fitur mati, unit dipakai) */
    @SerialName("bayar_mandiri") val bayarMandiri: BayarMandiri? = null,
)

@Serializable
data class BayarMandiri(
    /** mulai | isi_ulang */
    val jenis: String,
    /** Halaman HP untuk mengetik nominal (isi QR "scan untuk main") */
    val url: String,
    @SerialName("tarif_per_jam") val tarifPerJam: Long? = null,
    @SerialName("minimal_menit") val minimalMenit: Int = 30,
    /** QRIS nominal yang sedang menunggu dibayar */
    val tagihan: TagihanQris? = null,
)

@Serializable
data class TagihanQris(
    val id: String,
    val qris: String,
    /** qris = string QRIS | tautan = halaman bayar gateway (DOKU), dipindai dengan kamera HP */
    val tipe: String = "qris",
    val nominal: Long,
    val menit: Int,
    val label: String,
    @SerialName("kedaluwarsa_ms") val kedaluwarsaMs: Long,
)

@Serializable
data class InfoServer(
    val lokal: String? = null,
    val cloud: String? = null,
    /** Server yang menjawab: "lokal" | "cloud" */
    val asal: String? = null,
)

@Serializable
data class PerintahRemote(
    val id: String,
    val perintah: String,
    @SerialName("waktu_ms") val waktuMs: Long = 0,
    /** Isi tambahan perintah (mis. pemberitahuan: teks, detik, ukuran, huruf, tebal) */
    val data: JsonObject? = null,
)

/** Pemberitahuan di tengah layar TV (perintah remote "pemberitahuan") */
@Serializable
data class Pemberitahuan(
    val teks: String,
    val detik: Int = 10,
    /** sedang | besar | jumbo */
    val ukuran: String = "besar",
    /** sans | serif | mono */
    val huruf: String = "sans",
    val tebal: Boolean = true,
)

@Serializable
data class RunningText(
    val teks: String,
    /** null = sampai dimatikan kasir */
    @SerialName("sampai_ms") val sampaiMs: Long? = null,
    /** Disembunyikan saat unit sedang dimainkan (layar main / jeda) */
    @SerialName("sembunyi_saat_main") val sembunyiSaatMain: Boolean = true,
    /** atas | bawah */
    val posisi: String = "bawah",
    /** Kepekatan latar strip 0–100 */
    val opasitas: Int = 60,
    /** kecil | sedang | besar */
    val ukuran: String = "sedang",
    val tebal: Boolean = false,
    /** lambat | sedang | cepat */
    val kecepatan: String = "sedang",
    /** #RRGGBB */
    val warna: String = "#FFFFFF",
)

@Serializable
data class InfoPerangkat(
    val id: String,
    val nama: String,
    @SerialName("bypass_sampai_ms") val bypassSampaiMs: Long? = null,
)

@Serializable
data class InfoUnit(
    val id: String,
    val kode: String,
    val nama: String,
    val status: String,
    val konsol: String? = null,
    @SerialName("konsol_kode") val konsolKode: String? = null,
    val kategori: String? = null,
    val lokasi: String? = null,
    @SerialName("tarif_per_jam") val tarifPerJam: Long? = null,
)

@Serializable
data class InfoSesi(
    val id: String = "",
    val mode: String,
    val status: String,
    val paket: String? = null,
    @SerialName("mulai_ms") val mulaiMs: Long,
    @SerialName("berakhir_ms") val berakhirMs: Long? = null,
    @SerialName("dijeda_ms") val dijedaMs: Long? = null,
    @SerialName("selesai_ms") val selesaiMs: Long? = null,
    @SerialName("total_jeda_detik") val totalJedaDetik: Long = 0,
    @SerialName("versi_tagihan") val versiTagihan: Int = 0,
    val tagihan: Tagihan? = null,
)

@Serializable
data class Tagihan(
    val nomor: String? = null,
    val pelanggan: String? = null,
    val total: Long = 0,
    @SerialName("estimasi_sewa") val estimasiSewa: Long? = null,
    val lunas: Boolean = false,
    val subtotal: Long = 0,
    @SerialName("total_diskon") val totalDiskon: Long = 0,
    val sisa: Long = 0,
    val items: List<ItemTagihan> = emptyList(),
    val diskon: List<DiskonTagihan> = emptyList(),
)

@Serializable
data class ItemTagihan(
    val nama: String,
    val jenis: String,
    val label: String? = null,
    val qty: Int = 1,
    @SerialName("harga_satuan") val hargaSatuan: Long = 0,
    val subtotal: Long = 0,
    val keterangan: String? = null,
)

@Serializable
data class DiskonTagihan(val nama: String, val nilai: Long)

@Serializable
data class Pengaturan(
    @SerialName("posisi_timer") val posisiTimer: String = "kanan_atas",
    @SerialName("peringatan_menit") val peringatanMenit: Int = 5,
    @SerialName("transparansi_lock") val transparansiLock: Int = 85,
    /** Kepekatan timer melayang (30–100%), juga saat waktu hampir habis (APK >= 0.6.3) */
    @SerialName("opasitas_timer") val opasitasTimer: Int = 90,
    /** Ukuran timer melayang: kecil | sedang | besar */
    @SerialName("ukuran_timer") val ukuranTimer: String = "sedang",
    /** Warna angka timer saat waktu masih banyak (#RRGGBB); hampir habis selalu merah */
    @SerialName("warna_timer") val warnaTimer: String? = null,
    /** Baris kecil di bawah timer: versi APK + respon ke server lokal/cloud (server lama tidak mengirim = sembunyi) */
    @SerialName("info_teknis") val infoTeknis: Boolean = false,
    /** Saat main hanya Volume, Home & OK yang diteruskan ke PS (butuh izin Aksesibilitas, APK >= 0.6.6) */
    @SerialName("kunci_remote") val kunciRemote: Boolean = false,
    @SerialName("durasi_bypass_menit") val durasiBypassMenit: Int = 15,
    @SerialName("bypass_maks_menit") val bypassMaksMenit: Int = 120,
    @SerialName("bypass_pilihan") val bypassPilihan: List<Int> = listOf(15, 30, 60),
    /** Input HDMI tempat PS tersambung (disimpan di server per TV) */
    @SerialName("input_hdmi") val inputHdmi: String? = null,
    @SerialName("input_hdmi_label") val inputHdmiLabel: String? = null,
    /** Aplikasi TV yang boleh dibuka saat bypass (selain PS/HDMI) */
    val aplikasi: List<AplikasiTv> = emptyList(),
    @SerialName("suara_aktif") val suaraAktif: Boolean = true,
)

@Serializable
data class AplikasiTv(val nama: String, val paket: String)

@Serializable
data class Tema(
    @SerialName("nama_rental") val namaRental: String,
    val cabang: String? = null,
    @SerialName("logo_url") val logoUrl: String? = null,
    /** Gambar latar layar kunci (khusus unit atau default tenant) */
    @SerialName("wallpaper_url") val wallpaperUrl: String? = null,
    val mode: String? = null,
    val aksen: String? = null,
    @SerialName("zona_waktu") val zonaWaktu: String? = null,
    @SerialName("zona_label") val zonaLabel: String? = null,
)

@Serializable
data class InfoRealtime(
    val key: String? = null,
    val host: String,
    val port: Int,
    val scheme: String = "http",
    val channel: String,
    val event: String = ".segarkan",
    @SerialName("auth_url") val authUrl: String,
)

@Serializable
data class InfoUpdate(
    @SerialName("ada_update") val adaUpdate: Boolean,
    @SerialName("versi_nama") val versiNama: String? = null,
    @SerialName("versi_kode") val versiKode: Int? = null,
    val ukuran: Long? = null,
    val sha256: String? = null,
    val wajib: Boolean = false,
    val catatan: String? = null,
    val url: String? = null,
)

@Serializable
data class PesanError(val pesan: String? = null, val kode: String? = null, val message: String? = null)
