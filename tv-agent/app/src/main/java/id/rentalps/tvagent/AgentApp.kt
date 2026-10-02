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

    /** Tampilkan dialog PIN akses staf (dibuka dengan Home lalu OK) */
    val mintaPinKeluar = MutableStateFlow(false)

    /**
     * Akses staf = tekan Home, lalu OK dalam 10 detik → dialog PIN. Nilai = batas waktu (ms) menunggu OK;
     * selama itu layar menampilkan petunjuk kecil. Pelanggan yang hanya menekan OK tidak membuka apa pun.
     */
    val aksesStafSampaiMs = MutableStateFlow(0L)

    fun siapkanAksesStaf() {
        aksesStafSampaiMs.value = System.currentTimeMillis() + 10_000
    }

    fun aksesStafSiap(): Boolean = System.currentTimeMillis() < aksesStafSampaiMs.value

    /** Aplikasi "ditutup" staf/operator: TV bebas dipakai (layar Google TV) sampai dikunci lagi atau sesi baru dimulai */
    val tertutup = MutableStateFlow(false)

    fun tutupAplikasi() {
        tertutup.value = true
        izinkanKeluar(12 * 60 * 60)
        mintaKeLauncher.value = System.currentTimeMillis()
    }

    /** Layar TV Agent (activity) diminta pindah ke layar Google TV — Android melarang membukanya dari latar belakang */
    val mintaKeLauncher = MutableStateFlow(0L)

    /**
     * Transisi logo rental di tengah layar: "mulai" (layar kunci -> HDMI PS) atau "selesai" (game -> waktu habis).
     * null = tidak ada transisi. Diatur AgentService, digambar di atas layar mana pun (Layar.kt).
     */
    val transisi = MutableStateFlow<String?>(null)

    /** Batalkan izin keluar / status ditutup: TV Agent kembali menjaga layar (kiosk) */
    fun kunciLagi() {
        tertutup.value = false
        bolehKeluarSampaiMs = 0
        aksesStafSampaiMs.value = 0
    }

    override fun onCreate() {
        super.onCreate()
        agent = Agent(this)
    }
}
