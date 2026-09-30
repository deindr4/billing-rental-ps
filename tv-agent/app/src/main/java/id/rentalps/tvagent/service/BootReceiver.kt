package id.rentalps.tvagent.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** TV dinyalakan / aplikasi selesai di-update -> jalankan TV Agent lagi */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED || intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            AgentService.jalankan(context)
        }
    }
}
