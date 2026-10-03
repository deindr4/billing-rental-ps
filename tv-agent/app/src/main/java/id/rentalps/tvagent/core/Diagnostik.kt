package id.rentalps.tvagent.core

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import id.rentalps.tvagent.BuildConfig
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject

/**
 * Laporan kemampuan TV, dikirim ke server lewat heartbeat & tampil di Admin → Perangkat TV.
 * Dengan ini perilaku tiap merek (Xiaomi/TCL) bisa diperiksa tanpa menyambungkan TV ke PC.
 */
object Diagnostik {

    fun kumpulkan(ctx: Context): JsonObject {
        val pm = ctx.packageManager
        val inputs = Hdmi.daftarInput(ctx)

        return buildJsonObject {
            put("app", JsonPrimitive("${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"))
            put("android", JsonPrimitive("${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})"))
            put("perangkat", JsonPrimitive("${Build.MANUFACTURER} ${Build.MODEL}"))
            put("build", JsonPrimitive(Build.DISPLAY))
            put("leanback", JsonPrimitive(pm.hasSystemFeature(PackageManager.FEATURE_LEANBACK)))
            put("izin_overlay", JsonPrimitive(Settings.canDrawOverlays(ctx)))
            put("izin_pasang_apk", JsonPrimitive(pm.canRequestPackageInstalls()))
            put("launcher_utama", JsonPrimitive(launcherUtama(ctx)))
            put("bebas_hemat_baterai", JsonPrimitive(bebasHematDaya(ctx)))
            put("jumlah_hdmi", JsonPrimitive(inputs.count { it.hdmi }))
            put("input", JsonArray(inputs.map { JsonPrimitive("${it.label}${if (it.hdmi) " [HDMI]" else ""} = ${it.id}") }))
            Hdmi.errorTerakhir?.let { put("error_hdmi", JsonPrimitive(it)) }
            Suara.laporan?.let { put("suara_terakhir", JsonPrimitive(it)) }
            put("fitur_device_admin", JsonPrimitive(Remote.didukungDeviceAdmin(ctx)))
            put("device_admin", JsonPrimitive(Remote.deviceAdmin(ctx)))
            put("device_owner", JsonPrimitive(Remote.deviceOwner(ctx)))
            Remote.laporan?.let { put("remote_terakhir", JsonPrimitive(it)) }
            put("izin_kunci_remote", JsonPrimitive(id.rentalps.tvagent.service.KunciRemote.diizinkan(ctx)))
            id.rentalps.tvagent.service.KunciRemote.laporan()?.let { put("tombol_ditahan", JsonPrimitive(it)) }
            put("aplikasi_terpasang", JsonArray(AplikasiTv.daftarTerpasang(ctx).take(40).map { JsonPrimitive(it) }))
        }
    }

    fun launcherUtama(ctx: Context): Boolean {
        // Android 10+: launcher utama = pemegang role HOME
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.Q) {
            val role = ctx.getSystemService(android.app.role.RoleManager::class.java)
            if (role != null && role.isRoleAvailable(android.app.role.RoleManager.ROLE_HOME)) {
                return role.isRoleHeld(android.app.role.RoleManager.ROLE_HOME)
            }
        }
        val home = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        val info = ctx.packageManager.resolveActivity(home, PackageManager.MATCH_DEFAULT_ONLY)
        return info?.activityInfo?.packageName == ctx.packageName
    }

    private fun bebasHematDaya(ctx: Context): Boolean {
        val power = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        return power.isIgnoringBatteryOptimizations(ctx.packageName)
    }
}
