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

    if (t == null) {
        Label(if (bm.jenis == "isi_ulang") "Isi ulang waktu" else "Main sekarang", warna = aksen)
        Spacer(Modifier.height(4.dp))
        Text(
            if (bm.jenis == "isi_ulang") "Scan untuk tambah waktu" else "Scan untuk main",
            color = Warna.teks, fontSize = if (besar) 20.sp else 18.sp, fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            KotakQr(bm.url, if (besar) 128.dp else 112.dp)
            Column(Modifier.width(if (besar) 170.dp else 150.dp)) {
                Text("1. Scan dengan kamera HP", color = Warna.redup, fontSize = 12.sp)
                Text("2. Ketik nominal, bebas", color = Warna.redup, fontSize = 12.sp)
                Text("3. Bayar QRIS yang muncul di TV", color = Warna.redup, fontSize = 12.sp)
                Spacer(Modifier.height(6.dp))
                bm.tarifPerJam?.let { Label("${rupiah(it)} / jam", warna = aksen, ukuran = 12.sp) }
                Label("Min. ${bm.minimalMenit} menit · TV terbuka otomatis", ukuran = 10.sp)
            }
        }
        return
    }

    val sisa = ((t.kedaluwarsaMs - sekarangMs) / 1000).coerceAtLeast(0)

    val tautan = t.tipe == "tautan"

    Label(if (tautan) "Scan untuk bayar" else "Scan QRIS untuk bayar", warna = aksen)
    Spacer(Modifier.height(8.dp))
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        KotakQr(t.qris, if (besar) 190.dp else 160.dp)
        Column {
            Angka(rupiah(t.nominal), aksen, if (besar) 26.sp else 22.sp)
            Text("= ${t.label}", color = Warna.teks, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(8.dp))
            Text(
                if (tautan) "Scan dengan kamera HP,\npilih metode bayar" else "Pakai aplikasi bank /\ne-wallet apa saja",
                color = Warna.redup, fontSize = 12.sp,
            )
            Spacer(Modifier.height(8.dp))
            Label("Berlaku ${Jam.format(sisa).substring(3)}", warna = if (sisa < 60) Warna.kuning else Warna.redup, ukuran = 11.sp)
            Label("Menunggu pembayaran…", ukuran = 10.sp)
        }
    }
}

/** QR hitam di atas kotak putih (mudah dipindai dari layar TV) */
@Composable
fun KotakQr(teks: String, ukuran: Dp) {
    val gambar = remember(teks) { buatQr(teks) }

    Box(
        Modifier
            .background(Color.White, RoundedCornerShape(8.dp))
            .padding(8.dp),
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
