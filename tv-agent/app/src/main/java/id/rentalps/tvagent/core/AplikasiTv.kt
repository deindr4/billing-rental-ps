package id.rentalps.tvagent.core

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.util.Log

/**
 * Membuka aplikasi TV lain (YouTube, dll.) saat bypass, dan daftar aplikasi terpasang untuk diagnostik
 * (supaya admin tahu nama paket yang bisa diizinkan).
 */
object AplikasiTv {

    fun terpasang(ctx: Context, paket: String): Boolean = intentBuka(ctx, paket) != null

    /** Return true jika aplikasi berhasil dibuka */
    fun buka(ctx: Context, paket: String): Boolean {
        val intent = intentBuka(ctx, paket) ?: return false

        return try {
            ctx.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            true
        } catch (e: Exception) {
            Log.w(Agent.TAG, "Buka $paket: ${e.message}")
            false
        }
    }

    private fun intentBuka(ctx: Context, paket: String): Intent? {
        val pm = ctx.packageManager
        return pm.getLeanbackLaunchIntentForPackage(paket) ?: pm.getLaunchIntentForPackage(paket)
    }

    /** "Nama = paket" untuk semua aplikasi yang tampil di launcher TV (tanpa TV Agent sendiri) */
    fun daftarTerpasang(ctx: Context): List<String> {
        val pm = ctx.packageManager
        val hasil = sortedSetOf<String>()

        listOf(Intent.CATEGORY_LEANBACK_LAUNCHER, Intent.CATEGORY_LAUNCHER).forEach { kategori ->
            val intent = Intent(Intent.ACTION_MAIN).addCategory(kategori)
            runCatching { pm.queryIntentActivities(intent, PackageManager.MATCH_ALL) }.getOrDefault(emptyList())
                .filter { it.activityInfo.packageName != ctx.packageName }
                .forEach { hasil.add("${it.loadLabel(pm)} = ${it.activityInfo.packageName}") }
        }

        return hasil.toList()
    }
}
