/**
 * Service WhatsApp lokal untuk Billing Rental PS.
 * - Login via QR (seperti WhatsApp Web), sesi tersimpan di folder ./sesi (atau FOLDER_SESI; installer Windows:
 *   <root>\data\whatsapp-sesi supaya tidak hilang saat update)
 * - Semua pesan masuk antrean: dikirim satu per satu dengan jeda acak & batas per jam
 * - Hanya menerima request dengan header x-token yang cocok
 */
import express from 'express';
import fs from 'node:fs';
import pino from 'pino';
import QRCode from 'qrcode';
import makeWASocket, { DisconnectReason, fetchLatestBaileysVersion, useMultiFileAuthState } from '@whiskeysockets/baileys';

const PORT = Number(process.env.PORT || 3001);
const TOKEN = process.env.WA_TOKEN || '';
const JEDA_MIN = Number(process.env.JEDA_MIN || 4) * 1000;
const JEDA_MAKS = Number(process.env.JEDA_MAKS || 9) * 1000;
const MAKS_PER_JAM = Number(process.env.MAKS_PER_JAM || 40);
const MAKS_PER_HARI = Number(process.env.MAKS_PER_HARI || 200);
const FOLDER_SESI = process.env.FOLDER_SESI || './sesi';

if (!TOKEN) {
    console.error('WA_TOKEN belum diisi di .env');
    process.exit(1);
}

let sock = null;
let status = 'menghubungkan'; // menghubungkan | menunggu_scan | terhubung | logout
let qrTerakhir = null;
let nomor = null;

/* ------------------------------------------------------------------ */
/* Koneksi                                                             */
/* ------------------------------------------------------------------ */
async function mulai() {
    const { state, saveCreds } = await useMultiFileAuthState(FOLDER_SESI);
    const { version } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        browser: ['Billing Rental PS', 'Chrome', '1.0'],
        markOnlineOnConnect: false,
        syncFullHistory: false,
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (u) => {
        if (u.qr) {
            qrTerakhir = await QRCode.toDataURL(u.qr);
            status = 'menunggu_scan';
            console.log('QR baru tersedia, scan dari panel admin.');
        }

        if (u.connection === 'open') {
            status = 'terhubung';
            qrTerakhir = null;
            nomor = sock.user?.id?.split(':')[0]?.split('@')[0] ?? null;
            console.log(`Terhubung sebagai ${nomor}`);
        }

        if (u.connection === 'close') {
            const kode = u.lastDisconnect?.error?.output?.statusCode;

            if (kode === DisconnectReason.loggedOut) {
                status = 'logout';
                nomor = null;
                fs.rmSync(FOLDER_SESI, { recursive: true, force: true });
                console.log('Logout. Menyiapkan QR baru...');
                setTimeout(mulai, 1500);
            } else {
                status = 'menghubungkan';
                setTimeout(mulai, 5000);
            }
        }
    });
}

/* ------------------------------------------------------------------ */
/* Antrean kirim (anti-spam)                                           */
/* ------------------------------------------------------------------ */
let antrean = Promise.resolve();
const riwayatKirim = []; // timestamp pesan terkirim (24 jam terakhir)

const tunggu = (ms) => new Promise((r) => setTimeout(r, ms));
const jedaAcak = () => JEDA_MIN + Math.floor(Math.random() * Math.max(1, JEDA_MAKS - JEDA_MIN));

function keJid(tujuan) {
    const t = String(tujuan || '').trim();
    if (t.includes('@')) return t; // grup (@g.us) atau jid lengkap

    let angka = t.replace(/\D/g, '');
    if (angka.startsWith('0')) angka = '62' + angka.slice(1);
    if (!angka) throw new Error('Tujuan tidak valid');

    return `${angka}@s.whatsapp.net`;
}

