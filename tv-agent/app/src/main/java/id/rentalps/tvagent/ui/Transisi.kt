package id.rentalps.tvagent.ui

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.rentalps.tvagent.R
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.Keadaan

/**
 * Transisi logo rental di tengah layar:
 * - "mulai": layar kunci -> (logo) -> HDMI PlayStation
 * - "selesai": game -> (logo) -> layar waktu habis / tagihan
 * Logo = logo dari Admin -> Pengaturan Tampilan (sama dengan layar kunci & kasir); belum diunggah = logo bawaan APK.
 */
@Composable
fun LayarTransisi(agent: Agent, keadaan: Keadaan, jenis: String) {
    val s = keadaan.status
    val aksen = LocalAksen.current
    val logo = gambarDariUrl(s?.tema?.logoUrl, agent.api.http)
    val mulai = jenis == "mulai"

    // Muncul pelan: memudar masuk + sedikit membesar
    val muncul = remember(jenis) { Animatable(0f) }
    LaunchedEffect(jenis) {
        android.util.Log.i(Agent.TAG, "Transisi logo tampil: $jenis")
        muncul.animateTo(1f, tween(durationMillis = 700, easing = FastOutSlowInEasing))
    }

    Box(
        Modifier
            .fillMaxSize()
            .background(Warna.latar)
            .background(Brush.radialGradient(listOf(aksen.copy(alpha = 0.20f), Warna.latar), radius = 1100f)),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
            modifier = Modifier.graphicsLayer {
                alpha = muncul.value
                val skala = 0.88f + 0.12f * muncul.value
                scaleX = skala
                scaleY = skala
            },
        ) {
            val ukuranLogo = Modifier.height(240.dp).widthIn(max = 620.dp)
            if (logo != null) {
                Image(logo, contentDescription = null, contentScale = ContentScale.Fit, modifier = ukuranLogo)
            } else {
                Image(painterResource(R.drawable.ikon), contentDescription = null, contentScale = ContentScale.Fit, modifier = ukuranLogo)
            }

            Spacer(Modifier.height(32.dp))
            Text(
                if (mulai) "SELAMAT BERMAIN" else "WAKTU BERMAIN SELESAI",
                color = aksen,
                fontSize = 22.sp,
                fontWeight = FontWeight.Bold,
                fontFamily = FontFamily.Monospace,
                letterSpacing = 5.sp,
            )
            Spacer(Modifier.height(10.dp))
            Text(
                listOfNotNull(
                    s?.unit?.nama,
                    if (mulai) "Membuka PlayStation…" else "Terima kasih sudah bermain",
                ).joinToString(" · "),
                color = Warna.redup,
                fontSize = 16.sp,
            )
        }
    }
}
