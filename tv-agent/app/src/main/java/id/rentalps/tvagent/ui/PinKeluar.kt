package id.rentalps.tvagent.ui

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.AplikasiTv
import id.rentalps.tvagent.core.Hdmi
import id.rentalps.tvagent.core.Keadaan
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** YouTube untuk Android TV / Google TV */
private const val PAKET_YOUTUBE = "com.google.android.youtube.tv"

private enum class Tujuan { GoogleTv, Hdmi, YouTube }

/**
 * Akses staf (Home lalu OK): PIN staf (izin Bypass TV) atau kode darurat, lalu pilih:
 * Google TV, HDMI (PlayStation), YouTube, atau tutup aplikasi.
 * Tujuan dibuka sebagai bypass di server (tercatat & tampil di kasir) selama durasi terpilih;
 * bila server tidak bisa dihubungi, TV dibuka lokal selama durasi yang sama.
 */
@Composable
fun DialogPinKeluar(agent: Agent, k: Keadaan, onPengaturan: () -> Unit, onTutup: () -> Unit) {
    val ctx = LocalContext.current
    val app = ctx.applicationContext as AgentApp
    val scope = rememberCoroutineScope()
    val fokus = remember { FocusRequester() }
    val fokusMenu = remember { FocusRequester() }
    var isian by remember { mutableStateOf("") }
    var pinBenar by remember { mutableStateOf<String?>(null) }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sibuk by remember { mutableStateOf(false) }
    var aktifTerakhir by remember { mutableStateOf(System.currentTimeMillis()) }

    val pilihan = k.status?.pengaturan?.bypassPilihan.orEmpty().ifEmpty { listOf(15, 30, 60) }
    var menit by remember { mutableIntStateOf(k.status?.pengaturan?.durasiBypassMenit?.takeIf { it in pilihan } ?: pilihan.first()) }

    // Menu baru muncul saat tombol OK keypad masih ditekan: lepasnya tombol jangan sampai "mengklik" pilihan pertama
    var menuSiapMs by remember { mutableStateOf(0L) }
    fun menuSiap() = pinBenar != null && System.currentTimeMillis() - menuSiapMs > 700

    fun buka(tujuan: Tujuan) {
        val pin = pinBenar ?: return
        if (sibuk || !menuSiap()) return
        sibuk = true
        // Scope sendiri: dialog ditutup lebih dulu, proses pindah tujuan tidak boleh ikut batal
        CoroutineScope(SupervisorJob() + Dispatchers.Main).launch {
            // Bypass di server (tercatat di kasir & admin); gagal (offline / kode darurat) -> buka lokal saja
            agent.bypass(pin, menit)
            app.izinkanKeluar(menit * 60)
            onTutup()
            delay(900) // beri waktu layar bypass tampil dulu, lalu pindah ke tujuan
            when (tujuan) {
                Tujuan.GoogleTv -> bukaLauncherBawaan(ctx)
                Tujuan.Hdmi -> agent.setHdmiGagal(!Hdmi.buka(ctx, agent.inputHdmi()))
                Tujuan.YouTube -> if (!AplikasiTv.buka(ctx, PAKET_YOUTUBE)) bukaLauncherBawaan(ctx)
            }
        }
    }

    Dialog(
        onDismissRequest = onTutup,
        properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnClickOutside = false),
    ) {
        Box(contentAlignment = Alignment.Center, modifier = Modifier.fillMaxSize().background(Color(0xCC000000))) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier
                    // Tombol remote apa pun (panah, angka) = masih dipakai; batas diam dihitung ulang
                    .onPreviewKeyEvent { aktifTerakhir = System.currentTimeMillis(); false }
                    .width(600.dp)
                    .background(Warna.latar, RoundedCornerShape(16.dp))
                    .border(2.dp, Warna.garis, RoundedCornerShape(16.dp))
                    .padding(28.dp),
            ) {
                Label("Akses staf", warna = LocalAksen.current)
                Spacer(Modifier.height(6.dp))

                if (pinBenar == null) {
                    Text("Masukkan PIN staf", color = Warna.teks, fontSize = 24.sp, fontWeight = FontWeight.Bold)
                    Text(
                        "PIN supervisor/owner. Tanpa server, pakai kode darurat.",
                        color = Warna.redup, fontSize = 14.sp, textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(18.dp))

                    KeypadAngka(
                        nilai = isian, maks = 6, focusAwal = fokus,
                        onUbah = { isian = it; pesan = null; aktifTerakhir = System.currentTimeMillis() },
                        onKirim = {
                            if (isian.length < 4 || sibuk) return@KeypadAngka
                            sibuk = true
                            scope.launch {
                                val error = agent.verifikasiStaf(isian)
                                sibuk = false
                                if (error == null) {
                                    menuSiapMs = System.currentTimeMillis()
                                    pinBenar = isian
                                    aktifTerakhir = System.currentTimeMillis()
                                } else {
                                    pesan = error
                                    isian = ""
                                }
                            }
                        },
                    )
                    Spacer(Modifier.height(10.dp))
                    TombolTv("Batal", kecil = true, onClick = onTutup)
                } else {
                    Text("Buka ke mana?", color = Warna.teks, fontSize = 24.sp, fontWeight = FontWeight.Bold)
                    Text(
                        "TV terbuka selama durasi terpilih, lalu terkunci lagi. Tercatat di kasir.",
                        color = Warna.redup, fontSize = 14.sp, textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(14.dp))

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Label("Durasi")
                        pilihan.forEach { m ->
                            TombolTv(
                                if (m >= 60 && m % 60 == 0) "${m / 60} jam" else "$m mnt",
                                kecil = true, utama = menit == m,
                            ) { menit = m; aktifTerakhir = System.currentTimeMillis() }
                        }
                    }
                    Spacer(Modifier.height(14.dp))

                    Column(verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.width(420.dp)) {
                        TombolTv("Google TV", utama = true, modifier = Modifier.fillMaxWidth(), focusRequester = fokusMenu) { buka(Tujuan.GoogleTv) }
                        TombolTv("HDMI (PlayStation)", modifier = Modifier.fillMaxWidth()) { buka(Tujuan.Hdmi) }
                        TombolTv("YouTube", modifier = Modifier.fillMaxWidth()) { buka(Tujuan.YouTube) }
                        TombolTv("Tutup aplikasi", bahaya = true, modifier = Modifier.fillMaxWidth()) {
                            if (!menuSiap()) return@TombolTv
                            // TV bebas sampai dikunci lagi dari kasir (tombol Lock) atau sesi berikutnya dimulai
                            app.tutupAplikasi() // activity pindah ke layar Google TV
                            onTutup()
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            TombolTv("Pengaturan TV Agent", kecil = true) { if (menuSiap()) { onTutup(); onPengaturan() } }
                            TombolTv("Batal", kecil = true) { if (menuSiap()) onTutup() }
                        }
                    }
                }

                pesan?.let {
                    Spacer(Modifier.height(12.dp))
                    Text(it, color = Warna.merah, fontSize = 15.sp)
                }
                if (sibuk) {
                    Spacer(Modifier.height(8.dp))
                    Text("Memproses…", color = Warna.redup, fontSize = 14.sp)
                }
            }
        }
    }

    LaunchedEffect(pinBenar) {
        val target = if (pinBenar == null) fokus else fokusMenu
        repeat(5) {
            delay(80)
            if (runCatching { target.requestFocus() }.isSuccess) return@repeat
        }
    }

    // Tidak disentuh 60 detik -> tutup (TV tetap terkunci)
    LaunchedEffect(aktifTerakhir) {
        delay(60_000)
        onTutup()
    }
}

/** Buka layar utama bawaan TV (Google TV / Android TV), walau TV Agent sedang jadi launcher utama */
fun bukaLauncherBawaan(ctx: Context) {
    val home = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
    // Utamakan launcher Google TV / Android TV / merek TV; abaikan "FallbackHome" (layar kosong cadangan sistem)
    val dikenal = listOf("com.google.android.apps.tv.launcherx", "com.google.android.tvlauncher")
    val lain = ctx.packageManager.queryIntentActivities(home, PackageManager.MATCH_ALL)
        .filter { it.activityInfo.packageName != ctx.packageName && !it.activityInfo.name.endsWith("FallbackHome") }
        .sortedWith(compareBy({ dikenal.indexOf(it.activityInfo.packageName).let { i -> if (i < 0) 99 else i } }, { -it.priority }))
        .firstOrNull()

    val intent = if (lain != null) {
        Intent(home).setComponent(ComponentName(lain.activityInfo.packageName, lain.activityInfo.name))
    } else {
        Intent(android.provider.Settings.ACTION_SETTINGS)
    }
    runCatching { ctx.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
