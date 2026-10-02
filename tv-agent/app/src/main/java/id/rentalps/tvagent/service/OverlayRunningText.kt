package id.rentalps.tvagent.service

import android.animation.ValueAnimator
import android.content.Context
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.Typeface
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.TypedValue
import android.view.Gravity
import android.view.WindowManager
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import android.widget.TextView
import id.rentalps.tvagent.core.Jam
import id.rentalps.tvagent.core.Keadaan
import id.rentalps.tvagent.core.Tahap
import id.rentalps.tvagent.data.RunningText

/**
 * Running text promo dari kasir: strip selebar layar di atas/bawah, teks bergeser terus.
 * Tampil di atas layar kunci & game, kecuali "sembunyi saat main" dan unit sedang dimainkan.
 * Mati sendiri saat waktunya habis (dicek tiap 5 detik). Tidak bisa difokus/diklik.
 */
class OverlayRunningText(private val ctx: Context) {
    private val wm = ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    private val handler = Handler(Looper.getMainLooper())

    private var keadaan: Keadaan? = null
    private var tayang: RunningText? = null
    private var strip: FrameLayout? = null
    private var gerak: ValueAnimator? = null

    private val cekBerkala = object : Runnable {
        override fun run() {
            evaluasi()
            handler.postDelayed(this, 5_000)
        }
    }

    fun mulai() {
        handler.removeCallbacks(cekBerkala)
        handler.post(cekBerkala)
    }

    fun hentikan() {
        handler.removeCallbacks(cekBerkala)
        hapus()
    }

    fun perbarui(k: Keadaan) {
        keadaan = k
        evaluasi()
    }

    private fun evaluasi() {
        val k = keadaan
        val rt = k?.status?.runningText
        val boleh = rt != null &&
            k.tahap == Tahap.Aktif &&
            rt.teks.isNotBlank() &&
            (rt.sampaiMs == null || Jam.sekarang() < rt.sampaiMs) &&
            !(rt.sembunyiSaatMain && k.layar in LAYAR_DIMAINKAN) &&
            Settings.canDrawOverlays(ctx)

        if (!boleh) {
            hapus()
            return
        }
        if (rt == tayang && strip != null) return

        hapus()
        pasang(rt!!)
    }

    private fun pasang(rt: RunningText) {
        val dp = ctx.resources.displayMetrics.density
        val isi = rt.teks + "          •          " // jeda antar putaran

        val teks = TextView(ctx).apply {
            text = isi
            isSingleLine = true
            setHorizontallyScrolling(true)
            setTextColor(runCatching { Color.parseColor(rt.warna) }.getOrDefault(Color.WHITE))
            setTextSize(
                TypedValue.COMPLEX_UNIT_SP,
                when (rt.ukuran) {
                    "kecil" -> 16f
                    "besar" -> 30f
                    else -> 22f
                },
            )
            typeface = Typeface.create(Typeface.SANS_SERIF, if (rt.tebal) Typeface.BOLD else Typeface.NORMAL)
            setShadowLayer(4f, 0f, 1f, Color.argb(160, 0, 0, 0)) // tetap terbaca walau latar transparan
        }
        val lebarTeks = teks.paint.measureText(isi).toInt() + 1

        val wadah = FrameLayout(ctx).apply {
            setBackgroundColor(Color.argb((rt.opasitas.coerceIn(0, 100) * 255) / 100, 0, 0, 0))
            setPadding(0, (6 * dp).toInt(), 0, (6 * dp).toInt())
            clipChildren = true
            addView(teks, FrameLayout.LayoutParams(lebarTeks, FrameLayout.LayoutParams.WRAP_CONTENT))
        }

        val param = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            // Tanpa FLAG_NOT_TOUCHABLE supaya kepekatan latar sesuai setelan (Android 12+ membatasi jendela
            // tembus-sentuh maks. 80%); TV tanpa layar sentuh, NOT_FOCUSABLE menjaga remote & stik tetap jalan.
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT,
        ).apply { gravity = if (rt.posisi == "atas") Gravity.TOP else Gravity.BOTTOM }

        runCatching {
            wm.addView(wadah, param)
            strip = wadah
            tayang = rt
        }.onFailure { return }

        // Geser dari tepi kanan sampai teks habis di kiri, berulang terus
        wadah.post {
            val lebarLayar = wadah.width.takeIf { it > 0 } ?: ctx.resources.displayMetrics.widthPixels
            val kecepatan = when (rt.kecepatan) {
                "lambat" -> 60f
                "cepat" -> 170f
                else -> 110f
            } * dp // px per detik
            val jarak = (lebarLayar + lebarTeks).toFloat()

            gerak = ValueAnimator.ofFloat(lebarLayar.toFloat(), -lebarTeks.toFloat()).apply {
                duration = (jarak / kecepatan * 1000).toLong().coerceAtLeast(3_000)
                interpolator = LinearInterpolator()
                repeatCount = ValueAnimator.INFINITE
                addUpdateListener { teks.translationX = it.animatedValue as Float }
                start()
            }
        }
    }

    private fun hapus() {
        gerak?.cancel()
        gerak = null
        strip?.let { runCatching { wm.removeView(it) } }
        strip = null
        tayang = null
    }

    private companion object {
        /** Layar saat unit sedang dimainkan (termasuk dijeda) */
        val LAYAR_DIMAINKAN = setOf("main", "jeda")
    }
}
