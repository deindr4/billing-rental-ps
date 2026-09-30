package id.rentalps.tvagent.core

import java.nio.ByteBuffer
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Kode darurat offline — harus identik dengan App\Services\Tv\KodeDarurat (server).
 * TOTP RFC 6238: HMAC-SHA1, periode 300 detik, 6 digit, kunci = rahasia_offline (UTF-8).
 */
object KodeDarurat {
    private const val PERIODE_DETIK = 300L

    fun buat(rahasia: String, waktuDetik: Long): String {
        val counter = ByteBuffer.allocate(8).putLong(waktuDetik / PERIODE_DETIK).array()
        val mac = Mac.getInstance("HmacSHA1").apply { init(SecretKeySpec(rahasia.toByteArray(Charsets.UTF_8), "HmacSHA1")) }
        val hmac = mac.doFinal(counter)
        val offset = hmac[19].toInt() and 0x0F

        val angka = ((hmac[offset].toInt() and 0x7F) shl 24) or
            ((hmac[offset + 1].toInt() and 0xFF) shl 16) or
            ((hmac[offset + 2].toInt() and 0xFF) shl 8) or
            (hmac[offset + 3].toInt() and 0xFF)

        return (angka % 1_000_000).toString().padStart(6, '0')
    }

    /** Terima periode sekarang & satu periode sebelumnya */
    fun cocok(rahasia: String, kode: String, waktuDetik: Long): Boolean =
        listOf(0L, -PERIODE_DETIK).any { buat(rahasia, waktuDetik + it) == kode.trim() }
}