function antrekan(kerja) {
    const hasil = antrean.then(async () => {
        if (status !== 'terhubung' || !sock) throw new Error('WhatsApp belum terhubung');

        const satuJamLalu = Date.now() - 3600_000;
        const sehariLalu = Date.now() - 86_400_000;
        while (riwayatKirim.length && riwayatKirim[0] < sehariLalu) riwayatKirim.shift();
        if (riwayatKirim.filter((t) => t >= satuJamLalu).length >= MAKS_PER_JAM) throw new Error('Batas pesan per jam tercapai, coba lagi nanti');
        if (riwayatKirim.length >= MAKS_PER_HARI) throw new Error('Batas pesan per hari tercapai, coba lagi besok');

        await kerja();
        riwayatKirim.push(Date.now());
        await tunggu(jedaAcak());
    });

    antrean = hasil.catch(() => {});
    return hasil;
}

/**
 * Kirim seperti manusia: cek nomor terdaftar di WhatsApp (mengirim ke nomor tak terdaftar = tanda bot),
 * "sedang mengetik…" sebanding panjang pesan (± 1,5–8 detik), baru kirim.
 */
async function kirim(jid, pesan) {
    if (jid.endsWith('@s.whatsapp.net')) {
        const [cek] = await sock.onWhatsApp(jid).catch(() => [null]);
        if (cek && !cek.exists) throw new Error('Nomor tidak terdaftar di WhatsApp');
    }

    const panjang = String(pesan.text ?? pesan.caption ?? '').length;
    const lamaKetik = Math.min(8000, 1500 + panjang * 25) + Math.floor(Math.random() * 1500);

    await sock.presenceSubscribe(jid).catch(() => {});
    await sock.sendPresenceUpdate('composing', jid).catch(() => {});
    await tunggu(lamaKetik);
    await sock.sendPresenceUpdate('paused', jid).catch(() => {});
    await sock.sendMessage(jid, pesan);
}

/* ------------------------------------------------------------------ */
/* HTTP API                                                            */
/* ------------------------------------------------------------------ */
const app = express();
app.use(express.json({ limit: '25mb' }));

app.use((req, res, next) => {
    if (req.get('x-token') !== TOKEN) return res.status(401).json({ error: 'Token salah' });
    next();
});

app.get('/status', (req, res) => {
    res.json({ status, nomor, qr: status === 'menunggu_scan' ? qrTerakhir : null });
});

app.get('/grup', async (req, res) => {
    try {
        if (status !== 'terhubung') throw new Error('WhatsApp belum terhubung');
        const semua = await sock.groupFetchAllParticipating();
        const grup = Object.values(semua)
            .map((g) => ({ id: g.id, nama: g.subject }))
            .sort((a, b) => a.nama.localeCompare(b.nama));
        res.json({ grup });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

app.post('/logout', async (req, res) => {
    try {
        await sock?.logout();
    } catch {
        // abaikan, sesi tetap dibersihkan lewat event close
    }
    res.json({ ok: true });
});

app.post('/kirim', async (req, res) => {
    try {
        const jid = keJid(req.body.tujuan);
        const teks = String(req.body.teks || '').slice(0, 4000);
        if (!teks) throw new Error('Teks kosong');

        await antrekan(() => kirim(jid, { text: teks }));
        res.json({ ok: true });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

app.post('/kirim-dokumen', async (req, res) => {
    try {
        const jid = keJid(req.body.tujuan);
        const isi = Buffer.from(String(req.body.base64 || ''), 'base64');
        if (!isi.length) throw new Error('File kosong');

        await antrekan(() => kirim(jid, {
            document: isi,
            mimetype: 'application/pdf',
            fileName: String(req.body.namaFile || 'laporan.pdf'),
            caption: req.body.caption ? String(req.body.caption).slice(0, 1000) : undefined,
        }));
        res.json({ ok: true });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

app.listen(PORT, '127.0.0.1', () => {
    console.log(`WhatsApp service jalan di http://127.0.0.1:${PORT}`);
    mulai();
});
