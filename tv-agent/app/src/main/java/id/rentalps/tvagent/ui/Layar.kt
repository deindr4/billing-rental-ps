package id.rentalps.tvagent.ui

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.basicMarquee
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.rentalps.tvagent.core.Agent
import id.rentalps.tvagent.core.Jam
import id.rentalps.tvagent.core.Keadaan
import id.rentalps.tvagent.core.Tahap
import id.rentalps.tvagent.data.ItemTagihan
import id.rentalps.tvagent.data.StatusTv
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Detak 1 detik untuk timer & jam di layar (jam server) */
@Composable
fun detikSekarang(): Long {
    var sekarang by remember { mutableLongStateOf(Jam.sekarang()) }
    LaunchedEffect(Unit) {
        while (true) {
            sekarang = Jam.sekarang()
            delay(1_000 - (sekarang % 1_000))
        }
    }
    return sekarang
}

@Composable
fun Aplikasi(agent: Agent, keadaan: Keadaan, onBukaHdmi: () -> Unit) {
    var menuStaf by remember { mutableStateOf(false) }
    val aksen = warnaDariHex(keadaan.status?.tema?.aksen) ?: Warna.hijau

    CompositionLocalProvider(LocalAksen provides aksen) {
        Box(Modifier.fillMaxSize().background(Warna.latar)) {
            when (val tahap = keadaan.tahap) {
                Tahap.IsiServer -> LayarIsiServer(onSimpan = agent::setelServer)
                is Tahap.Pairing -> LayarPairing(tahap, agent.simpan.serverUrl, onGantiServer = agent::lupakanServer)
                Tahap.Aktif -> {
                    // Setup: TV punya beberapa HDMI tapi input PS belum ditentukan (hanya saat tidak ada sesi)
                    val perluSetupHdmi = remember(keadaan.status?.pengaturan?.inputHdmi) { agent.perluPilihHdmi() }
                    if (perluSetupHdmi && keadaan.layar in setOf("kunci", "belum_ada_unit", "servis")) {
                        LayarPilihHdmi(agent)
                    } else if (keadaan.layar == "habis" || keadaan.layar == "menunggu_bayar") {
                        LayarTagihan(agent, keadaan, onBukaMenu = { menuStaf = true })
                    } else {
                        LayarKunci(agent, keadaan, onBukaMenu = { menuStaf = true }, onBukaHdmi = onBukaHdmi)
                    }
                    if (menuStaf) {
                        MenuStaf(agent, keadaan, onTutup = { menuStaf = false })
                    }

                    // Tombol Home saat TV terkunci -> PIN staf untuk keluar sementara
                    val app = androidx.compose.ui.platform.LocalContext.current.applicationContext as id.rentalps.tvagent.AgentApp
                    val mintaPin by app.mintaPinKeluar.collectAsState()
                    if (mintaPin && !menuStaf && keadaan.layar in Agent.LAYAR_TERKUNCI) {
                        DialogPinKeluar(agent, onTutup = { app.mintaPinKeluar.value = false })
                    }
                }
            }
        }
    }
}

/* ================= Isi alamat server ================= */

@Composable
private fun LayarIsiServer(onSimpan: (String) -> Unit) {
    var alamat by remember { mutableStateOf("192.168.50.9") }
    val fokus = remember { FocusRequester() }
    val aksen = LocalAksen.current

    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
        modifier = Modifier.fillMaxSize().padding(48.dp),
    ) {
        Label("TV Agent · pengaturan awal", warna = aksen)
        Spacer(Modifier.height(8.dp))
        Text("Alamat server billing", color = Warna.teks, fontSize = 30.sp, fontWeight = FontWeight.Bold)
        Text("IP komputer kasir di jaringan yang sama", color = Warna.redup, fontSize = 16.sp)
        Spacer(Modifier.height(24.dp))

        OutlinedTextField(
            value = alamat,
            onValueChange = { alamat = it },
            singleLine = true,
            label = { Text("Alamat server") },
            placeholder = { Text("192.168.1.10") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { if (alamat.isNotBlank()) onSimpan(alamat) }),
            colors = OutlinedTextFieldDefaults.colors(
                focusedTextColor = Warna.teks, unfocusedTextColor = Warna.teks,
                focusedBorderColor = aksen, unfocusedBorderColor = Warna.garis,
                focusedLabelColor = aksen, unfocusedLabelColor = Warna.redup,
            ),
            textStyle = TextStyle(fontSize = 22.sp, fontFamily = FontFamily.Monospace),
            modifier = Modifier.width(480.dp),
        )
        Spacer(Modifier.height(18.dp))
        TombolTv("Sambungkan", utama = true, focusRequester = fokus) { if (alamat.isNotBlank()) onSimpan(alamat) }
    }

    LaunchedEffect(Unit) { runCatching { fokus.requestFocus() } }
}

