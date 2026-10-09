// Delta Billing HuB Monitor — status & perbaikan cepat server billing di PC rental (Windows).
// Dipasang installer di <folder>\kelola\BillingPS-Monitor.exe, berjalan sebagai Administrator.
//  - Status layanan Windows (MariaDB, Apache+PHP, Realtime, Antrean, Jadwal, WhatsApp, Tunnel) + nyalakan/hentikan/restart
//  - Pemeriksaan: query database, PHP CLI, halaman web (/up), realtime TV
//  - Status port (web, database, realtime, WhatsApp) + proses yang memakainya
//  - Backup database TANPA aplikasi web: mariadb-dump -> zip format backup aplikasi (bisa dipulihkan dari
//    Admin → Backup atau installer "Pulihkan dari file backup")
// Dikompilasi installer\build.ps1 dengan csc .NET Framework 4 (C# 5: tanpa $"", ?. , nameof).
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.ServiceProcess;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Web.Script.Serialization;
using System.Windows.Forms;

namespace BillingPS.Monitor
{
    internal static class Program
    {
        [STAThread]
        private static void Main(string[] args)
        {
            // Uji / pemakaian manual: BillingPS-Monitor.exe --konversi <dump.sql> <database.sql>
            if (args.Length == 3 && args[0] == "--konversi")
            {
                using (var tulis = new StreamWriter(args[2], false, new UTF8Encoding(false)))
                    Jendela.TulisPernyataan(args[1], tulis);
                return;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new Jendela());
        }
    }

    internal sealed class Layanan
    {
        public string Nama;
        public string Judul;
        public Layanan(string nama, string judul) { Nama = nama; Judul = judul; }
    }

    internal sealed class Konfig
    {
        public string Root, App, Runtime, Data, Logs, Kelola;
        public int PortWeb = 80, PortDb = 3306, PortWs = 8080, PortWa;
        public string DbRoot = "";
        public bool Ada;

        public static Konfig Muat()
        {
            var k = new Konfig();
            string exe = Path.GetDirectoryName(Application.ExecutablePath);
            // exe di <root>\kelola; saat diuji di luar folder pemasangan pakai C:\BillingPS
            k.Root = string.Equals(Path.GetFileName(exe), "kelola", StringComparison.OrdinalIgnoreCase)
                ? Path.GetDirectoryName(exe) : @"C:\BillingPS";
            k.App = Path.Combine(k.Root, "app");
            k.Runtime = Path.Combine(k.Root, "runtime");
            k.Data = Path.Combine(k.Root, "data");
            k.Logs = Path.Combine(k.Root, "logs");
            k.Kelola = Path.Combine(k.Root, "kelola");

            string file = Path.Combine(k.Kelola, "konfigurasi.json");
            if (File.Exists(file))
            {
                try
                {
                    var json = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(File.ReadAllText(file));
                    k.PortWeb = Angka(json, "port_web", 80);
                    k.PortDb = Angka(json, "port_db", 3306);
                    k.PortWs = Angka(json, "port_ws", 8080);
                    k.DbRoot = json.ContainsKey("db_root") ? Convert.ToString(json["db_root"]) : "";
                    k.Ada = true;
                }
                catch { k.Ada = false; }
            }

            // Port WhatsApp dari .env (WA_SERVICE_URL=http://127.0.0.1:3001)
            string env = Path.Combine(k.App, ".env");
            if (File.Exists(env))
            {
                var m = Regex.Match(File.ReadAllText(env), @"WA_SERVICE_URL\s*=\s*""?https?://[^:\s""]+:(\d+)");
                if (m.Success) k.PortWa = int.Parse(m.Groups[1].Value);
            }
            return k;
        }

        private static int Angka(Dictionary<string, object> j, string kunci, int bawaan)
        {
            object v;
            return j.TryGetValue(kunci, out v) && v != null ? Convert.ToInt32(v) : bawaan;
        }

        public string Alamat
        {
            get { return "http://localhost" + (PortWeb == 80 ? "" : ":" + PortWeb); }
        }
    }

