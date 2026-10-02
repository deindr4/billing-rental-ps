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
import android.view.WindowManager
import android.widget.LinearLayout
import android.widget.TextView
import id.rentalps.tvagent.core.Jam
import id.rentalps.tvagent.core.Keadaan

/**
 * Timer kecil yang melayang di atas tampilan PS (HDMI), gaya desain Stitch.
 * Tidak bisa difokus/diklik, jadi tidak mengganggu stik PS maupun remote.
 * Peringatan tidak pernah membesar / menutup permainan: cukup angka merah (sisa ≤ menit peringatan)
 * dan berkedip sebentar di pojok (+ bunyi dari [id.rentalps.tvagent.core.Suara]).
 * Butuh izin "tampil di atas aplikasi lain".
 */
class OverlayTimer(private val ctx: Context) {
    private val wm = ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    private val handler = Handler(Looper.getMainLooper())

    private var tampil = false
    private var keadaan: Keadaan? = null
    private var kedipSampaiMs = 0L
    private var redup = false

    private val warnaNormal = Color.argb(255, 15, 28, 43)     // #0f1c2b; transparansi dari setelan kepekatan
    private val warnaGaris = Color.parseColor("#1E3144")
    private val warnaMerah = Color.parseColor("#F87171")
    private val warnaBiru = Color.parseColor("#38BDF8")
    private val warnaTeks = Color.parseColor("#E6EDF5")
    private val warnaRedup = Color.parseColor("#7F90A6")

    private val judul = TextView(ctx).apply {
        typeface = Typeface.MONOSPACE
        letterSpacing = 0.12f
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 10f)
        setShadowLayer(4f, 0f, 1f, Color.argb(200, 0, 0, 0))
    }

    private val waktu = TextView(ctx).apply {
        typeface = Typeface.create(Typeface.MONOSPACE, Typeface.BOLD)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 24f)
        // Bayangan: angka tetap terbaca walau latar dibuat transparan di atas game yang terang
        setShadowLayer(6f, 0f, 2f, Color.argb(220, 0, 0, 0))
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
    }

    private val detik = object : Runnable {
        override fun run() {
            gambar()
            handler.postDelayed(this, 1_000)
        }
    }

    /** Kedip: timer bergantian pekat / redup tiap 0,4 detik sampai [kedipSampaiMs] */
    private val kedipan = object : Runnable {
        override fun run() {
            if (System.currentTimeMillis() >= kedipSampaiMs) {
                redup = false
                gambar()
                return
            }
            redup = !redup
            gambar()
            handler.postDelayed(this, 400)
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
        handler.removeCallbacks(kedipan)
        kedipSampaiMs = 0
        redup = false
        if (!tampil) return

        runCatching { wm.removeView(kotak) }
        tampil = false
    }

    /** Berkedip di pojok selama [detik] — pengganti banner besar supaya permainan tidak tertutup */
    fun kedip(detik: Int = 6) {
        kedipSampaiMs = System.currentTimeMillis() + detik * 1000L
        handler.removeCallbacks(kedipan)
        handler.post(kedipan)
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

        // Ukuran dari admin (kecil/sedang/besar) & tidak pernah membesar sendiri;
        // hampir habis = angka & garis merah, latar tetap gelap (tidak menutup game)
        terapkanUkuran(k.status?.pengaturan?.ukuranTimer ?: "sedang")

        // Kepekatan (Pengaturan → Operasional) hanya untuk LATAR & garis: game di belakang terlihat,
        // angka tetap pekat (+ bayangan) supaya selalu terbaca. Juga berlaku saat hampir habis.
        val opasitas = (k.status?.pengaturan?.opasitasTimer ?: 90).coerceIn(30, 100) / 100f
        val garis = if (hampir) warnaMerah else if (pilihGame) warnaBiru else warnaGaris
        latar.setColor(denganAlpha(warnaNormal, opasitas))
        latar.setStroke(2, denganAlpha(garis, maxOf(opasitas, 0.6f)))
        // Warna normal dari admin (putih/hijau/biru/...); hampir habis selalu merah, waktu pilih game biru
        val warnaNormalTeks = runCatching { Color.parseColor(k.status?.pengaturan?.warnaTimer ?: "") }.getOrDefault(warnaTeks)
        waktu.setTextColor(if (hampir) warnaMerah else if (pilihGame) warnaBiru else warnaNormalTeks)
        judul.setTextColor(if (hampir) warnaMerah else warnaRedup)

        // Kedip peringatan: seluruh timer meredup sesaat
        kotak.alpha = if (redup) 0.2f else 1f
    }

    private fun denganAlpha(warna: Int, alpha: Float): Int =
        Color.argb((Color.alpha(warna) * alpha).toInt().coerceIn(0, 255), Color.red(warna), Color.green(warna), Color.blue(warna))

    private var ukuranTerpasang: String? = null

    private fun terapkanUkuran(ukuran: String) {
        if (ukuran == ukuranTerpasang) return
        ukuranTerpasang = ukuran

        val (judulSp, waktuSp, padH, padV) = when (ukuran) {
            "kecil" -> listOf(8f, 17f, 20f, 10f)
            "besar" -> listOf(12f, 32f, 38f, 22f)
            else -> listOf(10f, 24f, 30f, 16f)
        }
        judul.setTextSize(TypedValue.COMPLEX_UNIT_SP, judulSp)
        waktu.setTextSize(TypedValue.COMPLEX_UNIT_SP, waktuSp)
        kotak.setPadding(padH.toInt(), padV.toInt(), padH.toInt(), (padV + 2).toInt())
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
