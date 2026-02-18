<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel fobi_comments (versi public/lite)
 * Tabel ini menyimpan komentar pada observasi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fobi_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            
            $table->text('body');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('checklist_id')->references('id')->on('fobi_checklists')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('fobi_users')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('fobi_comments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fobi_comments');
    }
};