    internal sealed class Jendela : Form
    {
        private static readonly Layanan[] DaftarLayanan =
        {
            new Layanan("BillingPS-Database", "Database (MariaDB / MySQL)"),
            new Layanan("BillingPS-Web", "Web (Apache + PHP)"),
            new Layanan("BillingPS-Realtime", "Realtime TV (Reverb)"),
            new Layanan("BillingPS-Antrean", "Antrean tugas"),
            new Layanan("BillingPS-Jadwal", "Jadwal (QRIS, sinkron, backup harian)"),
            new Layanan("BillingPS-WhatsApp", "WhatsApp (laporan & notifikasi)"),
            new Layanan("BillingPS-Tunnel", "Cloudflare Tunnel (akses internet)"),
        };

        private static readonly Color Hijau = Color.FromArgb(22, 163, 74);
        private static readonly Color Merah = Color.FromArgb(220, 38, 38);
        private static readonly Color Kuning = Color.FromArgb(202, 138, 4);
        private static readonly Color Abu = Color.FromArgb(107, 114, 128);

        private readonly Konfig _k;
        private readonly ListView _lvLayanan, _lvCek, _lvPort;
        private readonly TextBox _log;
        private readonly Label _lblWaktu;
        private readonly CheckBox _cbOtomatis, _cbFoto;
        private readonly Timer _timer;
        private readonly List<Control> _tombolAksi = new List<Control>();
        private bool _sibuk, _memeriksa;