/* ================= Pairing ================= */

@Composable
private fun LayarPairing(tahap: Tahap.Pairing, server: String?, onGantiServer: () -> Unit) {
    val sekarang = detikSekarang()
    val aksen = LocalAksen.current

    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
        modifier = Modifier.fillMaxSize().padding(40.dp),
    ) {
        Label("TV Agent · pairing", warna = aksen)
        Spacer(Modifier.height(8.dp))
        Text("Pasangkan TV ini", color = Warna.teks, fontSize = 32.sp, fontWeight = FontWeight.Bold)
        Text(
            "Admin → Rental → Perangkat TV → Pasangkan TV, lalu masukkan kode ini",
            color = Warna.redup, fontSize = 16.sp, textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(28.dp))

        if (tahap.kode != null) {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                tahap.kode.forEach { c ->
                    Box(
                        contentAlignment = Alignment.Center,
                        modifier = Modifier
                            .width(78.dp).height(100.dp)
                            .background(Warna.permukaan, RoundedCornerShape(8.dp))
                            .border(2.dp, aksen, RoundedCornerShape(8.dp)),
                    ) {
                        Angka(c.toString(), ukuran = 60.sp)
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
            // `sekarang` dibaca supaya hitung mundur tergambar ulang tiap detik
            val sisa = (tahap.sampaiMs - System.currentTimeMillis() + (sekarang - sekarang)) / 1000
            Label("Kode berganti dalam ${Jam.format(sisa).substring(3)}")
        } else {
            Text(tahap.pesan ?: "Meminta kode…", color = if (tahap.pesan != null) Warna.kuning else Warna.redup, fontSize = 18.sp)
        }

        Spacer(Modifier.height(32.dp))
        Label("Server: ${server ?: "-"}")
        Spacer(Modifier.height(10.dp))
        TombolTv("Ganti alamat server", kecil = true, onClick = onGantiServer)
    }
}

/* ================= Layar kunci (desain Stitch 10) ================= */

@Composable
private fun LayarKunci(agent: Agent, k: Keadaan, onBukaMenu: () -> Unit, onBukaHdmi: () -> Unit) {
    val s = k.status
    val aksen = LocalAksen.current
    val sekarang = detikSekarang()
    val fokusMenu = remember { FocusRequester() }
    val logo = gambarDariUrl(s?.tema?.logoUrl, agent.api.http)
    val terbuka = k.layar in Agent.LAYAR_TERBUKA

    val (teksStatus, warnaStatus) = when (k.layar) {
        "kunci" -> "Terkunci & siap main" to aksen
        "jeda" -> "Sesi dijeda" to Warna.jeda
        "servis" -> "Maintenance" to Warna.oranye
        "belum_ada_unit" -> "Menunggu penugasan unit" to Warna.redup
        "main" -> "Sedang bermain" to Warna.biru
        "bypass" -> "Bypass aktif" to Warna.biru
        "darurat" -> "Buka darurat" to Warna.kuning
        else -> "Memuat" to Warna.redup
    }

    val wallpaper = gambarDariUrl(s?.tema?.wallpaperUrl, agent.api.http)
    // Kegelapan lapisan di atas wallpaper (Admin → Tampilan), supaya teks tetap terbaca
    val gelap = ((s?.pengaturan?.transparansiLock ?: 70).coerceIn(0, 95)) / 100f

    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.radialGradient(listOf(aksen.copy(alpha = 0.13f), Warna.latar), radius = 1400f)),
    ) {
        if (wallpaper != null) {
            Image(wallpaper, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            Box(Modifier.fillMaxSize().background(Warna.latar.copy(alpha = gelap)))
            // Atas & bawah sedikit lebih gelap untuk bar status dan kartu
            Box(
                Modifier.fillMaxSize().background(
                    Brush.verticalGradient(
                        0f to Warna.latar.copy(alpha = 0.55f),
                        0.25f to Color.Transparent,
                        0.7f to Color.Transparent,
                        1f to Warna.latar.copy(alpha = 0.7f),
                    ),
                ),
            )
        }

        Column(Modifier.fillMaxSize().padding(horizontal = 36.dp, vertical = 24.dp)) {
            // Bar atas: stasiun + status | jam
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    modifier = Modifier
                        .background(Warna.permukaan.copy(alpha = 0.85f), RoundedCornerShape(8.dp))
                        .border(1.dp, Warna.garis, RoundedCornerShape(8.dp))
                        .padding(horizontal = 14.dp, vertical = 9.dp),
                ) {
                    Text("STASIUN ${s?.unit?.kode ?: "-"}", color = Warna.teks, fontSize = 16.sp, fontWeight = FontWeight.Bold, fontFamily = FontFamily.Monospace)
                    Titik(warnaStatus)
                    Label(teksStatus, warna = warnaStatus)
                }
                Spacer(Modifier.weight(1f))
                Angka(jam(sekarang, s?.tema?.zonaWaktu), ukuran = 26.sp)
                Spacer(Modifier.width(6.dp))
                Label(s?.tema?.zonaLabel ?: "")
            }

            // Tengah
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
                modifier = Modifier.weight(1f).fillMaxWidth(),
            ) {
                if (logo != null && k.layar == "kunci") {
                    Image(logo, contentDescription = "Logo", contentScale = ContentScale.Fit, modifier = Modifier.height(90.dp))
                    Spacer(Modifier.height(14.dp))
                }

                val judul = when (k.layar) {
                    "kunci" -> "READY TO PLAY"
                    "jeda" -> "SESI DIJEDA"
                    "servis" -> "DALAM PERBAIKAN"
                    "belum_ada_unit" -> "TV TERDAFTAR"
                    "bypass" -> "BYPASS AKTIF"
                    "main", "darurat" -> "SELAMAT BERMAIN"
                    else -> "MEMUAT…"
                }
                Text(
                    judul,
                    fontSize = 60.sp,
                    fontWeight = FontWeight.Black,
                    letterSpacing = 3.sp,
                    style = TextStyle(brush = Brush.horizontalGradient(listOf(aksen, Warna.biru))),
                    textAlign = TextAlign.Center,
                )

                val sesi = s?.sesi
                when (k.layar) {
                    "jeda" -> if (sesi?.berakhirMs != null) {
                        Angka(Jam.format((sesi.berakhirMs - (sesi.dijedaMs ?: sekarang)) / 1000), Warna.jeda, 52.sp)
                        Label("Sisa waktu berhenti selama dijeda")
                    }
                    "main" -> if (sesi?.mode == "paket" && sesi.berakhirMs != null) {
                        Angka(Jam.format((sesi.berakhirMs - sekarang) / 1000), ukuran = 52.sp)
                    } else if (sesi != null) {
                        Angka(Jam.format((sekarang - sesi.mulaiMs) / 1000 - sesi.totalJedaDetik), ukuran = 52.sp)
                    }
                    "bypass" -> Angka(Jam.format(((s?.perangkat?.bypassSampaiMs ?: sekarang) - sekarang) / 1000), Warna.biru, 52.sp)
                    "darurat" -> Angka(Jam.format((k.daruratSampaiMs - sekarang) / 1000), Warna.kuning, 52.sp)
                    else -> Text(
                        listOfNotNull(s?.unit?.nama, s?.unit?.konsol).joinToString(" · "),
                        color = Warna.redup, fontSize = 18.sp,
                    )
                }

                if (k.layar == "bypass") {
                    Spacer(Modifier.height(18.dp))
                    PilihanBypass(agent, k, onBukaHdmi)
                } else if (terbuka && k.hdmiGagal) {
                    // Pindah HDMI gagal: jangan tampilkan aplikasi lain, minta pilih input PS
                    Spacer(Modifier.height(14.dp))
                    Text("Gagal pindah ke HDMI otomatis", color = Warna.kuning, fontSize = 16.sp)
                    Spacer(Modifier.height(10.dp))
                    PilihanInputCepat(agent)
                } else if (terbuka) {
                    Spacer(Modifier.height(16.dp))
                    TombolTv("Buka HDMI (PS)", utama = true, onClick = onBukaHdmi)
                }

                Spacer(Modifier.height(18.dp))
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    modifier = Modifier
                        .background(Warna.permukaan.copy(alpha = 0.85f), RoundedCornerShape(20.dp))
                        .border(1.dp, Warna.garis, RoundedCornerShape(20.dp))
                        .padding(horizontal = 14.dp, vertical = 6.dp),
                ) {
                    Titik(aksen, 6)
                    Text(
                        listOfNotNull(s?.tema?.namaRental, s?.tema?.cabang).joinToString(" · "),
                        color = Warna.teks, fontSize = 13.sp,
                    )
                }
            }

            // Bawah: info unit + koneksi | kartu ajakan
            Row(verticalAlignment = Alignment.Bottom, modifier = Modifier.fillMaxWidth()) {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        s?.unit?.konsol?.let { Chip(it) }
                        s?.unit?.kategori?.let { Chip(it) }
                        s?.unit?.lokasi?.let { Chip(it) }
                    }
                    BarKoneksi(k, fokusMenu, onBukaMenu)
                }
                Spacer(Modifier.weight(1f))
                KartuAjakan(k)
            }

            Pengumuman(s)
        }
    }

    // Layar bypass memfokuskan pilihan tujuan sendiri
    LaunchedEffect(k.layar) { if (k.layar != "bypass") runCatching { fokusMenu.requestFocus() } }
}

