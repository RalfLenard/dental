<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_no', 40)->nullable()->unique();
            $table->string('patient_name')->nullable();
            $table->json('form_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_records');
    }
};
