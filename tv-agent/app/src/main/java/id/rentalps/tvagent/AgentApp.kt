package id.rentalps.tvagent

import android.app.Application
import id.rentalps.tvagent.core.Agent
import kotlinx.coroutines.flow.MutableStateFlow

class AgentApp : Application() {
    lateinit var agent: Agent
        private set

    /** Layar TV Agent sedang tampil di depan (timer melayang disembunyikan agar tidak menimpa layar sendiri) */
    val layarDepan = MutableStateFlow(false)

    /**
     * Mode kiosk: saat TV terkunci, TV Agent selalu ditarik kembali ke depan (mis. pelanggan menekan Home).
     * Aksi staf yang sengaja membuka layar lain (tes HDMI, izin, pasang update) diberi waktu keluar sementara.
     */
    @Volatile
    var bolehKeluarSampaiMs: Long = 0
        private set

    fun izinkanKeluar(detik: Int) {
        bolehKeluarSampaiMs = System.currentTimeMillis() + detik * 1000L
    }

    fun bolehKeluar(): Boolean = System.currentTimeMillis() < bolehKeluarSampaiMs

    /** Tombol Home ditekan saat TV terkunci: tampilkan dialog PIN staf untuk keluar sementara */
    val mintaPinKeluar = MutableStateFlow(false)

    override fun onCreate() {
        super.onCreate()
        agent = Agent(this)
    }
}