/** Bypass (owner): pilih mau ke PS, YouTube, atau aplikasi lain yang diizinkan admin */
@Composable
private fun PilihanBypass(agent: Agent, k: Keadaan, onBukaHdmi: () -> Unit) {
    val ctx = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    val fokus = remember { FocusRequester() }
    var pesan by remember { mutableStateOf<String?>(null) }
    val aplikasi = k.status?.pengaturan?.aplikasi.orEmpty()

    Label("Pilih tujuan")
    Spacer(Modifier.height(8.dp))
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
        TombolTv("PlayStation (HDMI)", utama = true, focusRequester = fokus, onClick = onBukaHdmi)
        aplikasi.forEach { app ->
            TombolTv(app.nama) {
                pesan = if (id.rentalps.tvagent.core.AplikasiTv.buka(ctx, app.paket)) null
                else "${app.nama} tidak terpasang di TV ini"
            }
        }
        TombolTv("Akhiri bypass", bahaya = true) {
            scope.launch { pesan = agent.akhiriBypass() }
        }
    }
    pesan?.let {
        Spacer(Modifier.height(8.dp))
        Text(it, color = Warna.kuning, fontSize = 13.sp)
    }

    // Pindah ke HDMI gagal: beri tahu & tawarkan pilihan input (sebelumnya diam saja)
    if (k.hdmiGagal) {
        Spacer(Modifier.height(10.dp))
        Text("Gagal pindah ke HDMI otomatis", color = Warna.kuning, fontSize = 14.sp)
        Spacer(Modifier.height(8.dp))
        PilihanInputCepat(agent)
    }

    LaunchedEffect(Unit) { runCatching { fokus.requestFocus() } }
}

