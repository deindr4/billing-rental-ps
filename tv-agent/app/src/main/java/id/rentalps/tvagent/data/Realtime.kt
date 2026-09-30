package id.rentalps.tvagent.data

import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener

/**
 * Klien Reverb (protokol Pusher) untuk channel privat TV.
 * Hanya mendengar sinyal "segarkan"; isi status tetap diambil lewat API.
 * Jika putus, tersambung ulang dengan jeda bertahap (TV tetap polling sebagai cadangan).
 */
class Realtime(
    private val api: Api,
    private val scope: CoroutineScope,
    private val onSegarkan: (alasan: String) -> Unit,
    private val onTersambung: (Boolean) -> Unit,
    private val onPerintah: (PerintahRemote) -> Unit,
) {
    private var ws: WebSocket? = null
    private var info: InfoRealtime? = null
    private var jobSambungUlang: Job? = null
    private var percobaan = 0
    private var dihentikan = false

    /** Dipanggil setiap status baru; tersambung ulang hanya jika alamat/channel berubah */
    fun pastikan(baru: InfoRealtime?) {
        if (baru == null || baru.key.isNullOrBlank()) return
        if (baru == info && ws != null) return

        info = baru
        dihentikan = false
        sambung()
    }

    fun hentikan() {
        dihentikan = true
        jobSambungUlang?.cancel()
        ws?.close(1000, "berhenti")
        ws = null
        info = null
        onTersambung(false)
    }

    private fun sambung() {
        val i = info ?: return
        ws?.cancel()

        val skema = if (i.scheme == "https") "wss" else "ws"
        val url = "$skema://${i.host}:${i.port}/app/${i.key}?protocol=7&client=tv-agent&version=1.0"

        ws = api.http.newWebSocket(Request.Builder().url(url).build(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) = terima(webSocket, text)

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.w(TAG, "Realtime gagal: ${t.message}")
                putus()
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) = putus()
        })
    }

    private fun terima(socket: WebSocket, text: String) {
        val pesan = runCatching { JsonApi.parseToJsonElement(text).jsonObject }.getOrNull() ?: return
        val event = pesan["event"]?.jsonPrimitive?.content ?: return

        when (event) {
            "pusher:connection_established" -> {
                val data = dataObjek(pesan) ?: return
                val socketId = data["socket_id"]?.jsonPrimitive?.content ?: return
                langganan(socket, socketId)
            }

            "pusher_internal:subscription_succeeded" -> {
                percobaan = 0
                onTersambung(true)
                onSegarkan("tersambung") // ambil status terbaru setelah tersambung
            }

            "pusher:ping" -> socket.send("""{"event":"pusher:pong","data":{}}""")

            "pusher:error" -> Log.w(TAG, "Reverb error: $text")

            "perintah" -> dataObjek(pesan)?.let { data ->
                runCatching { JsonApi.decodeFromJsonElement(PerintahRemote.serializer(), data) }
                    .onSuccess(onPerintah)
            }

            else -> {
                val namaEvent = info?.event?.trimStart('.') ?: "segarkan"
                if (event == namaEvent) {
                    val alasan = dataObjek(pesan)?.get("alasan")?.jsonPrimitive?.content ?: "sinyal"
                    onSegarkan(alasan)
                }
            }
        }
    }

    private fun langganan(socket: WebSocket, socketId: String) {
        val channel = info?.channel ?: return

        scope.launch {
            try {
                val auth = api.authBroadcast(socketId, channel)
                val pesan = buildJsonObject {
                    put("event", JsonPrimitive("pusher:subscribe"))
                    put("data", buildJsonObject {
                        put("auth", JsonPrimitive(auth))
                        put("channel", JsonPrimitive(channel))
                    })
                }
                socket.send(pesan.toString())
            } catch (e: Exception) {
                Log.w(TAG, "Auth channel gagal: ${e.message}")
                socket.close(1000, "auth gagal")
            }
        }
    }

    /** Field "data" pesan Pusher berupa string JSON */
    private fun dataObjek(pesan: JsonObject): JsonObject? {
        val data = pesan["data"] ?: return null
        return runCatching {
            if (data is JsonPrimitive) JsonApi.parseToJsonElement(data.content).jsonObject else data.jsonObject
        }.getOrNull()
    }

    private fun putus() {
        ws = null
        onTersambung(false)
        if (dihentikan) return

        jobSambungUlang?.cancel()
        jobSambungUlang = scope.launch {
            percobaan++
            delay(minOf(60_000L, 2_000L * percobaan)) // 2 dtk, 4 dtk, ... maks 1 menit
            if (!dihentikan) sambung()
        }
    }

    companion object {
        private const val TAG = "TvAgent"
    }
}
