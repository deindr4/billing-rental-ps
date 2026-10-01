package id.rentalps.tvagent.ui

import android.content.Intent
import android.os.Bundle
import android.view.KeyEvent
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.setContent
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Hdmi
import id.rentalps.tvagent.core.Tahap
import id.rentalps.tvagent.service.AgentService

/**
 * Layar kunci / pairing. Tombol Back diabaikan supaya pelanggan tidak bisa keluar.
 * Akses staf: tekan Home, lalu OK → PIN → pilih Google TV / HDMI / YouTube / tutup aplikasi.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        val agent = (application as AgentApp).agent
        AgentService.jalankan(this)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = Unit
        })

        setContent {
            val keadaan by agent.keadaan.collectAsStateWithLifecycle()

            // "Tutup aplikasi" (dari TV atau kasir): pindah ke layar Google TV dari activity ini
            val keLauncher by (application as AgentApp).mintaKeLauncher.collectAsStateWithLifecycle()
            LaunchedEffect(keLauncher) {
                if (keLauncher > 0 && System.currentTimeMillis() - keLauncher < 10_000) {
                    bukaLauncherBawaan(this@MainActivity)
                    moveTaskToBack(true)
                }
            }

            // Tidak ada "mundur ke belakang" otomatis: jika HDMI gagal, layar TV Agent tetap tampil
            // dengan pilihan input (bukan memperlihatkan aplikasi terakhir seperti YouTube).
            Aplikasi(agent, keadaan, onBukaHdmi = {
                agent.setHdmiGagal(!Hdmi.buka(this, agent.inputHdmi()))
            })
        }
    }

    /** TV Agent = layar utama: tombol Home ditekan */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (!intent.hasCategory(Intent.CATEGORY_HOME)) return

        val app = application as AgentApp
        val k = app.agent.keadaan.value

        // Bypass / buka darurat / aplikasi ditutup / masih dalam izin keluar staf: Home ke layar utama Google TV
        if (k.layar in setOf("bypass", "darurat") || app.bolehKeluar()) {
            bukaLauncherBawaan(this)
            return
        }

        // Selain itu: siapkan akses staf (tekan OK dalam 10 detik untuk PIN)
        if (k.tahap == Tahap.Aktif) app.siapkanAksesStaf()
    }

    /** OK / Enter setelah Home → dialog PIN akses staf */
    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        val app = application as AgentApp
        val ok = event.keyCode == KeyEvent.KEYCODE_DPAD_CENTER || event.keyCode == KeyEvent.KEYCODE_ENTER ||
            event.keyCode == KeyEvent.KEYCODE_NUMPAD_ENTER

        if (ok && app.aksesStafSiap() && app.agent.keadaan.value.tahap == Tahap.Aktif) {
            if (event.action == KeyEvent.ACTION_UP) {
                app.aksesStafSampaiMs.value = 0
                app.mintaPinKeluar.value = true
            }
            return true
        }

        return super.dispatchKeyEvent(event)
    }

    override fun onResume() {
        super.onResume()
        (application as AgentApp).layarDepan.value = true
    }

    override fun onPause() {
        (application as AgentApp).layarDepan.value = false
        super.onPause()
    }
}
