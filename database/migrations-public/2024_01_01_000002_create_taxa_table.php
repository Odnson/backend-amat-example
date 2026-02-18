<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel taxa (versi public/lite)
 * Tabel ini menyimpan data taksonomi untuk identifikasi spesies
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxa', function (Blueprint $table) {
            $table->id();
            $table->string('scientific_name');
            $table->string('rank')->default('species');
            $table->string('taxonomic_status')->default('ACCEPTED');
            
            // Hierarchy
            $table->string('kingdom')->nullable();
            $table->string('phylum')->nullable();
            $table->string('class')->nullable();
            $table->string('order')->nullable();
            $table->string('family')->nullable();
            $table->string('genus')->nullable();
            $table->string('species')->nullable();
            $table->string('subspecies')->nullable();
            
            // Common names
            $table->string('cname_species')->nullable();
            $table->string('cname_genus')->nullable();
            $table->string('cname_family')->nullable();
            $table->string('cname_order')->nullable();
            
            // Author citation
            $table->string('author')->nullable();
            
            // Conservation status
            $table->string('iucn_red_list_category')->nullable();
            $table->string('cites_status')->nullable();
            
            // Accepted name for synonyms
            $table->string('accepted_scientific_name')->nullable();
            $table->unsignedBigInteger('accepted_name_id')->nullable();
            
            // Media
            $table->string('default_image')->nullable();
            
            $table->timestamps();
            
            $table->index('scientific_name');
            $table->index('rank');
            $table->index('genus');
            $table->index('family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxa');
    }
};
