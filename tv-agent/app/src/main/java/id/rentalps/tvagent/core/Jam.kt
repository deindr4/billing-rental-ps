package id.rentalps.tvagent.core

/**
 * Jam server. Jam TV bisa meleset, jadi semua timer memakai jam TV + selisih ke server.
 * Selisih diperbarui setiap status dari server; saat offline memakai selisih terakhir.
 */
object Jam {
    @Volatile
    private var selisihMs: Long = 0

    fun setel(serverMs: Long) {
        selisihMs = serverMs - System.currentTimeMillis()
    }

    fun sekarang(): Long = System.currentTimeMillis() + selisihMs

    /** 3725 detik -> "01:02:05" */
    fun format(detik: Long): String {
        val d = maxOf(0L, detik)
        return "%02d:%02d:%02d".format(d / 3600, (d % 3600) / 60, d % 60)
    }
}
