package id.rentalps.tvagent.ui

import android.graphics.BitmapFactory
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.focusable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.text.NumberFormat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/* Token warna desain Stitch (sama dengan resources/css/shared/tokens.css di server) */
object Warna {
    val latar = Color(0xFF0A1420)
    val permukaan = Color(0xFF0F1C2B)
    val permukaan2 = Color(0xFF152537)
    val garis = Color(0xFF1E3144)
    val teks = Color(0xFFE6EDF5)
    val redup = Color(0xFF7F90A6)
    val hijau = Color(0xFF4ADE80)     // aksen default & status siap
    val biru = Color(0xFF38BDF8)      // terisi / open bill
    val kuning = Color(0xFFFBBF24)    // segera habis
    val oranye = Color(0xFFFB923C)    // maintenance
    val merah = Color(0xFFF87171)     // bahaya / terkunci
    val jeda = Color(0xFFA5B4C8)
}

/** Warna aksen dari Admin → Tampilan (default mint) */
val LocalAksen = staticCompositionLocalOf { Warna.hijau }

fun warnaDariHex(hex: String?): Color? = runCatching {
    Color(android.graphics.Color.parseColor(hex ?: return null))
}.getOrNull()

fun teksDiAtas(latar: Color): Color = if (latar.luminance() > 0.45f) Color(0xFF052E16) else Color.White

fun rupiah(n: Long): String = (if (n < 0) "-Rp " else "Rp ") + NumberFormat.getInstance(Locale("id", "ID")).format(kotlin.math.abs(n))

fun jam(ms: Long, zona: String?, pola: String = "HH:mm"): String =
    SimpleDateFormat(pola, Locale("id", "ID")).apply { zona?.let { timeZone = TimeZone.getTimeZone(it) } }.format(Date(ms))

/** Label kecil huruf kapital, mono, renggang (gaya Stitch) */
@Composable
fun Label(teks: String, warna: Color = Warna.redup, ukuran: TextUnit = 11.sp, modifier: Modifier = Modifier) =
    Text(
        teks.uppercase(),
        color = warna,
        fontSize = ukuran,
        fontFamily = FontFamily.Monospace,
        letterSpacing = 1.4.sp,
        modifier = modifier,
    )

/** Angka besar mono (timer, uang, kode) */
@Composable
fun Angka(teks: String, warna: Color = Warna.teks, ukuran: TextUnit = 28.sp, modifier: Modifier = Modifier) =
    Text(teks, color = warna, fontSize = ukuran, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold, modifier = modifier)

@Composable
fun Titik(warna: Color, ukuran: Int = 8) =
    Box(Modifier.size(ukuran.dp).background(warna, CircleShape))

/** Chip kotak kecil: VIP ROOM 1, PS5, KONSOL TERKUNCI */
@Composable
fun Chip(teks: String, warna: Color = Warna.redup, latar: Color = Warna.permukaan2, garis: Color? = null) {
    Box(
        Modifier
            .background(latar, RoundedCornerShape(4.dp))
            .then(if (garis != null) Modifier.border(1.dp, garis, RoundedCornerShape(4.dp)) else Modifier)
            .padding(horizontal = 7.dp, vertical = 3.dp),
    ) {
        Label(teks, warna = warna, ukuran = 10.sp)
    }
}

/** Panel/kartu: latar gelap, garis tipis, sudut kecil */
@Composable
fun Panel(modifier: Modifier = Modifier, garis: Color = Warna.garis, isi: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier = modifier
            .background(Warna.permukaan, RoundedCornerShape(10.dp))
            .border(1.dp, garis, RoundedCornerShape(10.dp))
            .padding(16.dp),
        content = isi,
    )
}

/** Tombol yang jelas saat difokus remote (membesar + garis tepi putih) */
@Composable
fun TombolTv(
    teks: String,
    modifier: Modifier = Modifier,
    utama: Boolean = false,
    bahaya: Boolean = false,
    kecil: Boolean = false,
    focusRequester: FocusRequester? = null,
    onClick: () -> Unit,
) {
    val interaksi = remember { MutableInteractionSource() }
    val fokus by interaksi.collectIsFocusedAsState()
    val aksen = LocalAksen.current
    val warnaDasar = when {
        bahaya -> Warna.merah
        utama -> aksen
        else -> Warna.permukaan2
    }
    val warnaTeks = if (utama || bahaya) teksDiAtas(warnaDasar) else Warna.teks

    Box(
        contentAlignment = Alignment.Center,
        modifier = modifier
            .then(if (focusRequester != null) Modifier.focusRequester(focusRequester) else Modifier)
            .scale(if (fokus) 1.05f else 1f)
            .background(if (fokus || utama || bahaya) warnaDasar else warnaDasar.copy(alpha = 0.9f), RoundedCornerShape(8.dp))
            .border(if (fokus) 3.dp else 1.dp, if (fokus) Color.White else Warna.garis, RoundedCornerShape(8.dp))
            .onKeyEvent {
                if (it.type == KeyEventType.KeyUp && (it.key == Key.DirectionCenter || it.key == Key.Enter || it.key == Key.NumPadEnter)) {
                    onClick()
                    true
                } else {
                    false
                }
            }
            .focusable(interactionSource = interaksi)
            .padding(horizontal = if (kecil) 12.dp else 20.dp, vertical = if (kecil) 7.dp else 11.dp),
    ) {
        Text(teks, color = warnaTeks, fontSize = if (kecil) 13.sp else 16.sp, fontWeight = FontWeight.SemiBold)
    }
}

