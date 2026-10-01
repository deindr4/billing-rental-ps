package id.rentalps.tvagent.service

import android.content.Context
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.LinearLayout
import android.widget.TextView
import id.rentalps.tvagent.core.Jam
import id.rentalps.tvagent.core.Keadaan

/**
 * Timer kecil yang melayang di atas tampilan PS (HDMI), gaya desain Stitch.
 * Tidak bisa difokus/diklik, jadi tidak mengganggu stik PS maupun remote.
 * Saat peringatan, membesar sebentar menjadi banner berisi pesan. Butuh izin "tampil di atas aplikasi lain".
 */
class OverlayTimer(private val ctx: Context) {
    private val wm = ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    private val handler = Handler(Looper.getMainLooper())

    private var tampil = false
    private var keadaan: Keadaan? = null
    private var bannerSampaiMs = 0L

    private val warnaNormal = Color.argb(225, 15, 28, 43)     // #0f1c2b
    private val warnaGaris = Color.parseColor("#1E3144")
    private val warnaKuning = Color.parseColor("#FBBF24")
    private val warnaGelap = Color.parseColor("#0A1420")
    private val warnaBiru = Color.parseColor("#38BDF8")

    private val judul = TextView(ctx).apply {
        typeface = Typeface.MONOSPACE
        letterSpacing = 0.12f
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 10f)
    }

    private val waktu = TextView(ctx).apply {
        typeface = Typeface.create(Typeface.MONOSPACE, Typeface.BOLD)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 24f)
    }

    private val pesan = TextView(ctx).apply {
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 15f)
        visibility = View.GONE
        maxWidth = 900
    }

    private val latar = GradientDrawable().apply {
        cornerRadius = 14f
        setStroke(2, warnaGaris)
    }

    private val kotak = LinearLayout(ctx).apply {
        orientation = LinearLayout.VERTICAL
        background = latar
        setPadding(30, 16, 30, 18)
        addView(judul)
        addView(waktu)
        addView(pesan)
    }

    private val detik = object : Runnable {
        override fun run() {
            gambar()
            handler.postDelayed(this, 1_000)
        }
    }

    fun perbarui(k: Keadaan) {
        val posisiBerubah = keadaan?.status?.pengaturan?.posisiTimer != k.status?.pengaturan?.posisiTimer
        keadaan = k

        if (tampil && posisiBerubah) {
            runCatching { wm.updateViewLayout(kotak, parameter()) }
        }
    }

    fun tampilkan() {
        if (tampil || !Settings.canDrawOverlays(ctx)) return

        runCatching {
            wm.addView(kotak, parameter())
            tampil = true
            handler.post(detik)
        }
    }

    fun sembunyikan() {
        handler.removeCallbacks(detik)
        bannerSampaiMs = 0
        if (!tampil) return

        runCatching { wm.removeView(kotak) }
        tampil = false
    }

    /** Membesar selama [detik] dengan pesan peringatan */
    fun banner(teks: String, detik: Int = 10) {
        pesan.text = teks
        bannerSampaiMs = System.currentTimeMillis() + detik * 1000L
        gambar()
    }

    private fun gambar() {
        val k = keadaan ?: return
        val status = k.status
        val sesi = status?.sesi
        val kode = status?.unit?.kode ?: ""
        val sekarang = Jam.sekarang()
        val peringatanDetik = (status?.pengaturan?.peringatanMenit ?: 5) * 60L
        var hampir = false
        var pilihGame = false

        when (k.layar) {
            "main" -> if (sesi != null && sekarang < sesi.mulaiMs) {
                // Waktu pilih game: hitung mundur ke mulai waktu sewa
                pilihGame = true
                judul.text = "$kode · PILIH GAME · BELUM DITAGIH"
                waktu.text = Jam.format((sesi.mulaiMs - sekarang + 999) / 1000)
            } else if (sesi?.mode == "paket" && sesi.berakhirMs != null) {
                val sisa = (sesi.berakhirMs - sekarang) / 1000
                judul.text = "$kode · SISA WAKTU"
                waktu.text = Jam.format(sisa)
                hampir = sisa <= peringatanDetik
            } else if (sesi != null) {
                judul.text = "$kode · DURASI MAIN"
                waktu.text = Jam.format((sekarang - sesi.mulaiMs) / 1000 - sesi.totalJedaDetik)
            }
            "bypass" -> {
                judul.text = "$kode · BYPASS"
                waktu.text = Jam.format(((status?.perangkat?.bypassSampaiMs ?: sekarang) - sekarang) / 1000)
            }
            "darurat" -> {
                judul.text = "$kode · BUKA DARURAT"
                waktu.text = Jam.format((k.daruratSampaiMs - sekarang) / 1000)
                hampir = true
            }
        }

        val modeBanner = System.currentTimeMillis() < bannerSampaiMs
        pesan.visibility = if (modeBanner) View.VISIBLE else View.GONE
        waktu.setTextSize(TypedValue.COMPLEX_UNIT_SP, if (modeBanner) 44f else 24f)
        judul.setTextSize(TypedValue.COMPLEX_UNIT_SP, if (modeBanner) 13f else 10f)

        val teksUtama = if (hampir) warnaGelap else Color.parseColor("#E6EDF5")
        latar.setColor(if (hampir) warnaKuning else warnaNormal)
        latar.setStroke(2, if (hampir) warnaKuning else if (pilihGame) warnaBiru else warnaGaris)
        waktu.setTextColor(if (pilihGame) warnaBiru else teksUtama)
        pesan.setTextColor(teksUtama)
        judul.setTextColor(if (hampir) warnaGelap else Color.parseColor("#7F90A6"))

        // Kepekatan diatur admin (Pengaturan → Operasional); peringatan selalu pekat supaya jelas terbaca
        val opasitas = (k.status?.pengaturan?.opasitasTimer ?: 90).coerceIn(30, 100)
        kotak.alpha = if (hampir || modeBanner) 1f else opasitas / 100f
    }

    private fun parameter(): WindowManager.LayoutParams {
        val gravitasi = when (keadaan?.status?.pengaturan?.posisiTimer) {
            "kiri_atas" -> Gravity.TOP or Gravity.START
            "kiri_bawah" -> Gravity.BOTTOM or Gravity.START
            "kanan_bawah" -> Gravity.BOTTOM or Gravity.END
            else -> Gravity.TOP or Gravity.END
        }

        return WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = gravitasi
            x = 36
            y = 28
        }
    }
}
