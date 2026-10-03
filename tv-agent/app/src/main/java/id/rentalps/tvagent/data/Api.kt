package id.rentalps.tvagent.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Server menolak token (TV dicabut / belum dipasangkan) */
class PerluPairing : Exception("TV perlu dipasangkan ulang")

/** Server menjawab error (4xx/5xx) dengan pesan dari server */
class ApiError(val status: Int, pesan: String) : Exception(pesan)

/**
 * Klien HTTP ke server billing. Semua fungsi suspend & dijalankan di thread IO.
 * IOException = server tidak bisa dihubungi (jaringan putus / server mati).
 */
class Api(private val simpan: Penyimpanan) {

    val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(5, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .pingInterval(30, TimeUnit.SECONDS)
        .build()

    private val jenisJson = "application/json; charset=utf-8".toMediaType()

    /** Semua request memakai server yang sedang aktif (lokal, atau cloud saat failover) */
    private fun url(jalur: String): String {
        val dasar = simpan.serverDipakai() ?: throw IOException("Alamat server belum diisi")
        return "$dasar/api/tv$jalur"
    }

    private fun permintaan(jalur: String, pakaiToken: Boolean = true): Request.Builder {
        val b = Request.Builder().url(url(jalur)).header("Accept", "application/json")
        if (pakaiToken) {
            simpan.token?.let { b.header("Authorization", "Bearer $it") }
        }
        return b
    }

    private suspend fun kirim(req: Request): String = withContext(Dispatchers.IO) {
        http.newCall(req).execute().use { res -> bacaJawaban(res) }
    }

    private fun bacaJawaban(res: Response): String {
        val isi = res.body?.string().orEmpty()

        if (res.code == 401) {
            throw PerluPairing()
        }

        if (!res.isSuccessful) {
            val err = runCatching { JsonApi.decodeFromString(PesanError.serializer(), isi) }.getOrNull()
            throw ApiError(res.code, err?.pesan ?: err?.message ?: "Server menjawab ${res.code}")
        }

        return isi
    }

    private fun jsonBody(data: JsonObject) = data.toString().toRequestBody(jenisJson)

    private fun objek(vararg pasangan: Pair<String, String?>): JsonObject = buildJsonObject {
        pasangan.forEach { (k, v) -> if (v != null) put(k, JsonPrimitive(v)) }
    }

    /* ---------------- Pairing ---------------- */

    suspend fun mulaiPairing(info: Map<String, String?>): PairingMulai {
        val body = jsonBody(objek(*info.toList().toTypedArray()))
        val isi = kirim(permintaan("/pairing", pakaiToken = false).post(body).build())
        return JsonApi.decodeFromString(PairingMulai.serializer(), isi)
    }

    /** 404/410 dikembalikan sebagai status, bukan exception */
    suspend fun cekPairing(kunci: String): PairingHasil = withContext(Dispatchers.IO) {
        val req = permintaan("/pairing/cek", pakaiToken = false).post(jsonBody(objek("kunci" to kunci))).build()
        http.newCall(req).execute().use { res ->
            val isi = res.body?.string().orEmpty()
            when (res.code) {
                200, 404, 410 -> JsonApi.decodeFromString(PairingHasil.serializer(), isi)
                else -> throw ApiError(res.code, "Server menjawab ${res.code}")
            }
        }
    }

    /* ---------------- TV terdaftar ---------------- */

    /** Return (objek status, JSON mentah untuk disimpan sebagai cadangan offline) */
    suspend fun status(): Pair<StatusTv, String> = ulangJikaTerpotong {
        val isi = kirim(permintaan("/status").get().build())
        JsonApi.decodeFromString(StatusTv.serializer(), isi) to isi
    }

    /**
     * Jawaban kadang terpotong (koneksi diputus server di tengah jalan): ulangi sekali dengan koneksi baru
     * sebelum dianggap gagal, supaya tidak memicu failover palsu.
     */
    private suspend fun <T> ulangJikaTerpotong(aksi: suspend () -> T): T = try {
        aksi()
    } catch (e: kotlinx.serialization.SerializationException) {
        http.connectionPool.evictAll()
        aksi()
    }

    /** Cek server lain (failover): GET /status ke alamat tertentu dengan token TV ini */
    suspend fun statusDari(dasar: String): Pair<StatusTv, String> = ulangJikaTerpotong {
        val req = Request.Builder()
            .url("${dasar.trimEnd('/')}/api/tv/status")
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${simpan.token}")
            .get()
            .build()
        val isi = kirim(req)
        JsonApi.decodeFromString(StatusTv.serializer(), isi) to isi
    }

    /** Cek ringan server cadangan (GET /api/ping, tanpa token & tanpa data sesi). Return waktu respon (ms). */
    suspend fun ping(dasar: String): Long = withContext(Dispatchers.IO) {
        val req = Request.Builder().url("${dasar.trimEnd('/')}/api/ping").header("Accept", "application/json").get().build()
        val mulai = System.nanoTime()
        http.newCall(req).execute().use { res ->
            res.body?.string()
            if (!res.isSuccessful) throw ApiError(res.code, "Server menjawab ${res.code}")
        }
        (System.nanoTime() - mulai) / 1_000_000
    }

    suspend fun heartbeat(data: JsonObject) {
        kirim(permintaan("/heartbeat").post(jsonBody(data)).build())
    }

    suspend fun bypass(pin: String, menit: Int?): Pair<StatusTv, String> {
        val isi = kirim(permintaan("/bypass").post(jsonBody(objek("pin" to pin, "menit" to menit?.toString()))).build())
        return JsonApi.decodeFromString(StatusTv.serializer(), isi) to isi
    }

    /** Return nama penyetuju jika PIN benar */
    suspend fun verifikasiPin(pin: String): String {
        val isi = kirim(permintaan("/verifikasi-pin").post(jsonBody(objek("pin" to pin))).build())
        val obj = JsonApi.parseToJsonElement(isi) as JsonObject
        return (obj["nama"] as? JsonPrimitive)?.content ?: "staf"
    }

    suspend fun akhiriBypass(): Pair<StatusTv, String> {
        val isi = kirim(permintaan("/bypass/akhiri").post(jsonBody(JsonObject(emptyMap()))).build())
        return JsonApi.decodeFromString(StatusTv.serializer(), isi) to isi
    }

    /** Tanda tangan channel privat Reverb; return nilai "auth" */
    suspend fun authBroadcast(socketId: String, channel: String): String {
        val isi = kirim(permintaan("/broadcasting/auth").post(jsonBody(objek("socket_id" to socketId, "channel_name" to channel))).build())
        val obj = JsonApi.parseToJsonElement(isi) as JsonObject
        return (obj["auth"] as JsonPrimitive).content
    }

    suspend fun simpanInputHdmi(id: String, label: String): Pair<StatusTv, String> {
        val isi = kirim(permintaan("/input-hdmi").post(jsonBody(objek("id" to id, "label" to label))).build())
        return JsonApi.decodeFromString(StatusTv.serializer(), isi) to isi
    }

    /** Return pesan dari server untuk ditampilkan ke pelanggan */
    suspend fun panggilKasir(): String {
        val isi = kirim(permintaan("/panggil-kasir").post(jsonBody(JsonObject(emptyMap()))).build())
        val obj = JsonApi.parseToJsonElement(isi) as JsonObject
        return (obj["pesan"] as? JsonPrimitive)?.content ?: "Kasir sudah dipanggil."
    }

    suspend fun cekUpdate(versiKode: Int): InfoUpdate {
        val isi = kirim(permintaan("/update?versi_kode=$versiKode").get().build())
        return JsonApi.decodeFromString(InfoUpdate.serializer(), isi)
    }

    /** Unduh file (APK) dengan token ke [tujuan] */
    suspend fun unduh(urlFile: String, tujuan: File) = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(urlFile).header("Authorization", "Bearer ${simpan.token}").build()
        val klien = http.newBuilder().readTimeout(5, TimeUnit.MINUTES).build()

        klien.newCall(req).execute().use { res ->
            if (res.code == 401) throw PerluPairing()
            if (!res.isSuccessful) throw ApiError(res.code, "Gagal mengunduh update (${res.code})")

            tujuan.parentFile?.mkdirs()
            res.body!!.byteStream().use { masuk -> tujuan.outputStream().use { keluar -> masuk.copyTo(keluar) } }
        }
    }
}
