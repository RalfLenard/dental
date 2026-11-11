<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mental_pdf_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_no')->nullable()->unique();
            $table->string('patient_name')->nullable()->index();
            $table->json('form_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mental_pdf_records');
    }
};