@Composable
private fun KartuAjakan(k: Keadaan) {
    val aksen = LocalAksen.current
    val s = k.status

    val (label, judul, isi) = when (k.layar) {
        "kunci" -> Triple("Mulai bermain", "Silakan ke kasir", "Bayar tunai, QRIS, atau transfer di kasir. TV terbuka otomatis saat sesi dimulai.")
        "jeda" -> Triple("Sesi dijeda", "Waktu Anda berhenti", "Minta kasir melanjutkan sesi saat siap bermain lagi.")
        "servis" -> Triple("Maintenance", "Unit sedang diperbaiki", "Silakan pilih unit lain di kasir.")
        "belum_ada_unit" -> Triple("Admin", "Pilih unit untuk TV ini", "Admin → Rental → Perangkat TV.")
        else -> return
    }

    Panel(modifier = Modifier.width(340.dp)) {
        Label(label, warna = aksen)
        Spacer(Modifier.height(4.dp))
        Text(judul, color = Warna.teks, fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(4.dp))
        Text(isi, color = Warna.redup, fontSize = 12.sp)
        val tarif = s?.unit?.tarifPerJam
        if (k.layar == "kunci" && tarif != null) {
            Spacer(Modifier.height(10.dp))
            Box(
                Modifier
                    .fillMaxWidth()
                    .background(Warna.permukaan2, RoundedCornerShape(6.dp))
                    .padding(horizontal = 10.dp, vertical = 6.dp),
            ) {
                Label("Mulai ${rupiah(tarif)} / jam", warna = aksen, ukuran = 12.sp)
            }
        }
    }
}

