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
import android.widget.FrameLayout
import android.widget.TextView
import id.rentalps.tvagent.data.Pemberitahuan

/**
 * Pemberitahuan dari kasir di tengah layar (di atas game maupun layar kunci), hilang sendiri setelah [Pemberitahuan.detik].
 * Tidak bisa difokus/diklik, jadi stik PS & remote tetap jalan. Butuh izin "tampil di atas aplikasi lain".
 */
class OverlayPemberitahuan(private val ctx: Context) {
    private val wm = ctx.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    private val handler = Handler(Looper.getMainLooper())
    private var tampil: View? = null

    private val tutupNanti = Runnable { tutup() }

    fun tampilkan(p: Pemberitahuan, aksen: String?) {
        if (!Settings.canDrawOverlays(ctx)) return
        tutupSegera()

        val dp = ctx.resources.displayMetrics.density
        val lebarLayar = ctx.resources.displayMetrics.widthPixels
        val warnaAksen = runCatching { Color.parseColor(aksen ?: "#4ADE80") }.getOrDefault(Color.parseColor("#4ADE80"))

        val teks = TextView(ctx).apply {
            text = p.teks
            setTextColor(Color.parseColor("#E6EDF5"))
            setTextSize(
                TypedValue.COMPLEX_UNIT_SP,
                when (p.ukuran) {
                    "sedang" -> 26f
                    "jumbo" -> 52f
                    else -> 38f
                },
            )
            val keluarga = when (p.huruf) {
                "serif" -> Typeface.SERIF
                "mono" -> Typeface.MONOSPACE
                else -> Typeface.SANS_SERIF
            }
            typeface = Typeface.create(keluarga, if (p.tebal) Typeface.BOLD else Typeface.NORMAL)
            gravity = Gravity.CENTER
            setLineSpacing(0f, 1.15f)
            maxWidth = (lebarLayar * 0.72f).toInt()
        }

        val kotak = FrameLayout(ctx).apply {
            background = GradientDrawable().apply {
                cornerRadius = 18 * dp
                setColor(Color.argb(250, 15, 28, 43)) // navy Stitch #0f1c2b
                setStroke((2 * dp).toInt(), warnaAksen)
            }
            setPadding((40 * dp).toInt(), (26 * dp).toInt(), (40 * dp).toInt(), (26 * dp).toInt())
            elevation = 24 * dp
            alpha = 0f
            addView(teks)
        }

        val param = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            // Tanpa FLAG_NOT_TOUCHABLE: Android 12+ membatasi jendela tembus-sentuh maks. 80% pekat.
            // TV tidak punya layar sentuh, jadi aman; NOT_FOCUSABLE tetap -> remote & stik PS tidak terganggu.
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
            PixelFormat.TRANSLUCENT,
        ).apply { gravity = Gravity.CENTER }

        runCatching {
            wm.addView(kotak, param)
            tampil = kotak
            kotak.animate().alpha(1f).setDuration(250).start()
            handler.postDelayed(tutupNanti, p.detik.coerceIn(3, 120) * 1000L)
        }
    }

    /** Memudar lalu dilepas */
    fun tutup() {
        val v = tampil ?: return
        v.animate().alpha(0f).setDuration(300).withEndAction {
            runCatching { wm.removeView(v) }
            if (tampil === v) tampil = null
        }.start()
    }

    fun tutupSegera() {
        handler.removeCallbacks(tutupNanti)
        tampil?.let { v ->
            v.animate().cancel()
            runCatching { wm.removeView(v) }
        }
        tampil = null
    }
}
