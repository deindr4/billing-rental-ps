package id.rentalps.tvagent.ui

import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.setContent
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Hdmi
import id.rentalps.tvagent.service.AgentService

/**
 * Layar kunci / pairing. Tombol Back diabaikan supaya pelanggan tidak bisa keluar;
 * staf memakai "Menu staf".
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

            // Tidak ada "mundur ke belakang" otomatis: jika HDMI gagal, layar TV Agent tetap tampil
            // dengan pilihan input (bukan memperlihatkan aplikasi terakhir seperti YouTube).
            Aplikasi(agent, keadaan, onBukaHdmi = {
                agent.setHdmiGagal(!Hdmi.buka(this, agent.inputHdmi()))
            })
        }
    }

    /** TV Agent = layar utama: tombol Home saat TV terkunci -> dialog PIN staf untuk keluar sementara */
    override fun onNewIntent(intent: android.content.Intent) {
        super.onNewIntent(intent)
        val app = application as AgentApp
        val k = app.agent.keadaan.value

        // Bypass / buka darurat = TV sedang terbuka untuk staf: Home langsung ke layar utama Google TV
        if (intent.hasCategory(android.content.Intent.CATEGORY_HOME) && k.layar in setOf("bypass", "darurat")) {
            bukaLauncherBawaan(this)
            return
        }

        if (intent.hasCategory(android.content.Intent.CATEGORY_HOME) &&
            k.tahap == id.rentalps.tvagent.core.Tahap.Aktif &&
            k.layar in id.rentalps.tvagent.core.Agent.LAYAR_TERKUNCI
        ) {
            // Masih dalam waktu keluar staf (PIN sudah benar): Home langsung ke layar TV bawaan
            if (app.bolehKeluar()) bukaLauncherBawaan(this) else app.mintaPinKeluar.value = true
        }
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