/* ================= Waktu habis / menunggu bayar (desain Stitch 11) ================= */

@Composable
private fun LayarTagihan(agent: Agent, k: Keadaan, onBukaMenu: () -> Unit) {
    val s = k.status ?: return
    val sesi = s.sesi
    val tagihan = sesi?.tagihan
    val aksen = LocalAksen.current
    val sekarang = detikSekarang()
    val fokus = remember { FocusRequester() }
    val scope = rememberCoroutineScope()
    var pesanPanggil by remember { mutableStateOf<String?>(null) }
    val habis = k.layar == "habis"

    Column(
        verticalArrangement = Arrangement.spacedBy(12.dp),
        modifier = Modifier.fillMaxSize().padding(horizontal = 24.dp, vertical = 16.dp),
    ) {
        // Kepala stasiun
        Panel(modifier = Modifier.fillMaxWidth()) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("STASIUN ${s.unit?.kode ?: "-"}", color = Warna.teks, fontSize = 18.sp, fontWeight = FontWeight.Bold, fontFamily = FontFamily.Monospace)
                        s.unit?.kategori?.let { Chip(it, warna = Warna.kuning, garis = Warna.kuning.copy(alpha = 0.5f)) }
                        s.unit?.konsol?.let { Chip(it) }
                    }
                    Spacer(Modifier.height(4.dp))
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Titik(Warna.merah, 7)
                        Label("Konsol terkunci · HDMI standby", warna = Warna.merah)
                        Label("• ${listOfNotNull(s.tema.namaRental, s.tema.cabang).joinToString(" · ")}")
                    }
                }
                Column(
                    horizontalAlignment = Alignment.End,
                    modifier = Modifier
                        .background(Warna.permukaan2, RoundedCornerShape(6.dp))
                        .border(1.dp, Warna.garis, RoundedCornerShape(6.dp))
                        .padding(horizontal = 12.dp, vertical = 6.dp),
                ) {
                    Label("Waktu lokal")
                    Row(verticalAlignment = Alignment.Bottom) {
                        Angka(jam(sekarang, s.tema.zonaWaktu, "HH:mm:ss"), ukuran = 22.sp)
                        Spacer(Modifier.width(6.dp))
                        Label(s.tema.zonaLabel ?: "")
                    }
                }
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.weight(1f)) {
            // Kiri: lockdown + rincian tagihan
            Column(verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.weight(1.55f)) {
                Panel(modifier = Modifier.fillMaxWidth(), garis = Warna.merah.copy(alpha = 0.35f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Titik(Warna.merah)
                        Spacer(Modifier.width(8.dp))
                        Label("Billing lockdown aktif", warna = Warna.teks)
                        Spacer(Modifier.weight(1f))
                        Chip("Konsol terkunci otomatis", warna = Warna.merah, latar = Warna.merah.copy(alpha = 0.12f), garis = Warna.merah.copy(alpha = 0.6f))
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                if (habis) "WAKTU ANDA TELAH HABIS" else "SESI SELESAI",
                                color = Warna.teks, fontSize = 24.sp, fontWeight = FontWeight.Bold,
                            )
                            Spacer(Modifier.height(4.dp))
                            Text(
                                if (habis) "Layar dialihkan ke standby. Silakan tambah waktu atau selesaikan pembayaran di kasir."
                                else "Terima kasih sudah bermain. Silakan selesaikan pembayaran di kasir.",
                                color = Warna.redup, fontSize = 13.sp,
                            )
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(
                            horizontalAlignment = Alignment.CenterHorizontally,
                            modifier = Modifier
                                .background(Warna.latar, RoundedCornerShape(8.dp))
                                .border(1.dp, Warna.garis, RoundedCornerShape(8.dp))
                                .padding(horizontal = 14.dp, vertical = 8.dp),
                        ) {
                            Label("Sisa durasi main", ukuran = 10.sp)
                            Angka("00:00:00", Warna.kuning, 28.sp)
                            val lewat = when {
                                habis && sesi?.berakhirMs != null -> "Expired +" + Jam.format((sekarang - sesi.berakhirMs) / 1000).substring(3)
                                sesi?.selesaiMs != null -> "Selesai " + jam(sesi.selesaiMs, s.tema.zonaWaktu)
                                else -> ""
                            }
                            Label(lewat, warna = Warna.kuning, ukuran = 10.sp)
                        }
                    }
                }

                RincianTagihan(s, modifier = Modifier.weight(1f).fillMaxWidth())
            }

            // Kanan: pembayaran + bantuan
            Column(verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.weight(1f)) {
                Panel(modifier = Modifier.fillMaxWidth().weight(1f)) {
                    Box(Modifier.background(aksen, RoundedCornerShape(3.dp)).padding(horizontal = 6.dp, vertical = 2.dp)) {
                        Label("Pembayaran", warna = teksDiAtas(aksen), ukuran = 10.sp)
                    }
                    Spacer(Modifier.height(10.dp))
                    Text("Selesaikan di kasir", color = Warna.teks, fontSize = 20.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "TV & konsol terbuka lagi otomatis setelah kasir menambah waktu atau pembayaran tercatat.",
                        color = Warna.redup, fontSize = 13.sp,
                    )
                    Spacer(Modifier.height(12.dp))
                    Label("Metode pembayaran")
                    Spacer(Modifier.height(6.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Chip("Tunai", warna = Warna.teks)
                        Chip("QRIS", warna = Warna.teks)
                        Chip("Transfer", warna = Warna.teks)
                    }
                    Spacer(Modifier.weight(1f))
                    if (tagihan != null) {
                        Label("Total tagihan", ukuran = 10.sp)
                        Angka(rupiah(if (tagihan.lunas) tagihan.total else tagihan.sisa), aksen, 30.sp)
                        tagihan.nomor?.let { Label(it, ukuran = 10.sp) }
                    }
                }

                Panel(modifier = Modifier.fillMaxWidth()) {
                    Label("Butuh bantuan?")
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        TombolTv("Panggil Kasir", utama = true, focusRequester = fokus, modifier = Modifier.weight(1f)) {
                            scope.launch { pesanPanggil = agent.panggilKasir() }
                        }
                        TombolTv("Menu staf", kecil = true, onClick = onBukaMenu)
                    }
                    pesanPanggil?.let {
                        Spacer(Modifier.height(6.dp))
                        Text(it, color = aksen, fontSize = 12.sp)
                    }
                }
            }
        }

        Pengumuman(s, koneksi = k)
    }

    LaunchedEffect(k.layar) { runCatching { fokus.requestFocus() } }
}

