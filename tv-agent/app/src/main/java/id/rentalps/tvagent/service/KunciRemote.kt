package id.rentalps.tvagent.service

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.AccessibilityServiceInfo
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.util.Log
import android.view.KeyEvent
import android.view.accessibility.AccessibilityEvent
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Agent
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Kunci remote saat main: hanya Volume, Home & OK yang diteruskan, tombol lain (panah, Back, angka, menu, ...)
 * ditahan supaya tidak sampai ke PS lewat HDMI-CEC — mis. remote TV sebelah (TV merek sama = kode IR sama)
 * ikut menggerakkan menu PS.
 *
 * Hanya aktif saat sesi main DAN TV Agent tidak di depan (HDMI/PS tampil). Layar TV Agent sendiri (PIN staf,
 * menu staf) dan bypass tidak dikunci. Pengaturan: Admin → Pengaturan Operasional → "Kunci remote saat main".
 *
 * Butuh izin Aksesibilitas (sekali per TV): Akses staf → Pengaturan TV Agent → "Izin kunci remote".
 * Tombol yang ditahan dicatat ([laporan]) → Diagnostik: tercatat = sinyal remote/IR; PS tetap bergerak tanpa
 * catatan = sumbernya bukan remote (stik PS drift / HDMI-CEC dari perangkat lain).
 */
class KunciRemote : AccessibilityService() {

    override fun onKeyEvent(event: KeyEvent): Boolean {
        if (!aktif() || event.keyCode in DITERUSKAN) return false

        if (event.action == KeyEvent.ACTION_DOWN && event.repeatCount == 0) {
            jumlahDitahan++
            terakhir = "${KeyEvent.keyCodeToString(event.keyCode).removePrefix("KEYCODE_")} " +
                SimpleDateFormat("HH:mm:ss", Locale.US).format(Date())
            Log.i(Agent.TAG, "Kunci remote: tombol ditahan $terakhir")
        }
        return true
    }

    private fun aktif(): Boolean {
        val app = application as AgentApp
        val k = app.agent.keadaan.value
        return k.status?.pengaturan?.kunciRemote == true && k.layar == "main" && !app.layarDepan.value
    }

    /** Sebagian TV mengabaikan flag dari XML: minta penyaringan tombol lagi saat tersambung */
    override fun onServiceConnected() {
        super.onServiceConnected()
        serviceInfo = serviceInfo.apply { flags = flags or AccessibilityServiceInfo.FLAG_REQUEST_FILTER_KEY_EVENTS }
        Log.i(Agent.TAG, "Kunci remote tersambung (flags ${serviceInfo.flags})")
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) = Unit

    override fun onInterrupt() = Unit

    companion object {
        /** Volume, Home, OK — tombol lain ditahan saat main */
        private val DITERUSKAN = setOf(
            KeyEvent.KEYCODE_VOLUME_UP, KeyEvent.KEYCODE_VOLUME_DOWN, KeyEvent.KEYCODE_VOLUME_MUTE, KeyEvent.KEYCODE_MUTE,
            KeyEvent.KEYCODE_HOME,
            KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER, KeyEvent.KEYCODE_NUMPAD_ENTER,
            // Tombol daya tetap ditangani sistem
            KeyEvent.KEYCODE_POWER, KeyEvent.KEYCODE_TV_POWER, KeyEvent.KEYCODE_SLEEP, KeyEvent.KEYCODE_WAKEUP,
        )

        @Volatile
        var jumlahDitahan = 0

        @Volatile
        var terakhir: String? = null

        /** Untuk diagnostik: "12 tombol ditahan, terakhir DPAD_UP 20:15:03" */
        fun laporan(): String? = terakhir?.let { "$jumlahDitahan tombol ditahan, terakhir $it" }

        /** Izin Aksesibilitas TV Agent sudah dinyalakan di pengaturan TV */
        fun diizinkan(ctx: Context): Boolean {
            val nama = ComponentName(ctx, KunciRemote::class.java).flattenToString()
            val aktif = Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES) ?: return false
            return aktif.split(':').any { it.equals(nama, ignoreCase = true) }
        }
    }
}