        public Jendela()
        {
            _k = Konfig.Muat();
            Text = "Delta Billing HuB Monitor";
            Font = new Font("Segoe UI", 9.5f);
            Width = 1000;
            Height = 760;
            MinimumSize = new Size(860, 620);
            StartPosition = FormStartPosition.CenterScreen;
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            // ---------- Kepala ----------
            var kepala = new Panel { Dock = DockStyle.Top, Height = 64, Padding = new Padding(12, 8, 12, 8), BackColor = Color.FromArgb(15, 28, 43) };
            var judul = new Label { Text = "Delta Billing HuB Monitor", ForeColor = Color.White, Font = new Font("Segoe UI Semibold", 14f), AutoSize = true, Location = new Point(12, 8) };
            var sub = new Label
            {
                Text = _k.Ada ? ("Folder " + _k.Root + "  ·  web :" + _k.PortWeb + "  ·  database :" + _k.PortDb + "  ·  realtime :" + _k.PortWs)
                              : ("konfigurasi.json tidak ditemukan di " + _k.Kelola + " — jalankan dari folder pemasangan."),
                ForeColor = Color.FromArgb(148, 163, 184), AutoSize = true, Location = new Point(14, 38)
            };
            kepala.Controls.Add(judul);
            kepala.Controls.Add(sub);

            // ---------- Bar tombol ----------
            var bar = new FlowLayoutPanel { Dock = DockStyle.Top, Height = 44, Padding = new Padding(8, 6, 8, 0), WrapContents = false };
            bar.Controls.Add(Tombol("Segarkan", (s, e) => Periksa()));
            bar.Controls.Add(Tombol("Nyalakan semua", (s, e) => Jalankan("Menyalakan semua layanan", NyalakanSemua), true));
            bar.Controls.Add(Tombol("Restart semua", (s, e) => Jalankan("Restart semua layanan", RestartSemua), true));
            bar.Controls.Add(Tombol("Buka Billing", (s, e) => Buka(_k.Alamat)));
            bar.Controls.Add(Tombol("Folder log", (s, e) => Buka(_k.Logs)));
            _cbOtomatis = new CheckBox { Text = "Segarkan otomatis (10 dtk)", Checked = true, AutoSize = true, Margin = new Padding(12, 8, 0, 0) };
            bar.Controls.Add(_cbOtomatis);
            _lblWaktu = new Label { AutoSize = true, ForeColor = Abu, Margin = new Padding(12, 9, 0, 0) };
            bar.Controls.Add(_lblWaktu);

            // ---------- Isi ----------
            var isi = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 3, Padding = new Padding(8) };
            isi.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 58));
            isi.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 42));
            isi.RowStyles.Add(new RowStyle(SizeType.Percent, 44));
            isi.RowStyles.Add(new RowStyle(SizeType.Percent, 30));
            isi.RowStyles.Add(new RowStyle(SizeType.Percent, 26));

            _lvLayanan = Daftar(new[] { "Layanan", "Status", "Mulai otomatis" }, new[] { 270, 110, 120 });
            var gbLayanan = Grup("Layanan Windows", _lvLayanan);
            var barLayanan = new FlowLayoutPanel { Dock = DockStyle.Bottom, Height = 38, WrapContents = false };
            barLayanan.Controls.Add(Tombol("Nyalakan", (s, e) => AksiTerpilih("nyalakan"), true));
            barLayanan.Controls.Add(Tombol("Hentikan", (s, e) => AksiTerpilih("hentikan"), true));
            barLayanan.Controls.Add(Tombol("Restart", (s, e) => AksiTerpilih("restart"), true));
            gbLayanan.Controls.Add(barLayanan);
            isi.Controls.Add(gbLayanan, 0, 0);

            _lvCek = Daftar(new[] { "Pemeriksaan", "Hasil", "Keterangan" }, new[] { 95, 55, 420 });
            _lvCek.ShowItemToolTips = true;
            isi.Controls.Add(Grup("Kesehatan (MySQL, PHP, Apache)", _lvCek), 1, 0);

            _lvPort = Daftar(new[] { "Port", "Untuk", "Status", "Dipakai oleh" }, new[] { 70, 170, 110, 200 });
            isi.Controls.Add(Grup("Port", _lvPort), 0, 1);

            // Backup
            var pnlBackup = new Panel { Dock = DockStyle.Fill, Padding = new Padding(8) };
            var ket = new Label
            {
                Text = "Backup database langsung dari MariaDB, tetap bisa walau halaman billing tidak bisa dibuka. " +
                       "Hasilnya .zip yang sama dengan backup aplikasi (pulihkan lewat Admin → Backup atau installer).",
                Dock = DockStyle.Top, Height = 80
            };
            _cbFoto = new CheckBox { Text = "Sertakan foto / logo unggahan", Checked = true, Dock = DockStyle.Top, Height = 26 };
            var barBackup = new FlowLayoutPanel { Dock = DockStyle.Top, Height = 40 };
            var btnBackup = Tombol("Backup database sekarang", (s, e) => Jalankan("Backup database", Backup), true);
            btnBackup.BackColor = Color.FromArgb(14, 165, 233);
            btnBackup.ForeColor = Color.White;
            barBackup.Controls.Add(btnBackup);
            barBackup.Controls.Add(Tombol("Buka folder backup", (s, e) => Buka(FolderBackup())));
            pnlBackup.Controls.Add(barBackup);
            pnlBackup.Controls.Add(_cbFoto);
            pnlBackup.Controls.Add(ket);
            isi.Controls.Add(Grup("Backup database", pnlBackup), 1, 1);

            _log = new TextBox { Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, Dock = DockStyle.Fill, Font = new Font("Consolas", 9f), BackColor = Color.White };
            var gbLog = Grup("Catatan", _log);
            isi.Controls.Add(gbLog, 0, 2);
            isi.SetColumnSpan(gbLog, 2);

            Controls.Add(isi);
            Controls.Add(bar);
            Controls.Add(kepala);

            _timer = new Timer { Interval = 10000 };
            _timer.Tick += (s, e) => { if (_cbOtomatis.Checked && !_sibuk) Periksa(); };
            Shown += (s, e) => { Catat("Monitor dibuka. Folder pemasangan: " + _k.Root); Periksa(); _timer.Start(); };
        }

        // ================= UI kecil =================
        private Button Tombol(string teks, EventHandler klik, bool aksi = false)
        {
            var b = new Button { Text = teks, AutoSize = true, Height = 30, Margin = new Padding(4, 2, 4, 2), Padding = new Padding(6, 0, 6, 0) };
            b.Click += klik;
            if (aksi) _tombolAksi.Add(b);
            return b;
        }

        private static ListView Daftar(string[] kolom, int[] lebar)
        {
            var lv = new ListView { View = View.Details, FullRowSelect = true, MultiSelect = false, Dock = DockStyle.Fill, HideSelection = false };
            for (int i = 0; i < kolom.Length; i++) lv.Columns.Add(kolom[i], lebar[i]);
            return lv;
        }

        private static GroupBox Grup(string judul, Control isi)
        {
            var g = new GroupBox { Text = judul, Dock = DockStyle.Fill, Padding = new Padding(6) };
            g.Controls.Add(isi);
            return g;
        }

        private void Catat(string teks)
        {
            if (InvokeRequired) { BeginInvoke(new Action<string>(Catat), teks); return; }
            _log.AppendText("[" + DateTime.Now.ToString("HH:mm:ss") + "] " + teks + Environment.NewLine);
        }

        private void Buka(string target)
        {
            try { Process.Start(new ProcessStartInfo(target) { UseShellExecute = true }); }
            catch (Exception ex) { Catat("Tidak bisa membuka " + target + ": " + ex.Message); }
        }

        // ================= Pemeriksaan =================
        private async void Periksa()
        {
            if (_memeriksa) return;
            _memeriksa = true;
            try
            {
                var layanan = await Task.Run(() => BacaLayanan());
                var port = await Task.Run(() => BacaPort());
                var cek = await Task.Run(() => BacaKesehatan());
                Isi(_lvLayanan, layanan);
                Isi(_lvPort, port);
                Isi(_lvCek, cek);
                _lblWaktu.Text = "Diperbarui " + DateTime.Now.ToString("HH:mm:ss");
            }
            catch (Exception ex) { Catat("Pemeriksaan gagal: " + ex.Message); }
            finally { _memeriksa = false; }
        }

        private static void Isi(ListView lv, List<ListViewItem> baris)
        {
            int pilih = lv.SelectedIndices.Count > 0 ? lv.SelectedIndices[0] : -1;
            lv.BeginUpdate();
            lv.Items.Clear();
            lv.Items.AddRange(baris.ToArray());
            if (pilih >= 0 && pilih < lv.Items.Count) lv.Items[pilih].Selected = true;
            lv.EndUpdate();
        }

        private static ListViewItem Baris(Color warna, params string[] sel)
        {
            var item = new ListViewItem(sel) { UseItemStyleForSubItems = false, ToolTipText = string.Join(" · ", sel) };
            item.SubItems[1].ForeColor = warna;
            item.SubItems[1].Font = new Font("Segoe UI Semibold", 9.5f);
            return item;
        }

        private List<ListViewItem> BacaLayanan()
        {
            var hasil = new List<ListViewItem>();
            foreach (var l in DaftarLayanan)
            {
                string status, mulai = "-";
                Color warna;
                try
                {
                    using (var sc = new ServiceController(l.Nama))
                    {
                        var st = sc.Status;
                        status = TeksStatus(st);
                        warna = st == ServiceControllerStatus.Running ? Hijau : (st == ServiceControllerStatus.Stopped ? Merah : Kuning);
                        mulai = sc.StartType == ServiceStartMode.Automatic ? "Otomatis" : (sc.StartType == ServiceStartMode.Disabled ? "Nonaktif" : "Manual");
                        if (l.Nama == "BillingPS-Tunnel" && st == ServiceControllerStatus.Stopped && !AdaTokenTunnel()) { status = "Belum diaktifkan"; warna = Abu; }
                    }
                }
                catch (InvalidOperationException) { status = "Tidak terpasang"; warna = Abu; }
                var item = Baris(warna, l.Judul, status, mulai);
                item.Tag = l.Nama;
                hasil.Add(item);
            }
            return hasil;
        }

        private static string TeksStatus(ServiceControllerStatus st)
        {
            switch (st)
            {
                case ServiceControllerStatus.Running: return "Berjalan";
                case ServiceControllerStatus.Stopped: return "Berhenti";
                case ServiceControllerStatus.StartPending: return "Menyala…";
                case ServiceControllerStatus.StopPending: return "Berhenti…";
                case ServiceControllerStatus.Paused: return "Dijeda";
                default: return st.ToString();
            }
        }

        private bool AdaTokenTunnel()
        {
            string f = Path.Combine(_k.Data, @"cloudflared\token.txt");
            return File.Exists(f) && new FileInfo(f).Length > 0;
        }

        private List<ListViewItem> BacaPort()
        {
            var pemilik = PemilikPort();
            var daftar = new List<Tuple<int, string>>
            {
                Tuple.Create(_k.PortWeb, "Web billing (Apache)"),
                Tuple.Create(_k.PortDb, "Database (MariaDB)"),
                Tuple.Create(_k.PortWs, "Realtime TV (Reverb)"),
            };
            if (_k.PortWa > 0) daftar.Add(Tuple.Create(_k.PortWa, "WhatsApp (lokal)"));

            var hasil = new List<ListViewItem>();
            foreach (var p in daftar)
            {
                string proses;
                bool aktif = pemilik.TryGetValue(p.Item1, out proses);
                // Kolom berwarna di daftar port = Status (indeks 2)
                var item = new ListViewItem(new[] { p.Item1.ToString(), p.Item2, aktif ? "Terbuka" : "Tidak aktif", aktif ? proses : "-" }) { UseItemStyleForSubItems = false };
                item.SubItems[2].ForeColor = aktif ? Hijau : Merah;
                item.SubItems[2].Font = new Font("Segoe UI Semibold", 9.5f);
                hasil.Add(item);
            }
            return hasil;
        }

        /// <summary>port LISTEN -> "nama.exe (PID)" dari netstat -ano</summary>
        private static Dictionary<int, string> PemilikPort()
        {
            var hasil = new Dictionary<int, string>();
            try
            {
                string keluaran = Perintah("netstat.exe", "-ano -p TCP", null, 8000);
                foreach (var baris in keluaran.Split('\n'))
                {
                    var m = Regex.Match(baris, @"^\s*TCP\s+\S+:(\d+)\s+\S+\s+LISTENING\s+(\d+)", RegexOptions.IgnoreCase);
                    if (!m.Success) continue;
                    int port = int.Parse(m.Groups[1].Value), pid = int.Parse(m.Groups[2].Value);
                    if (hasil.ContainsKey(port)) continue;
                    string nama;
                    try { nama = Process.GetProcessById(pid).ProcessName + ".exe"; } catch { nama = "PID"; }
                    hasil[port] = nama + " (" + pid + ")";
                }
            }
            catch
            {
                foreach (var ep in IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners())
                    if (!hasil.ContainsKey(ep.Port)) hasil[ep.Port] = "?";
            }
            return hasil;
        }

        private List<ListViewItem> BacaKesehatan()
        {
            var hasil = new List<ListViewItem>();

            // MySQL / MariaDB: query sungguhan
            string mysql = AlatDb("mariadb.exe", "mysql.exe");
            if (mysql == null) hasil.Add(Baris(Abu, "MySQL", "?", "mariadb.exe tidak ditemukan"));
            else
            {
                int kode;
                string k = PerintahDb(mysql, "--host=127.0.0.1 --port=" + _k.PortDb + " --user=root --batch --skip-column-names -e \"SELECT VERSION(), (SELECT COUNT(*) FROM billing_ps.users)\"", 8000, out kode);
                if (kode == 0)
                {
                    var bagian = k.Trim().Split('\t');
                    hasil.Add(Baris(Hijau, "MySQL", "OK", "MariaDB " + bagian[0].Split('-')[0] + (bagian.Length > 1 ? " · " + bagian[1] + " pengguna" : "")));
                }
                else hasil.Add(Baris(Merah, "MySQL", "GAGAL", Ringkas(k)));
            }

            // PHP CLI
            string php = Path.Combine(_k.Runtime, @"php\php.exe");
            if (!File.Exists(php)) hasil.Add(Baris(Abu, "PHP", "?", "php.exe tidak ditemukan"));
            else
            {
                string v = Perintah(php, "-r \"echo PHP_VERSION, ' ', extension_loaded('pdo_mysql') ? 'pdo_mysql' : 'TANPA pdo_mysql';\"", _k.App, 8000);
                bool ok = v.Contains("pdo_mysql") && !v.Contains("TANPA");
                // Peringatan php.ini (stderr) ikut terbaca: tampilkan versinya saja bila berhasil
                var cocok = Regex.Match(v, @"(\d+\.\d+\.\d+) pdo_mysql");
                hasil.Add(Baris(ok ? Hijau : Merah, "PHP", ok ? "OK" : "GAGAL", ok && cocok.Success ? "PHP " + cocok.Groups[1].Value + " · pdo_mysql" : Ringkas(v)));
            }

            // Apache + PHP + Laravel: halaman /up
            var sw = Stopwatch.StartNew();
            try
            {
                var req = (HttpWebRequest)WebRequest.Create("http://127.0.0.1:" + _k.PortWeb + "/up");
                req.Timeout = 8000;
                using (var resp = (HttpWebResponse)req.GetResponse())
                    hasil.Add(Baris(Hijau, "Apache (web)", "OK", "HTTP " + (int)resp.StatusCode + " · " + sw.ElapsedMilliseconds + " ms"));
            }
            catch (WebException ex)
            {
                var r = ex.Response as HttpWebResponse;
                hasil.Add(Baris(Merah, "Apache (web)", "GAGAL", r != null ? ("HTTP " + (int)r.StatusCode + " (lihat logs\\apache & app\\storage\\logs)") : ex.Message));
            }

            // Realtime TV
            hasil.Add(PortTerbuka(_k.PortWs)
                ? Baris(Hijau, "Realtime TV", "OK", "port " + _k.PortWs + " menerima koneksi")
                : Baris(Merah, "Realtime TV", "GAGAL", "port " + _k.PortWs + " tidak menerima koneksi"));

            // Ruang disk folder data
            try
            {
                var drive = new DriveInfo(Path.GetPathRoot(_k.Root));
                double gb = drive.AvailableFreeSpace / 1073741824.0;
                hasil.Add(Baris(gb < 2 ? Merah : (gb < 5 ? Kuning : Hijau), "Ruang disk", gb < 2 ? "PENUH" : "OK", gb.ToString("0.0") + " GB kosong di " + drive.Name));
            }
            catch { }

            return hasil;
        }

        private static bool PortTerbuka(int port)
        {
            try
            {
                using (var c = new TcpClient())
                {
                    var t = c.ConnectAsync("127.0.0.1", port);
                    return t.Wait(1500) && c.Connected;
                }
            }
            catch { return false; }
        }

        private static string Ringkas(string teks)
        {
            teks = (teks ?? "").Replace("\r", " ").Replace("\n", " ").Trim();
            return teks.Length > 160 ? teks.Substring(0, 160) + "…" : teks;
        }

        // ================= Menjalankan program =================
        private static string Perintah(string file, string argumen, string folder, int batasMs)
        {
            int kode;
            return Perintah(file, argumen, folder, batasMs, null, out kode);
        }

        private static string Perintah(string file, string argumen, string folder, int batasMs, Dictionary<string, string> env, out int kode)
        {
            var psi = new ProcessStartInfo(file, argumen)
            {
                UseShellExecute = false, CreateNoWindow = true,
                RedirectStandardOutput = true, RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8, StandardErrorEncoding = Encoding.UTF8,
            };
            if (folder != null) psi.WorkingDirectory = folder;
            if (env != null) foreach (var kv in env) psi.EnvironmentVariables[kv.Key] = kv.Value;
            using (var p = Process.Start(psi))
            {
                var keluar = p.StandardOutput.ReadToEndAsync();
                var galat = p.StandardError.ReadToEndAsync();
                if (!p.WaitForExit(batasMs)) { try { p.Kill(); } catch { } kode = -1; return "Waktu habis"; }
                kode = p.ExitCode;
                return keluar.Result + galat.Result;
            }
        }

        private string AlatDb(params string[] nama)
        {
            foreach (var n in nama)
            {
                string f = Path.Combine(_k.Runtime, @"mariadb\bin\" + n);
                if (File.Exists(f)) return f;
            }
            return null;
        }

        /// <summary>Program klien MariaDB dengan password lewat MYSQL_PWD (tidak muncul di daftar proses)</summary>
        private string PerintahDb(string file, string argumen, int batasMs, out int kode)
        {
            var env = new Dictionary<string, string> { { "MYSQL_PWD", _k.DbRoot } };
            return Perintah(file, argumen, null, batasMs, env, out kode);
        }

        // ================= Aksi layanan =================
        private async void Jalankan(string judul, Action aksi)
        {
            if (_sibuk) return;
            _sibuk = true;
            foreach (var t in _tombolAksi) t.Enabled = false;
            UseWaitCursor = true;
            Catat(judul + "…");
            try
            {
                await Task.Run(aksi);
                Catat(judul + ": selesai.");
            }
            catch (Exception ex)
            {
                Catat(judul + " GAGAL: " + ex.Message);
                MessageBox.Show(this, ex.Message, judul + " gagal", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
            finally
            {
                UseWaitCursor = false;
                foreach (var t in _tombolAksi) t.Enabled = true;
                _sibuk = false;
                Periksa();
            }
        }

        private void AksiTerpilih(string aksi)
        {
            if (_lvLayanan.SelectedItems.Count == 0) { MessageBox.Show(this, "Pilih layanan di daftar dulu.", "Delta Billing HuB Monitor"); return; }
            var item = _lvLayanan.SelectedItems[0];
            string nama = (string)item.Tag, judul = item.Text;
            if (aksi == "nyalakan") Jalankan("Menyalakan " + judul, () => Nyalakan(nama));
            else if (aksi == "hentikan") Jalankan("Menghentikan " + judul, () => Hentikan(nama));
            else Jalankan("Restart " + judul, () => { Hentikan(nama); Nyalakan(nama); });
        }

        private void Nyalakan(string nama)
        {
            if (nama == "BillingPS-Tunnel" && !AdaTokenTunnel()) { Catat("Tunnel belum diaktifkan (Admin → Pengaturan → Cloudflare Tunnel), dilewati."); return; }
            using (var sc = new ServiceController(nama))
            {
                if (sc.Status == ServiceControllerStatus.Running) { Catat(nama + " sudah berjalan."); return; }
                if (sc.Status != ServiceControllerStatus.StartPending) sc.Start();
                sc.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(45));
                Catat(nama + " berjalan.");
            }
        }

        private void Hentikan(string nama)
        {
            using (var sc = new ServiceController(nama))
            {
                if (sc.Status == ServiceControllerStatus.Stopped) return;
                if (sc.Status != ServiceControllerStatus.StopPending) sc.Stop();
                sc.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(60));
                Catat(nama + " berhenti.");
            }
        }

        private bool Terpasang(string nama)
        {
            try { using (var sc = new ServiceController(nama)) { var _ = sc.Status; return true; } }
            catch (InvalidOperationException) { return false; }
        }

        /// <summary>Database dulu, baru layanan lain (urutan sama dengan kelola\layanan.ps1)</summary>
        private void NyalakanSemua()
        {
            foreach (var l in DaftarLayanan)
                if (Terpasang(l.Nama)) { try { Nyalakan(l.Nama); } catch (Exception ex) { Catat(l.Nama + ": " + ex.Message); } }
        }

        private void RestartSemua()
        {
            foreach (var l in DaftarLayanan.Skip(1).Reverse())
                if (Terpasang(l.Nama)) { try { Hentikan(l.Nama); } catch (Exception ex) { Catat(l.Nama + ": " + ex.Message); } }
            if (Terpasang(DaftarLayanan[0].Nama)) Hentikan(DaftarLayanan[0].Nama);
            NyalakanSemua();
        }

        // ================= Backup =================
        private string FolderBackup()
        {
            string f = Path.Combine(_k.Data, @"storage\app\private\backup");
            Directory.CreateDirectory(f);
            return f;
        }

        // Sama dengan App\Services\BackupService
        private const string Pemisah = "\n-- ;;\n";
        private static readonly string[] TanpaData = { "sessions", "cache", "cache_locks", "jobs", "job_batches", "sync_antrean" };

        private void Backup()
        {
            if (!_k.Ada) throw new Exception("konfigurasi.json tidak ditemukan; kata sandi database tidak diketahui.");
            string dump = AlatDb("mariadb-dump.exe", "mysqldump.exe");
            if (dump == null) throw new Exception("mariadb-dump.exe tidak ditemukan di runtime\\mariadb\\bin.");

            // Database harus menyala; nyalakan bila berhenti
            if (!PortTerbuka(_k.PortDb))
            {
                Catat("Database belum berjalan, menyalakan BillingPS-Database…");
                Nyalakan("BillingPS-Database");
            }

            string folder = FolderBackup();
            string nama = "backup-" + DateTime.Now.ToString("yyyyMMdd-HHmmss") + "-monitor.zip";
            string sementara = Path.Combine(Path.GetTempPath(), "billingps-dump-" + Guid.NewGuid().ToString("N") + ".sql");

            try
            {
                // Tanpa trigger & komentar: trigger sinkron dipasang ulang otomatis saat pemulihan
                var argumen = new StringBuilder("--host=127.0.0.1 --port=" + _k.PortDb + " --user=root --single-transaction --quick" +
                    " --skip-triggers --skip-comments --skip-add-locks --skip-lock-tables --default-character-set=utf8mb4 --hex-blob" +
                    " --result-file=\"" + sementara + "\"");
                foreach (var t in TanpaData) argumen.Append(" --ignore-table-data=billing_ps." + t);
                argumen.Append(" billing_ps");

                int kode;
                Catat("Menjalankan mariadb-dump…");
                string keluaran = PerintahDb(dump, argumen.ToString(), 15 * 60 * 1000, out kode);
                if (kode != 0 || !File.Exists(sementara)) throw new Exception("mariadb-dump gagal: " + Ringkas(keluaran));

                string tujuan = Path.Combine(folder, nama);
                int foto = 0;
                using (var zip = ZipFile.Open(tujuan, ZipArchiveMode.Create))
                {
                    var entri = zip.CreateEntry("database.sql", CompressionLevel.Optimal);
                    using (var tulis = new StreamWriter(entri.Open(), new UTF8Encoding(false)))
                    {
                        tulis.Write("-- Backup Delta Billing HuB (Monitor) " + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "\n");
                        tulis.Write("-- Pulihkan: Admin -> Backup, php artisan backup:pulihkan <file>, atau installer\n\n");
                        TulisPernyataan(sementara, tulis);
                    }

                    var info = zip.CreateEntry("info.json");
                    using (var tulis = new StreamWriter(info.Open(), new UTF8Encoding(false)))
                        tulis.Write("{\n    \"aplikasi\": \"Delta Billing HuB\",\n    \"mode\": \"local\",\n    \"database\": \"billing_ps\",\n    \"dibuat\": \"" +
                                    DateTime.Now.ToString("yyyy-MM-ddTHH:mm:sszzz") + "\",\n    \"sumber\": \"monitor\"\n}");

                    if (_cbFoto.Checked)
                    {
                        string publik = Path.Combine(_k.Data, @"storage\app\public");
                        if (Directory.Exists(publik))
                        {
                            foreach (var f in Directory.GetFiles(publik, "*", SearchOption.AllDirectories))
                            {
                                string rel = f.Substring(publik.Length).TrimStart('\\').Replace('\\', '/');
                                if (rel.StartsWith(".")) continue;
                                zip.CreateEntryFromFile(f, "uploads/" + rel, CompressionLevel.Optimal);
                                foto++;
                            }
                        }
                    }
                }

                var ukuran = new FileInfo(tujuan).Length / 1048576.0;
                Catat("Backup tersimpan: " + tujuan + " (" + ukuran.ToString("0.0") + " MB, " + foto + " file foto)");
                BeginInvoke(new Action(() => MessageBox.Show(this,
                    "Backup berhasil:\n" + tujuan + "\n\nSimpan salinannya di flashdisk / Google Drive.",
                    "Backup database", MessageBoxButtons.OK, MessageBoxIcon.Information)));
            }
            finally
            {
                try { File.Delete(sementara); } catch { }
            }
        }

        /// <summary>
        /// Ubah keluaran mariadb-dump (satu pernyataan diakhiri ";" di akhir baris) ke format BackupService:
        /// tiap pernyataan + ";" + pemisah. Nilai teks di dump tidak pernah memuat baris baru mentah (di-escape \n),
        /// jadi ";" di akhir baris selalu akhir pernyataan.
        /// </summary>
        internal static void TulisPernyataan(string fileDump, StreamWriter tulis)
        {
            tulis.Write("SET NAMES utf8mb4;" + Pemisah);
            tulis.Write("SET FOREIGN_KEY_CHECKS=0;" + Pemisah);
            var kalimat = new StringBuilder();
            using (var baca = new StreamReader(fileDump, Encoding.UTF8))
            {
                string baris;
                while ((baris = baca.ReadLine()) != null)
                {
                    if (kalimat.Length == 0 && (baris.Length == 0 || baris.StartsWith("--"))) continue;
                    if (kalimat.Length > 0) kalimat.Append('\n');
                    kalimat.Append(baris);
                    if (baris.EndsWith(";"))
                    {
                        tulis.Write(kalimat.ToString());
                        tulis.Write(Pemisah);
                        kalimat.Clear();
                    }
                }
            }
            if (kalimat.Length > 0) { tulis.Write(kalimat.ToString()); tulis.Write(";" + Pemisah); }
        }
    }
}
