<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel fobi_users (versi public/lite)
 * Tabel ini menyimpan data user untuk aplikasi FOBi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fobi_users', function (Blueprint $table) {
            $table->id();
            $table->string('uname')->unique();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->text('bio')->nullable();
            $table->string('profile_picture')->nullable();
            $table->string('profile_picture_storage_type')->default('local');
            $table->integer('level')->default(1);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('verification_token')->nullable();
            $table->string('license_observation')->default('CC-BY-NC');
            $table->string('license_photo')->default('CC-BY-NC');
            $table->string('license_audio')->default('CC-BY-NC');
            $table->unsignedBigInteger('burungnesia_user_id')->nullable();
            $table->unsignedBigInteger('kupunesia_user_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fobi_users');
    }
};
