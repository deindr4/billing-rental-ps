package id.rentalps.tvagent.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.util.Log
import androidx.core.content.ContextCompat
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.Hdmi
import id.rentalps.tvagent.core.Peringatan
import id.rentalps.tvagent.core.Suara
import id.rentalps.tvagent.core.Tahap
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import id.rentalps.tvagent.ui.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

/**
 * Layanan latar depan yang menjaga TV Agent tetap hidup dan menjalankan perpindahan tampilan:
 * - layar terbuka (main/bypass/darurat) -> pindah ke HDMI + timer melayang
 * - layar terkunci -> tutup timer, tampilkan layar kunci (MainActivity)
 */
class AgentService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private lateinit var overlay: OverlayTimer

    private val agent: Agent get() = (application as AgentApp).agent

    override fun onCreate() {
        super.onCreate()
        mulaiLatarDepan()

        overlay = OverlayTimer(this)
        agent.mulai()

        scope.launch {
            agent.keadaan
                .map { it.layar }
                .distinctUntilChanged()
                .collect { layar -> ubahTampilan(layar) }
        }

        // Timer melayang ikut status terbaru (sisa waktu, posisi, peringatan)
        scope.launch {
            agent.keadaan.collect { overlay.perbarui(it) }
        }

        // Timer melayang hanya saat TV terbuka DAN aplikasi lain (PS/HDMI, YouTube) di depan,
        // supaya tidak menimpa layar TV Agent sendiri
        scope.launch {
            combine(agent.keadaan.map { it.layar }, (application as AgentApp).layarDepan) { layar, depan ->
                layar in Agent.LAYAR_TERBUKA && !depan
            }
                .distinctUntilChanged()
                .collect { tampil -> if (tampil) overlay.tampilkan() else overlay.sembunyikan() }
        }

        // Mode kiosk: TV terkunci tapi TV Agent terdorong ke belakang (tombol Home, aplikasi lain)
        // -> tarik kembali ke depan, kecuali staf sedang diberi waktu keluar
        scope.launch {
            val app = application as AgentApp
            combine(agent.keadaan, app.layarDepan) { k, depan ->
                !depan && k.tahap == Tahap.Aktif && k.layar in Agent.LAYAR_TERKUNCI
            }
                .distinctUntilChanged()
                .collectLatest { terlepas ->
                    while (terlepas && isActive) {
                        delay(1_200)
                        val k = agent.keadaan.value
                        val masihTerlepas = !app.layarDepan.value && k.tahap == Tahap.Aktif && k.layar in Agent.LAYAR_TERKUNCI
                        if (!masihTerlepas) break
                        if (!app.bolehKeluar()) {
                            Log.i(Agent.TAG, "Kiosk: layar kunci ditarik kembali ke depan")
                            app.mintaPinKeluar.value = true // tawarkan PIN staf untuk keluar
                            tampilkanLayarKunci()
                        }
                        delay(1_800)
                    }
                }
        }

        // Peringatan sisa waktu: bunyi + banner besar sebentar
        scope.launch {
            agent.peringatan.collect { p ->
                when (p.jenis) {
                    Peringatan.Jenis.MULAI -> overlay.banner("Waktu bermain dimulai — selamat bermain!", 8)
                    Peringatan.Jenis.SISA -> overlay.banner("Sisa ${p.menit} menit — hubungi kasir untuk tambah waktu")
                    Peringatan.Jenis.SATU_MENIT -> overlay.banner("Sisa 1 menit — simpan permainan Anda")
                    Peringatan.Jenis.HABIS -> Unit // layar kunci "waktu habis" tampil sendiri
                }
                if (p.bunyi) {
                    launch { Suara.bunyikan(p.jenis, sedangHdmi = agent.keadaan.value.layar in Agent.LAYAR_TERBUKA) }
                }
            }
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int = START_STICKY

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        overlay.sembunyikan()
        scope.cancel()
        super.onDestroy()
    }

    private fun ubahTampilan(layar: String) {
        Log.i(Agent.TAG, "Tampilan -> $layar")

        when (layar) {
            // Bypass: tidak otomatis ke HDMI — owner memilih PS / YouTube / aplikasi lain di layar bypass
            "bypass" -> tampilkanLayarKunci()
            in Agent.LAYAR_TERBUKA -> {
                // Gagal pindah HDMI: tetap di layar TV Agent (dengan pilihan input), jangan tampilkan aplikasi lain
                val berhasil = Hdmi.buka(this, agent.inputHdmi())
                agent.setHdmiGagal(!berhasil)
                if (!berhasil) tampilkanLayarKunci()
            }
            in Agent.LAYAR_TERKUNCI -> tampilkanLayarKunci()
        }
    }

    /** Membawa layar kunci ke depan (butuh izin overlay agar boleh dari latar belakang di Android 10+) */
    private fun tampilkanLayarKunci() {
        try {
            startActivity(
                Intent(this, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            )
        } catch (e: Exception) {
            Log.w(Agent.TAG, "Gagal menampilkan layar kunci: ${e.message}")
        }
    }

    private fun mulaiLatarDepan() {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(KANAL, "TV Agent", NotificationManager.IMPORTANCE_MIN))

        val notif = Notification.Builder(this, KANAL)
            .setContentTitle("TV Agent aktif")
            .setSmallIcon(android.R.drawable.ic_lock_idle_lock)
            .setOngoing(true)
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(1, notif, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(1, notif)
        }
    }

    companion object {
        private const val KANAL = "tv_agent"

        fun jalankan(ctx: Context) {
            ContextCompat.startForegroundService(ctx, Intent(ctx, AgentService::class.java))
        }

        fun bisaOverlay(ctx: Context): Boolean = Settings.canDrawOverlays(ctx)
    }
}
