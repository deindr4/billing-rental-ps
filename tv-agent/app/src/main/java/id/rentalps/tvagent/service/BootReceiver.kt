package id.rentalps.tvagent.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * TV dinyalakan / aplikasi selesai di-update -> jalankan TV Agent lagi. Layanan lalu menampilkan layar kunci
 * (butuh izin "tampil di atas aplikasi lain"). QUICKBOOT = "boot cepat" sebagian merek TV.
 * Bangun dari standby (bukan boot) ditangani AgentService lewat ACTION_SCREEN_ON.
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action in AKSI) AgentService.jalankan(context)
    }

    private companion object {
        val AKSI = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
        )
    }
}
