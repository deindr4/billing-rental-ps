package id.rentalps.tvagent.ui

import android.graphics.Bitmap
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.FilterQuality
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import id.rentalps.tvagent.core.Jam
import id.rentalps.tvagent.data.BayarMandiri

/**
 * Bayar mandiri di TV:
 *  - belum ada tagihan : QR menuju halaman HP ("ketik nominal") + tarif per jam
 *  - ada tagihan       : QRIS nominal itu (dibayar dengan aplikasi bank / e-wallet), sisa waktu berlaku
 * TV terbuka otomatis begitu pembayaran terdeteksi server (status berikutnya berubah ke "main").
 */
@Composable
fun KontenBayarMandiri(bm: BayarMandiri, sekarangMs: Long, besar: Boolean = true) {
    val aksen = LocalAksen.current
    val t = bm.tagihan

    // Ukuran dalam dp = proporsi layar yang sama di TV kecil maupun besar (layar TV ±960×540 dp).
    // Panel ajakan dibuat ringkas (±30% lebar layar); QRIS pembayaran tetap cukup besar untuk dipindai dari HP.
    if (t == null) {
        Label(if (bm.jenis == "isi_ulang") "Isi ulang waktu" else "Main sekarang", warna = aksen, ukuran = 10.sp)
        Spacer(Modifier.height(2.dp))
        Text(
            if (bm.jenis == "isi_ulang") "Scan untuk tambah waktu" else "Scan untuk main",
            color = Warna.teks, fontSize = if (besar) 16.sp else 15.sp, fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            KotakQr(bm.url, if (besar) 92.dp else 84.dp, tepi = 5.dp)
            Column(Modifier.width(132.dp), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Text("1. Scan kamera HP", color = Warna.redup, fontSize = 11.sp, maxLines = 1)
                Text("2. Ketik nominal", color = Warna.redup, fontSize = 11.sp, maxLines = 1)
                Text("3. Bayar QRIS di TV", color = Warna.redup, fontSize = 11.sp, maxLines = 1)
                Spacer(Modifier.height(4.dp))
                bm.tarifPerJam?.let { Label("${rupiah(it)}/jam", warna = aksen, ukuran = 11.sp) }
                Label("Min. ${bm.minimalMenit} menit", ukuran = 9.sp)
            }
        }
        return
    }

    val sisa = ((t.kedaluwarsaMs - sekarangMs) / 1000).coerceAtLeast(0)

    val tautan = t.tipe == "tautan"

    Label(if (tautan) "Scan untuk bayar" else "Scan QRIS untuk bayar", warna = aksen, ukuran = 10.sp)
    Spacer(Modifier.height(8.dp))
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        KotakQr(t.qris, if (besar) 140.dp else 128.dp)
        Column(Modifier.width(132.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Angka(rupiah(t.nominal), aksen, if (besar) 20.sp else 18.sp)
            Text("= ${t.label}", color = Warna.teks, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
            Spacer(Modifier.height(4.dp))
            Text(
                if (tautan) "Scan kamera HP, pilih metode bayar" else "Bank / e-wallet apa saja",
                color = Warna.redup, fontSize = 11.sp,
            )
            Spacer(Modifier.height(4.dp))
            Label("Berlaku ${Jam.format(sisa).substring(3)}", warna = if (sisa < 60) Warna.kuning else Warna.redup, ukuran = 10.sp)
            Label("Menunggu bayar…", ukuran = 9.sp)
        }
    }
}

/** QR hitam di atas kotak putih (mudah dipindai dari layar TV) */
@Composable
fun KotakQr(teks: String, ukuran: Dp, tepi: Dp = 7.dp) {
    val gambar = remember(teks) { buatQr(teks) }

    Box(
        Modifier
            .background(Color.White, RoundedCornerShape(6.dp))
            .padding(tepi), // zona tenang putih di sekeliling QR (wajib supaya mudah dipindai)
    ) {
        if (gambar != null) {
            Image(gambar, contentDescription = "QR", filterQuality = FilterQuality.None, modifier = Modifier.size(ukuran))
        } else {
            Text("QR gagal dibuat", color = Color.Black, fontSize = 11.sp, textAlign = TextAlign.Center, modifier = Modifier.size(ukuran))
        }
    }
}

private fun buatQr(teks: String): ImageBitmap? = runCatching {
    val m = QRCodeWriter().encode(
        teks, BarcodeFormat.QR_CODE, 0, 0,
        mapOf(EncodeHintType.MARGIN to 0, EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M),
    )
    // Satu piksel per modul; diperbesar tanpa penghalusan saat digambar (FilterQuality.None)
    val bmp = Bitmap.createBitmap(m.width, m.height, Bitmap.Config.ARGB_8888)
    for (x in 0 until m.width) for (y in 0 until m.height) {
        bmp.setPixel(x, y, if (m[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    }
    bmp.asImageBitmap()
}.getOrNull()
