package id.rentalps.tvagent.core

import android.media.AudioManager
import android.media.ToneGenerator
import android.util.Log
import kotlinx.coroutines.delay

/**
 * Bunyi peringatan tanpa file audio (nada bawaan Android).
 * Belum tentu terdengar saat TV di input HDMI (tergantung TV mencampur suara aplikasi dengan HDMI),
 * jadi hasilnya dicatat untuk laporan diagnostik.
 */
object Suara {
    @Volatile
    var laporan: String? = null
        private set

    suspend fun bunyikan(jenis: Peringatan.Jenis, sedangHdmi: Boolean) {
        val (nada, ulang, lama, jeda) = when (jenis) {
            Peringatan.Jenis.MULAI -> Pola(ToneGenerator.TONE_PROP_ACK, 1, 300, 0)
            Peringatan.Jenis.SISA -> Pola(ToneGenerator.TONE_PROP_BEEP, 2, 180, 320)
            Peringatan.Jenis.SATU_MENIT -> Pola(ToneGenerator.TONE_PROP_BEEP2, 3, 220, 280)
            Peringatan.Jenis.HABIS -> Pola(ToneGenerator.TONE_PROP_PROMPT, 1, 900, 0)
        }

        var tone: ToneGenerator? = null
        try {
            tone = ToneGenerator(AudioManager.STREAM_MUSIC, 90)
            repeat(ulang) {
                tone.startTone(nada, lama)
                delay((lama + jeda).toLong())
            }
            laporan = "diputar ${jenis.name.lowercase()}${if (sedangHdmi) " saat HDMI" else ""}"
        } catch (e: Exception) {
            laporan = "gagal: ${e.javaClass.simpleName} ${e.message}"
            Log.w(Agent.TAG, "Suara: $laporan")
        } finally {
            delay(200)
            tone?.release()
        }
    }

    private data class Pola(val nada: Int, val ulang: Int, val lama: Int, val jeda: Int)
}
