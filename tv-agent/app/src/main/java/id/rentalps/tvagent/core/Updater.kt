package id.rentalps.tvagent.core

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.content.FileProvider
import id.rentalps.tvagent.BuildConfig
import id.rentalps.tvagent.data.Api
import id.rentalps.tvagent.data.InfoUpdate
import java.io.File
import java.security.MessageDigest

/**
 * Update APK dari server: cek versi, unduh, cocokkan SHA-256, lalu buka pemasang Android.
 * Pemasangan tetap perlu dikonfirmasi dengan remote (pemasangan senyap butuh mode device owner).
 */
class Updater(private val ctx: Context, private val api: Api) {

    @Volatile
    var terakhir: InfoUpdate? = null
        private set

    @Volatile
    var errorTerakhir: String? = null
        private set

    suspend fun cek(): InfoUpdate? = runCatching { api.cekUpdate(BuildConfig.VERSION_CODE) }
        .onSuccess { terakhir = it.takeIf { u -> u.adaUpdate } }
        .onFailure { errorTerakhir = "cek: ${it.message}" }
        .getOrNull()
        ?.takeIf { it.adaUpdate }

    /** Unduh & buka pemasang. Return true jika pemasang berhasil dibuka. */
    suspend fun pasang(info: InfoUpdate): Boolean {
        val url = info.url ?: return false
        val file = File(ctx.cacheDir, "update/tv-agent.apk")

        return try {
            api.unduh(url, file)

            if (!info.sha256.isNullOrBlank() && sha256(file) != info.sha256) {
                file.delete()
                errorTerakhir = "Checksum APK tidak cocok, unduhan dibatalkan"
                return false
            }

            val uri = FileProvider.getUriForFile(ctx, "${ctx.packageName}.files", file)
            val intent = Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)

            (ctx.applicationContext as id.rentalps.tvagent.AgentApp).izinkanKeluar(120) // layar pemasang Android
            ctx.startActivity(intent)
            errorTerakhir = null
            true
        } catch (e: Exception) {
            errorTerakhir = "pasang: ${e.javaClass.simpleName} ${e.message}"
            Log.w("TvAgent", errorTerakhir!!)
            false
        }
    }

    private fun sha256(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { masuk ->
            val buf = ByteArray(64 * 1024)
            while (true) {
                val n = masuk.read(buf)
                if (n < 0) break
                md.update(buf, 0, n)
            }
        }
        return md.digest().joinToString("") { "%02x".format(it) }
    }
}
