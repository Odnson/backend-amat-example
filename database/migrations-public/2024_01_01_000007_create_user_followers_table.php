<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel user_followers (versi public/lite)
 * Tabel ini menyimpan relasi follow antar user
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_followers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follower_id');
            $table->unsignedBigInteger('following_id');
            $table->timestamps();
            
            $table->foreign('follower_id')->references('id')->on('fobi_users')->onDelete('cascade');
            $table->foreign('following_id')->references('id')->on('fobi_users')->onDelete('cascade');
            
            $table->unique(['follower_id', 'following_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_followers');
    }
};
