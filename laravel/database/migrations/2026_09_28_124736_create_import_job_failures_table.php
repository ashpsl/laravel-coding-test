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
        Schema::create('import_job_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('errors');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_job_failures');
    }
};
