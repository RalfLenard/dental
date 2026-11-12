<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mental_pdf_mhg2s', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mental_pdf_record_id')->unique()->constrained('mental_pdf_records')->cascadeOnDelete();
            $table->json('form_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mental_pdf_mhg2s');
    }
};