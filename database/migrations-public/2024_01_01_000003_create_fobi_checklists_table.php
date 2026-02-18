<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel fobi_checklists (versi public/lite)
 * Tabel ini menyimpan data observasi/checklist
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fobi_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('taxa_id')->nullable();
            
            // Location
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('location_name')->nullable();
            $table->string('administrative_area')->nullable();
            
            // Observation details
            $table->date('observation_date');
            $table->time('observation_time')->nullable();
            $table->text('notes')->nullable();
            $table->integer('count')->default(1);
            
            // Quality grade
            $table->enum('quality_grade', [
                'research grade',
                'confirmed id', 
                'needs id',
                'low quality id',
                'casual'
            ])->default('needs id');
            
            // Wild status
            $table->boolean('is_wild')->default(true);
            
            // Visibility
            $table->boolean('is_public')->default(true);
            $table->boolean('obscured')->default(false);
            
            // Source
            $table->string('source')->default('fobi');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('user_id')->references('id')->on('fobi_users')->onDelete('cascade');
            $table->foreign('taxa_id')->references('id')->on('taxa')->onDelete('set null');
            
            $table->index(['latitude', 'longitude']);
            $table->index('observation_date');
            $table->index('quality_grade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fobi_checklists');
    }
};
