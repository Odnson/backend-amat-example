<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel fobi_checklist_media (versi public/lite)
 * Tabel ini menyimpan media (foto, audio) untuk observasi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fobi_checklist_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            
            // Media info
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->enum('media_type', ['image', 'audio', 'video'])->default('image');
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            
            // Storage
            $table->string('storage_type')->default('local');
            
            // For audio - spectrogram
            $table->string('spectrogram_path')->nullable();
            
            // Metadata
            $table->string('license')->default('CC-BY-NC');
            $table->string('photographer_name')->nullable();
            $table->text('caption')->nullable();
            
            // Order
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
            
            $table->foreign('checklist_id')->references('id')->on('fobi_checklists')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fobi_checklist_media');
    }
};
