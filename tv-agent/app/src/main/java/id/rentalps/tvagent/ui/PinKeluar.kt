package id.rentalps.tvagent.ui

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import id.rentalps.tvagent.AgentApp
import id.rentalps.tvagent.core.Agent
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Lama staf boleh berada di layar TV lain setelah PIN benar */
private const val MENIT_KELUAR = 5

/**
 * Tombol Home saat TV terkunci: minta PIN staf (izin Bypass TV) atau kode darurat.
 * Benar -> layar utama Google TV terbuka 5 menit, lalu TV Agent mengunci lagi.
 * Tidak diisi -> tertutup sendiri setelah 30 detik.
 */
@Composable
fun DialogPinKeluar(agent: Agent, onTutup: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val fokus = remember { FocusRequester() }
    var isian by remember { mutableStateOf("") }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sibuk by remember { mutableStateOf(false) }
    var aktifTerakhir by remember { mutableStateOf(System.currentTimeMillis()) }

    Dialog(
        onDismissRequest = onTutup,
        properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnClickOutside = false),
    ) {
        Box(contentAlignment = Alignment.Center, modifier = Modifier.fillMaxSize().background(Color(0xCC000000))) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier
                    .width(600.dp)
                    .background(Warna.latar, RoundedCornerShape(16.dp))
                    .border(2.dp, Warna.garis, RoundedCornerShape(16.dp))
                    .padding(28.dp),
            ) {
                Label("TV terkunci", warna = LocalAksen.current)
                Spacer(Modifier.height(6.dp))
                Text("Masukkan PIN staf untuk keluar", color = Warna.teks, fontSize = 24.sp, fontWeight = FontWeight.Bold)
                Text(
                    "Layar TV terbuka $MENIT_KELUAR menit, lalu kembali terkunci. Tanpa server, pakai kode darurat.",
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
                                (ctx.applicationContext as AgentApp).izinkanKeluar(MENIT_KELUAR * 60)
                                onTutup()
                                bukaLauncherBawaan(ctx)
                            } else {
                                pesan = error
                                isian = ""
                            }
                        }
                    },
                )

                pesan?.let {
                    Spacer(Modifier.height(12.dp))
                    Text(it, color = Warna.merah, fontSize = 15.sp)
                }
                Spacer(Modifier.height(10.dp))
                TombolTv("Batal", kecil = true, onClick = onTutup)
            }
        }
    }

    LaunchedEffect(Unit) {
        repeat(5) {
            delay(80)
            if (runCatching { fokus.requestFocus() }.isSuccess) return@repeat
        }
    }

    // Tidak diisi 30 detik -> tutup (TV tetap terkunci)
    LaunchedEffect(aktifTerakhir) {
        delay(30_000)
        onTutup()
    }
}

/** Buka layar utama bawaan TV (Google TV / Android TV), walau TV Agent sedang jadi launcher utama */
fun bukaLauncherBawaan(ctx: Context) {
    val home = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
    val lain = ctx.packageManager.queryIntentActivities(home, PackageManager.MATCH_ALL)
        .firstOrNull { it.activityInfo.packageName != ctx.packageName }

    val intent = if (lain != null) {
        Intent(home).setComponent(ComponentName(lain.activityInfo.packageName, lain.activityInfo.name))
    } else {
        Intent(android.provider.Settings.ACTION_SETTINGS)
    }
    runCatching { ctx.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