@Composable
private fun RincianTagihan(s: StatusTv, modifier: Modifier) {
    val tagihan = s.sesi?.tagihan
    val aksen = LocalAksen.current
    val items = tagihan?.items.orEmpty()
    val maks = 5

    Panel(modifier = modifier) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Rincian Tagihan Sesi", color = Warna.teks, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.weight(1f))
            Label("Klien: ", ukuran = 10.sp)
            Text(tagihan?.pelanggan ?: "Tamu", color = Warna.teks, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.height(8.dp))

        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
            Label("Deskripsi layanan / item", ukuran = 10.sp, modifier = Modifier.weight(3f))
            Label("Qty", ukuran = 10.sp, modifier = Modifier.weight(0.8f))
            Label("Subtotal", ukuran = 10.sp, modifier = Modifier.weight(1.2f))
        }
        Box(Modifier.fillMaxWidth().height(1.dp).background(Warna.garis))

        Column(Modifier.weight(1f)) {
            items.take(maks).forEach { BarisItem(it) }
            if (items.size > maks) {
                Label("+ ${items.size - maks} item lainnya", modifier = Modifier.padding(top = 4.dp))
            }
            if (items.isEmpty()) {
                Text("Belum ada item.", color = Warna.redup, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
            }
        }

        if (tagihan != null) {
            if (tagihan.totalDiskon > 0) {
                BarisRingkas("Subtotal pemakaian & F&B", rupiah(tagihan.subtotal))
                tagihan.diskon.forEach { BarisRingkas(it.nama, rupiah(-it.nilai), aksen) }
            }
            Spacer(Modifier.height(6.dp))
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Warna.latar, RoundedCornerShape(6.dp))
                    .border(1.dp, Warna.garis, RoundedCornerShape(6.dp))
                    .padding(horizontal = 12.dp, vertical = 8.dp),
            ) {
                Column(Modifier.weight(1f)) {
                    Label(if (tagihan.lunas) "Total tagihan · lunas" else "Total tagihan belum dibayar", ukuran = 10.sp, warna = Warna.teks)
                    if (!tagihan.lunas && tagihan.sisa < tagihan.total) {
                        Text("Sudah dibayar ${rupiah(tagihan.total - tagihan.sisa)}", color = Warna.kuning, fontSize = 11.sp)
                    }
                }
                Angka(rupiah(if (tagihan.lunas) tagihan.total else tagihan.sisa), aksen, 26.sp)
            }
        }
    }
}

