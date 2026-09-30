<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            // Input HDMI tempat PS tersambung (id TvInput Android + label, mis. "HDMI 2")
            $table->string('input_hdmi', 191)->nullable()->after('layar_hidup');
            $table->string('input_hdmi_label', 60)->nullable()->after('input_hdmi');
        });
    }

    public function down(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            $table->dropColumn(['input_hdmi', 'input_hdmi_label']);
        });
    }
};
