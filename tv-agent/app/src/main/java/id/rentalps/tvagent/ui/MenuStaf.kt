package id.rentalps.tvagent.ui

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.BackHandler
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.rentalps.tvagent.BuildConfig
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.Diagnostik
import id.rentalps.tvagent.core.Hdmi
import id.rentalps.tvagent.core.Keadaan
import id.rentalps.tvagent.core.Remote
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private enum class Panel { Utama, Bypass, Darurat, Hdmi, Info, GantiServer, Gerbang }

/**
 * Menu staf (dibuka dari tombol "Menu staf" di layar kunci).
 * Aksi yang membuka TV butuh PIN supervisor/owner atau kode darurat.
 */
@Composable
fun MenuStaf(agent: Agent, k: Keadaan, onTutup: () -> Unit) {
    var panel by remember { mutableStateOf(Panel.Utama) }
    var pesan by remember { mutableStateOf<Pair<String, Boolean>?>(null) } // teks, sukses?
    var isian by remember { mutableStateOf("") }
    var sibuk by remember { mutableStateOf(false) }
    var menitBypass by remember { mutableStateOf(k.status?.pengaturan?.durasiBypassMenit ?: 15) }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    val fokusAwal = remember(panel) { FocusRequester() }

    fun ke(p: Panel) {
        panel = p
        isian = ""
        pesan = null
    }

    // Aksi berbahaya (ganti server, izin/Settings, input HDMI) wajib PIN supervisor/owner atau kode darurat,
    // supaya pelanggan tidak bisa melepas/mengacak TV dengan remote. Berlaku selama menu terbuka.
    var terverifikasi by remember { mutableStateOf(false) }
    var tujuan by remember { mutableStateOf(Panel.Utama) }

    fun lewatGerbang(p: Panel) {
        if (terverifikasi) {
            ke(p)
        } else {
            tujuan = p
            ke(Panel.Gerbang)
        }
    }

    // Dialog = jendela tersendiri, jadi fokus remote terkunci di menu (tidak tertinggal di layar belakang)
    Dialog(
        onDismissRequest = onTutup,
        properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnBackPress = false, dismissOnClickOutside = false),
    ) {
    BackHandler { if (panel == Panel.Utama) onTutup() else ke(Panel.Utama) }

    Box(
        contentAlignment = Alignment.Center,
        modifier = Modifier.fillMaxSize().background(Color(0xCC000000)),
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier
                .width(640.dp)
                .background(Warna.latar, RoundedCornerShape(16.dp))
                .border(2.dp, Warna.garis, RoundedCornerShape(16.dp))
                .padding(28.dp),
        ) {
            val judul = when (panel) {
                Panel.Utama -> "Menu staf"
                Panel.Bypass -> "Bypass · PIN supervisor/owner"
                Panel.Darurat -> "Kode darurat"
                Panel.Hdmi -> "Pilih input PS"
                Panel.Info -> "Info & diagnostik"
                Panel.GantiServer -> "Ganti server?"
                Panel.Gerbang -> "PIN supervisor / kode darurat"
            }
            Text(judul, color = Warna.teks, fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(18.dp))

            when (panel) {
                Panel.Utama -> Column(verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.width(420.dp)) {
                    if (k.layar == "bypass") {
                        TombolTv("Akhiri bypass", modifier = Modifier.fillMaxWidth(), focusRequester = fokusAwal) {
                            scope.launch { agent.akhiriBypass(); onTutup() }
                        }
                    } else if (k.layar == "darurat") {
                        TombolTv("Akhiri buka darurat", modifier = Modifier.fillMaxWidth(), focusRequester = fokusAwal) {
                            agent.akhiriDarurat(); onTutup()
                        }
                    } else {
                        TombolTv("Bypass (PIN)", modifier = Modifier.fillMaxWidth(), focusRequester = fokusAwal) { ke(Panel.Bypass) }
                    }
                    TombolTv("Kode darurat (offline)", modifier = Modifier.fillMaxWidth()) { ke(Panel.Darurat) }
                    TombolTv("Pilih input HDMI", modifier = Modifier.fillMaxWidth()) { lewatGerbang(Panel.Hdmi) }
                    TombolTv("Info & diagnostik", modifier = Modifier.fillMaxWidth()) { lewatGerbang(Panel.Info) }
                    TombolTv("Muat ulang status", modifier = Modifier.fillMaxWidth()) { agent.segarkanSekarang(); onTutup() }
                    TombolTv("Ganti server / pasangkan ulang", modifier = Modifier.fillMaxWidth()) { lewatGerbang(Panel.GantiServer) }
                    TombolTv("Tutup", modifier = Modifier.fillMaxWidth(), onClick = onTutup)
                }

                Panel.Bypass -> {
                    // Durasi: pilihan dari admin (dibatasi batas maksimal cabang)
                    val pilihan = k.status?.pengaturan?.bypassPilihan.orEmpty().ifEmpty { listOf(15, 30, 60) }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Label("Durasi")
                        pilihan.forEach { m ->
                            TombolTv(
                                if (m >= 60 && m % 60 == 0) "${m / 60} jam" else "$m mnt",
                                kecil = true,
                                utama = menitBypass == m,
                            ) { menitBypass = m }
                        }
                    }
                    Spacer(Modifier.height(16.dp))
                    KeypadAngka(
                        nilai = isian, maks = 6, focusAwal = fokusAwal,
                        onUbah = { isian = it; pesan = null },
                        onKirim = {
                            if (isian.length < 4 || sibuk) return@KeypadAngka
                            sibuk = true
                            scope.launch {
                                val error = agent.bypass(isian, menitBypass)
                                sibuk = false
                                if (error == null) onTutup() else { pesan = error to false; isian = "" }
                            }
                        },
                    )
                }

                Panel.Gerbang -> {
                    Text(
                        "Masukkan PIN supervisor/owner. Jika server tidak bisa dihubungi, pakai kode darurat.",
                        color = Warna.redup, fontSize = 16.sp, modifier = Modifier.padding(bottom = 16.dp),
                    )
                    KeypadAngka(
                        nilai = isian, maks = 6, focusAwal = fokusAwal,
                        onUbah = { isian = it; pesan = null },
                        onKirim = {
                            if (isian.length < 4 || sibuk) return@KeypadAngka
                            sibuk = true
                            scope.launch {
                                val error = agent.verifikasiStaf(isian)
                                sibuk = false
                                if (error == null) {
                                    terverifikasi = true
                                    ke(tujuan)
                                } else {
                                    pesan = error to false
                                    isian = ""
                                }
                            }
                        },
                    )
                }

                Panel.Darurat -> {
                    Text(
                        "Untuk membuka TV saat server/jaringan mati. Minta kode ke supervisor (Admin → Perangkat TV, atau panel TV di halaman Rental).",
                        color = Warna.redup, fontSize = 16.sp, modifier = Modifier.padding(bottom = 16.dp),
                    )
                    KeypadAngka(
                        nilai = isian, maks = 6, focusAwal = fokusAwal, samarkan = false,
                        onUbah = { isian = it; pesan = null },
                        onKirim = {
                            if (agent.bukaDarurat(isian)) onTutup() else { pesan = "Kode salah atau sudah berganti" to false; isian = "" }
                        },
                    )
                }

                Panel.Hdmi -> {
                    val daftar = remember { Hdmi.daftarInput(ctx) }
                    val dipilih = Hdmi.pilih(ctx, agent.inputHdmi())
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.width(480.dp)) {
                        if (daftar.isEmpty()) {
                            Text("Tidak ada input HDMI terdeteksi di TV ini.", color = Warna.kuning, fontSize = 18.sp)
                        }
                        daftar.forEachIndexed { i, input ->
                            TombolTv(
                                (if (input.id == dipilih?.id) "✓ " else "") + input.label + if (input.hdmi) "" else " (bukan HDMI)",
                                utama = input.id == dipilih?.id,
                                modifier = Modifier.fillMaxWidth(),
                                focusRequester = if (i == 0) fokusAwal else null,
                            ) {
                                scope.launch {
                                    val error = agent.pilihInputHdmi(input)
                                    pesan = (error ?: "Input PS: ${input.label} (tersimpan di server)") to (error == null)
                                }
                            }
                        }
                        TombolTv("Kembali", modifier = Modifier.fillMaxWidth(), focusRequester = if (daftar.isEmpty()) fokusAwal else null) { ke(Panel.Utama) }
                    }
                }

                Panel.Info -> {
                    val diag = remember { Diagnostik.kumpulkan(ctx) }
                    Column(Modifier.height(320.dp).width(560.dp).verticalScroll(rememberScrollState())) {
                        InfoBaris("Server", agent.simpan.serverUrl ?: "-")
                        InfoBaris("Perangkat", k.status?.perangkat?.nama ?: "-")
                        InfoBaris("Unit", k.status?.unit?.nama ?: "-")
                        InfoBaris("Versi app", "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
                        diag.forEach { (kunci, nilai) -> InfoBaris(kunci, nilai.toString().trim('"')) }
                        agent.updater.errorTerakhir?.let { InfoBaris("Error update", it) }
                    }
                    Spacer(Modifier.height(14.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        TombolTv("Izin tampil di atas", focusRequester = fokusAwal) {
                            val ok = runCatching {
                                (ctx.applicationContext as id.rentalps.tvagent.AgentApp).izinkanKeluar(60)
                                ctx.startActivity(
                                    Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:${ctx.packageName}"))
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                )
                            }.isSuccess
                            if (!ok) pesan = "Menu izin tidak tersedia di TV ini; berikan lewat adb" to false
                        }
                        TombolTv("Jadikan layar utama") {
                            // Mode kiosk: TV langsung membuka TV Agent saat dinyalakan & saat tombol Home ditekan
                            if (Diagnostik.launcherUtama(ctx)) {
                                pesan = "TV Agent sudah menjadi layar utama" to true
                                return@TombolTv
                            }
                            val activity = ctx as? android.app.Activity
                            val ok = runCatching {
                                (ctx.applicationContext as id.rentalps.tvagent.AgentApp).izinkanKeluar(60)
                                val role = ctx.getSystemService(android.app.role.RoleManager::class.java)
                                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.Q && role != null &&
                                    role.isRoleAvailable(android.app.role.RoleManager.ROLE_HOME) && activity != null
                                ) {
                                    activity.startActivityForResult(role.createRequestRoleIntent(android.app.role.RoleManager.ROLE_HOME), 7)
                                } else {
                                    ctx.startActivity(Intent(Settings.ACTION_HOME_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                                }
                            }.isSuccess
                            if (!ok) pesan = "TV ini tidak menyediakan pilihan layar utama; atur lewat adb (lihat panduan)" to false
                        }
                        TombolTv("Izin matikan layar") {
                            if (!Remote.didukungDeviceAdmin(ctx)) {
                                pesan = "TV ini tidak mendukung Device admin. Matikan layar memakai layar hitam penuh." to false
                                return@TombolTv
                            }
                            val ok = runCatching {
                                (ctx.applicationContext as id.rentalps.tvagent.AgentApp).izinkanKeluar(60)
                                ctx.startActivity(
                                    Intent(android.app.admin.DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN)
                                        .putExtra(android.app.admin.DevicePolicyManager.EXTRA_DEVICE_ADMIN, Remote.admin(ctx))
                                        .putExtra(
                                            android.app.admin.DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                                            "Dipakai hanya untuk mematikan layar TV dari aplikasi kasir.",
                                        )
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                )
                            }.isSuccess
                            if (!ok) pesan = "Tidak tersedia; aktifkan lewat adb: dpm set-active-admin" to false
                        }
                        TombolTv("Kembali") { ke(Panel.Utama) }
                    }
                }

                Panel.GantiServer -> {
                    Text(
                        "TV akan lepas dari server ini dan harus dipasangkan ulang oleh admin.",
                        color = Warna.redup, fontSize = 18.sp, modifier = Modifier.padding(bottom = 18.dp),
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        TombolTv("Batal", focusRequester = fokusAwal) { ke(Panel.Utama) }
                        TombolTv("Ya, lepaskan", bahaya = true) { agent.lupakanServer(); onTutup() }
                    }
                }
            }

            pesan?.let { (teks, sukses) ->
                Spacer(Modifier.height(14.dp))
                Text(teks, color = if (sukses) Warna.hijau else Warna.merah, fontSize = 18.sp)
            }
            if (sibuk) {
                Spacer(Modifier.height(10.dp))
                Text("Memproses…", color = Warna.redup, fontSize = 16.sp)
            }
        }
    }

    // Fokus awal setelah tombol benar-benar tampil (coba beberapa kali)
    LaunchedEffect(panel) {
        repeat(5) {
            delay(80)
            if (runCatching { fokusAwal.requestFocus() }.isSuccess) return@LaunchedEffect
        }
    }
    }
}

@Composable
private fun InfoBaris(label: String, nilai: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(label, color = Warna.redup, fontSize = 15.sp, modifier = Modifier.width(190.dp))
        Text(nilai, color = Warna.teks, fontSize = 15.sp, fontFamily = FontFamily.Monospace)
    }
}
