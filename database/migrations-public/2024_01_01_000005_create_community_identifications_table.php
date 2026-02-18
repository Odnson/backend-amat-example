<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel community_identifications (versi public/lite)
 * Tabel ini menyimpan identifikasi dari komunitas untuk observasi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_identifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('taxa_id');
            
            // Identification details
            $table->text('body')->nullable();
            $table->boolean('is_current')->default(true);
            $table->boolean('is_withdrawn')->default(false);
            
            // Agreement
            $table->boolean('agrees_with_observation')->default(true);
            
            // Certainty for challenging higher grades
            $table->tinyInteger('certainty')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('checklist_id')->references('id')->on('fobi_checklists')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('fobi_users')->onDelete('cascade');
            $table->foreign('taxa_id')->references('id')->on('taxa')->onDelete('cascade');
            
            $table->index(['checklist_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_identifications');
    }
};