@Composable
private fun BarisItem(item: ItemTagihan) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Column(Modifier.weight(3f)) {
            Text(item.nama, color = Warna.teks, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            item.keterangan?.let {
                Text(it.replace(" | ", " • "), color = Warna.redup, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        Text(
            if (item.jenis == "produk") "${item.qty}x" else "1x",
            color = Warna.redup, fontSize = 13.sp, fontFamily = FontFamily.Monospace, modifier = Modifier.weight(0.8f),
        )
        Text(
            rupiah(item.subtotal), color = Warna.teks, fontSize = 13.sp, fontFamily = FontFamily.Monospace,
            fontWeight = FontWeight.SemiBold, textAlign = TextAlign.End, modifier = Modifier.weight(1.2f),
        )
    }
}

@Composable
private fun BarisRingkas(label: String, nilai: String, warna: Color = Warna.redup) {
    Row(Modifier.fillMaxWidth().padding(vertical = 1.dp)) {
        Text(label, color = warna, fontSize = 12.sp, modifier = Modifier.weight(1f))
        Text(nilai, color = warna, fontSize = 12.sp, fontFamily = FontFamily.Monospace)
    }
}

/* ================= Bagian bersama ================= */

@Composable
private fun BarKoneksi(k: Keadaan, fokusMenu: FocusRequester, onBukaMenu: () -> Unit) {
    val (teks, warna) = when {
        k.offline -> "Offline · ${k.pesan ?: "server tidak terjangkau"}" to Warna.kuning
        // Sedang memakai server cadangan (lokal mati)
        k.server == "cloud" -> "Online · server cloud (lokal tidak terjangkau)" to Warna.biru
        k.realtime -> "Online · lokal · realtime" to Warna.hijau
        else -> "Online · lokal" to Warna.hijau
    }
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Titik(warna, 7)
        Text(
            teks.uppercase(), color = Warna.redup, fontSize = 10.sp, fontFamily = FontFamily.Monospace,
            letterSpacing = 1.4.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            modifier = Modifier.width(320.dp),
        )
        TombolTv("Menu staf", kecil = true, focusRequester = fokusMenu, onClick = onBukaMenu)
    }
}

/** Kaki layar: pengumuman berjalan (Admin → Operasional) + status koneksi */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Pengumuman(s: StatusTv?, koneksi: Keadaan? = null) {
    val teks = s?.pengumuman
    if (teks.isNullOrBlank() && koneksi == null) return

    val aksen = LocalAksen.current
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = 10.dp)
            .heightIn(min = 30.dp)
            .background(Warna.permukaan, RoundedCornerShape(6.dp))
            .border(1.dp, Warna.garis, RoundedCornerShape(6.dp))
            .padding(horizontal = 12.dp, vertical = 6.dp),
    ) {
        if (!teks.isNullOrBlank()) {
            Label("Info", warna = aksen)
            Spacer(Modifier.width(12.dp))
            // Teks diulang sampai lebih panjang dari layar: marquee hanya bergerak jika teks meluap,
            // jadi pengumuman pendek pun tetap berjalan terus tanpa jeda.
            val berjalan = remember(teks) {
                val satu = teks.replace('\n', ' ').trim() + "     •     "
                satu.repeat(maxOf(2, 240 / satu.length + 1))
            }
            Text(
                berjalan,
                color = Warna.teks, fontSize = 13.sp, fontFamily = FontFamily.Monospace, maxLines = 1,
                modifier = Modifier
                    .weight(1f)
                    .basicMarquee(
                        iterations = Int.MAX_VALUE,
                        initialDelayMillis = 0,
                        repeatDelayMillis = 0,
                        velocity = 70.dp,
                    ),
            )
        } else {
            Spacer(Modifier.weight(1f))
        }
        koneksi?.let {
            Spacer(Modifier.width(12.dp))
            Titik(if (it.offline) Warna.kuning else if (it.server == "cloud") Warna.biru else Warna.hijau, 6)
            Spacer(Modifier.width(6.dp))
            Label(if (it.offline) "Offline" else "Online · ${it.server}", ukuran = 10.sp)
        }
    }
}
