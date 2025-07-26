<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('instrumentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('uploads')->onDelete('cascade');
            $table->string('TckrSymb')->nullable();
            $table->date('RptDt')->nullable();
            $table->string('MktNm')->nullable();
            $table->string('SctyCtgyNm')->nullable();
            $table->string('ISIN')->nullable();
            $table->string('CrpnNm')->nullable();
            $table->json('dados_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instrumentos');
    }
};
