package id.rentalps.tvagent.ui

import android.content.Intent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.Hdmi
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Setup: "PS tersambung ke HDMI berapa?". Staf memilih input → TV pindah ke input itu sebagai tes →
 * 6 detik kemudian TV Agent kembali & bertanya "Tampil PS?". Pilihan disimpan di TV & server.
 */
@Composable
fun LayarPilihHdmi(agent: Agent, onSelesai: () -> Unit = {}) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val aksen = LocalAksen.current
    val daftar = remember { Hdmi.daftarInput(ctx) }
    var dites by remember { mutableStateOf<Hdmi.Input?>(null) }
    var konfirmasi by remember { mutableStateOf(false) }
    var pesan by remember { mutableStateOf<String?>(null) }
    val fokus = remember(konfirmasi) { FocusRequester() }

    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
        modifier = Modifier.fillMaxSize().padding(40.dp),
    ) {
        Label("Setup TV · input PlayStation", warna = aksen)
        Spacer(Modifier.height(8.dp))

        val input = dites
        if (konfirmasi && input != null) {
            Text("Tadi tampil PlayStation di ${input.label}?", color = Warna.teks, fontSize = 30.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(24.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                TombolTv("Ya, benar", utama = true, focusRequester = fokus) {
                    scope.launch {
                        pesan = agent.pilihInputHdmi(input)
                        if (pesan == null) onSelesai()
                    }
                }
                TombolTv("Bukan, pilih lain") {
                    konfirmasi = false
                    dites = null
                }
            }
        } else {
            Text("PS tersambung ke HDMI berapa?", color = Warna.teks, fontSize = 30.sp, fontWeight = FontWeight.Bold)
            Text(
                "Pilih input, TV akan pindah sebentar untuk dicek lalu kembali ke layar ini.",
                color = Warna.redup, fontSize = 15.sp, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(24.dp))

            val terpilih = agent.inputHdmi()
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                daftar.forEachIndexed { i, masuk ->
                    TombolTv(
                        (if (masuk.id == terpilih) "✓ " else "") + masuk.label,
                        utama = masuk.hdmi,
                        focusRequester = if (i == 0) fokus else null,
                        modifier = Modifier.width(170.dp),
                    ) {
                        dites = masuk
                        // Tes HDMI sengaja meninggalkan layar TV Agent: jangan ditarik balik oleh mode kiosk
                        (ctx.applicationContext as AgentApp).izinkanKeluar(12)
                        if (Hdmi.bukaInput(ctx, masuk)) {
                            scope.launch {
                                delay(6_000)
                                // Kembali ke TV Agent untuk konfirmasi
                                ctx.startActivity(
                                    Intent(ctx, MainActivity::class.java)
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
                                )
                                konfirmasi = true
                            }
                        } else {
                            pesan = "Gagal pindah ke ${masuk.label}: ${Hdmi.errorTerakhir ?: "tidak didukung TV"}"
                        }
                    }
                }
            }
            if (daftar.isEmpty()) {
                Text("Tidak ada input HDMI terdeteksi di TV ini.", color = Warna.kuning, fontSize = 16.sp)
            }
            if (dites != null && !konfirmasi) {
                Spacer(Modifier.height(16.dp))
                Label("Mengecek ${dites?.label}… TV akan kembali ke sini")
            }
        }

        pesan?.let {
            Spacer(Modifier.height(14.dp))
            Text(it, color = Warna.kuning, fontSize = 14.sp)
        }
    }

    LaunchedEffect(konfirmasi) { runCatching { fokus.requestFocus() } }
}

/** Saat sesi berjalan tapi pindah HDMI gagal: pilih input lalu langsung dibuka (tanpa tes) */
@Composable
fun PilihanInputCepat(agent: Agent) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val daftar = remember { Hdmi.daftarInput(ctx) }
    val fokus = remember { FocusRequester() }

    if (daftar.isEmpty()) {
        Text("Tidak ada input HDMI terdeteksi. Hubungi kasir.", color = Warna.kuning, fontSize = 15.sp)
        return
    }

    Label("Pilih input PlayStation")
    Spacer(Modifier.height(8.dp))
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        daftar.forEachIndexed { i, masuk ->
            TombolTv(masuk.label, utama = masuk.hdmi, focusRequester = if (i == 0) fokus else null) {
                scope.launch { agent.pilihInputHdmi(masuk) }
                agent.setHdmiGagal(!Hdmi.bukaInput(ctx, masuk))
            }
        }
    }

    LaunchedEffect(Unit) { runCatching { fokus.requestFocus() } }
}
