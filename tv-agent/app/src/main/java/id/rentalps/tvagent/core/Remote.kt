package id.rentalps.tvagent.core

import android.app.AlarmManager
import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.media.AudioManager
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.os.Process
import android.os.SystemClock
import android.provider.Settings
import android.util.Log
import android.view.View
import android.view.WindowManager
import id.rentalps.tvagent.service.AdminReceiver
import id.rentalps.tvagent.ui.MainActivity

/**
 * Menjalankan perintah remote dari kasir.
 * - Layar mati: DevicePolicyManager.lockNow() (butuh izin Device admin) → TV standby.
 *   Tanpa izin itu: layar ditutup hitam penuh (fallback), suara dari PS tetap ada.
 * - Restart TV: reboot penuh hanya jika TV Agent device owner; selain itu restart aplikasi.
 */
object Remote {
    /** Hasil perintah remote terakhir (dikirim di diagnostik). Juga diisi Agent untuk perintah update. */
    @Volatile
    var laporan: String? = null

    private var layarGelap: View? = null

    fun admin(ctx: Context) = ComponentName(ctx, AdminReceiver::class.java)

    private fun dpm(ctx: Context) = ctx.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    /** Banyak Android TV tidak menyertakan fitur Device admin; jika tidak ada, layar mati = layar hitam */
    fun didukungDeviceAdmin(ctx: Context): Boolean =
        ctx.packageManager.hasSystemFeature(android.content.pm.PackageManager.FEATURE_DEVICE_ADMIN)

    fun deviceAdmin(ctx: Context): Boolean = didukungDeviceAdmin(ctx) && dpm(ctx).isAdminActive(admin(ctx))

    fun deviceOwner(ctx: Context): Boolean = dpm(ctx).isDeviceOwnerApp(ctx.packageName)

    fun jalankan(ctx: Context, perintah: String) {
        laporan = try {
            when (perintah) {
                "volume_naik" -> volume(ctx, AudioManager.ADJUST_RAISE)
                "volume_turun" -> volume(ctx, AudioManager.ADJUST_LOWER)
                "volume_senyap" -> volume(ctx, AudioManager.ADJUST_TOGGLE_MUTE)
                "layar_mati" -> layarMati(ctx)
                "layar_nyala" -> layarNyala(ctx)
                "restart_aplikasi" -> restartAplikasi(ctx)
                "restart_tv" -> if (deviceOwner(ctx)) {
                    dpm(ctx).reboot(admin(ctx))
                    "reboot TV"
                } else {
                    restartAplikasi(ctx) + " (reboot penuh butuh device owner)"
                }
                else -> "perintah tidak dikenal: $perintah"
            }
        } catch (e: Exception) {
            "gagal $perintah: ${e.javaClass.simpleName} ${e.message}"
        }
        Log.i(Agent.TAG, "Remote: $laporan")
    }

    /* ---------------- Status untuk heartbeat ---------------- */

    private fun audio(ctx: Context) = ctx.getSystemService(Context.AUDIO_SERVICE) as AudioManager

    fun volumePersen(ctx: Context): Int {
        val a = audio(ctx)
        val maks = a.getStreamMaxVolume(AudioManager.STREAM_MUSIC).coerceAtLeast(1)
        return a.getStreamVolume(AudioManager.STREAM_MUSIC) * 100 / maks
    }

    fun senyap(ctx: Context): Boolean = audio(ctx).isStreamMute(AudioManager.STREAM_MUSIC)

    fun layarHidup(ctx: Context): Boolean =
        (ctx.getSystemService(Context.POWER_SERVICE) as PowerManager).isInteractive && layarGelap == null

    /* ---------------- Pelaksana ---------------- */

    private fun volume(ctx: Context, arah: Int): String {
        audio(ctx).adjustStreamVolume(AudioManager.STREAM_MUSIC, arah, AudioManager.FLAG_SHOW_UI)
        return "volume ${volumePersen(ctx)}%${if (senyap(ctx)) " (senyap)" else ""}"
    }

    private fun layarMati(ctx: Context): String {
        if (deviceAdmin(ctx)) {
            dpm(ctx).lockNow()
            return "layar mati (standby)"
        }

        // Fallback: tutup layar hitam penuh (butuh izin tampil di atas aplikasi lain)
        if (!Settings.canDrawOverlays(ctx)) return "gagal: butuh izin Device admin atau izin tampil di atas"

        Handler(Looper.getMainLooper()).post {
            if (layarGelap != null) return@post
            val v = View(ctx).apply { setBackgroundColor(Color.BLACK) }
            // Tanpa FLAG_NOT_TOUCHABLE: Android 12+ membatasi overlay "tembus sentuh" maks. 80% gelap.
            // Tetap FLAG_NOT_FOCUSABLE, jadi tombol remote tetap sampai ke aplikasi di bawahnya.
            val lp = WindowManager.LayoutParams(
                WindowManager.LayoutParams.MATCH_PARENT,
                WindowManager.LayoutParams.MATCH_PARENT,
                WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
                    WindowManager.LayoutParams.FLAG_FULLSCREEN,
                PixelFormat.OPAQUE,
            )
            runCatching { (ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager).addView(v, lp) }
                .onSuccess { layarGelap = v }
        }
        return if (didukungDeviceAdmin(ctx)) "layar ditutup hitam (izin Device admin belum aktif)"
        else "layar ditutup hitam (TV tidak mendukung standby dari aplikasi)"
    }

    @Suppress("DEPRECATION")
    private fun layarNyala(ctx: Context): String {
        Handler(Looper.getMainLooper()).post {
            layarGelap?.let { runCatching { (ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager).removeView(it) } }
            layarGelap = null
        }

        val power = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        if (!power.isInteractive) {
            power.newWakeLock(
                PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE,
                "tvagent:nyala",
            ).acquire(3_000)
        }

        ctx.startActivity(Intent(ctx, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
        return if (power.isInteractive) "layar nyala" else "layar nyala (wake lock)"
    }

    /** Mulai ulang proses aplikasi; layanan & layar kunci hidup lagi otomatis */
    private fun restartAplikasi(ctx: Context): String {
        val buka = PendingIntent.getActivity(
            ctx, 0,
            Intent(ctx, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_CANCEL_CURRENT,
        )
        (ctx.getSystemService(Context.ALARM_SERVICE) as AlarmManager)
            .set(AlarmManager.ELAPSED_REALTIME, SystemClock.elapsedRealtime() + 1_500, buka)

        Handler(Looper.getMainLooper()).postDelayed({ Process.killProcess(Process.myPid()) }, 800)
        return "restart aplikasi"
    }
}