/**
 * Gambar dari server (logo, wallpaper), disimpan di memori.
 * Unduhan gagal / terpotong dicoba ulang (5 dtk, 15 dtk, lalu tiap 60 dtk); gambar besar diperkecil
 * maksimal ±1920 px supaya hemat memori TV.
 */
@Composable
fun gambarDariUrl(url: String?, http: OkHttpClient): ImageBitmap? {
    var gambar by remember(url) { mutableStateOf(url?.let { CacheGambar.ambil(it) }) }

    LaunchedEffect(url) {
        if (url == null || gambar != null) return@LaunchedEffect

        var percobaan = 0
        while (gambar == null) {
            gambar = withContext(Dispatchers.IO) { unduhGambar(url, http) }?.also { CacheGambar.simpan(url, it) }

            if (gambar == null) {
                percobaan++
                delay(if (percobaan == 1) 5_000L else if (percobaan == 2) 15_000L else 60_000L)
            }
        }
    }

    return gambar
}

private fun unduhGambar(url: String, http: OkHttpClient): ImageBitmap? = runCatching {
    http.newCall(Request.Builder().url(url).build()).execute().use { res ->
        if (!res.isSuccessful) return@use null
        val bytes = res.body?.bytes() ?: return@use null

        // Baca ukuran dulu, lalu turunkan resolusi jika lebih dari ±1920 px
        val ukuran = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, ukuran)
        if (ukuran.outWidth <= 0) return@use null // data terpotong / bukan gambar

        var sampel = 1
        while (maxOf(ukuran.outWidth, ukuran.outHeight) / (sampel * 2) >= 1920) sampel *= 2

        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sampel })
            ?.asImageBitmap()
    }
}.getOrNull()

private object CacheGambar {
    private val isi = mutableMapOf<String, ImageBitmap>()
    fun ambil(url: String) = isi[url]
    fun simpan(url: String, g: ImageBitmap) {
        isi[url] = g
    }
}

/**
 * Keypad angka untuk remote: PIN (4-6 digit) atau kode darurat (6 digit).
 * Tombol angka di remote juga bisa langsung dipakai.
 */
@Composable
fun KeypadAngka(
    nilai: String,
    maks: Int,
    onUbah: (String) -> Unit,
    onKirim: () -> Unit,
    focusAwal: FocusRequester,
    samarkan: Boolean = true,
) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            repeat(maks) { i ->
                Box(
                    contentAlignment = Alignment.Center,
                    modifier = Modifier
                        .size(44.dp, 54.dp)
                        .background(Warna.permukaan2, RoundedCornerShape(6.dp))
                        .border(2.dp, if (i == nilai.length) LocalAksen.current else Warna.garis, RoundedCornerShape(6.dp)),
                ) {
                    val c = nilai.getOrNull(i)
                    Angka(if (c == null) "" else if (samarkan) "•" else c.toString(), ukuran = 24.sp)
                }
            }
        }

        Spacer(Modifier.height(18.dp))

        val baris = listOf(listOf("1", "2", "3"), listOf("4", "5", "6"), listOf("7", "8", "9"), listOf("⌫", "0", "OK"))
        Column(
            verticalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier.onKeyEvent { e ->
                if (e.type != KeyEventType.KeyUp) return@onKeyEvent false
                val angka = when (e.key) {
                    Key.Zero, Key.NumPad0 -> "0"; Key.One, Key.NumPad1 -> "1"; Key.Two, Key.NumPad2 -> "2"
                    Key.Three, Key.NumPad3 -> "3"; Key.Four, Key.NumPad4 -> "4"; Key.Five, Key.NumPad5 -> "5"
                    Key.Six, Key.NumPad6 -> "6"; Key.Seven, Key.NumPad7 -> "7"; Key.Eight, Key.NumPad8 -> "8"
                    Key.Nine, Key.NumPad9 -> "9"; else -> null
                }
                if (angka != null && nilai.length < maks) {
                    onUbah(nilai + angka)
                    true
                } else {
                    false
                }
            },
        ) {
            baris.forEachIndexed { r, kolom ->
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    kolom.forEachIndexed { c, t ->
                        TombolTv(
                            teks = t,
                            utama = t == "OK",
                            modifier = Modifier.width(96.dp),
                            focusRequester = if (r == 0 && c == 0) focusAwal else null,
                        ) {
                            when (t) {
                                "⌫" -> onUbah(nilai.dropLast(1))
                                "OK" -> onKirim()
                                else -> if (nilai.length < maks) onUbah(nilai + t)
                            }
                        }
                    }
                }
            }
        }
    }
}
