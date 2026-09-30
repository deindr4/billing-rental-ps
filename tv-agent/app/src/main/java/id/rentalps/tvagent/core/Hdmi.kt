package id.rentalps.tvagent.core

import android.content.Context
import android.content.Intent
import android.media.tv.TvContract
import android.media.tv.TvInputInfo
import android.media.tv.TvInputManager
import android.util.Log

/**
 * Pindah ke input HDMI tempat PS tersambung (TV Input Framework, input "passthrough").
 * Cara ini standar Android TV, tapi dukungan tiap merek berbeda — hasilnya dilaporkan lewat diagnostik.
 */
object Hdmi {
    data class Input(val id: String, val label: String, val hdmi: Boolean)

    @Volatile
    var errorTerakhir: String? = null
        private set

    fun daftarInput(ctx: Context): List<Input> = runCatching {
        val tim = ctx.getSystemService(Context.TV_INPUT_SERVICE) as? TvInputManager ?: return emptyList()
        tim.tvInputList
            .filter { it.isPassthroughInput }
            .map { Input(it.id, it.loadLabel(ctx).toString(), it.type == TvInputInfo.TYPE_HDMI) }
    }.getOrElse {
        errorTerakhir = "daftarInput: ${it.message}"
        emptyList()
    }

    /** Input pilihan tersimpan, atau HDMI pertama */
    fun pilih(ctx: Context, pilihan: String?): Input? {
        val semua = daftarInput(ctx)
        return semua.firstOrNull { it.id == pilihan } ?: semua.firstOrNull { it.hdmi } ?: semua.firstOrNull()
    }

    fun buka(ctx: Context, pilihan: String?): Boolean {
        val input = pilih(ctx, pilihan)

        if (input == null) {
            errorTerakhir = "Tidak ada input HDMI terdeteksi"
            return false
        }

        return bukaInput(ctx, input)
    }

    fun bukaInput(ctx: Context, input: Input): Boolean {
        return try {
            val intent = Intent(Intent.ACTION_VIEW, TvContract.buildChannelUriForPassthroughInput(input.id))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            ctx.startActivity(intent)
            errorTerakhir = null
            true
        } catch (e: Exception) {
            errorTerakhir = "buka ${input.label}: ${e.javaClass.simpleName} ${e.message}"
            Log.w("TvAgent", errorTerakhir!!)
            false
        }
    }
}
