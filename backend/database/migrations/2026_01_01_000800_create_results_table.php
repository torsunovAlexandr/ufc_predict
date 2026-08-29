<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_id')->unique()->constrained('fights')->cascadeOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('fighters')->nullOnDelete();

            $table->boolean('is_draw')->default(false);
            $table->boolean('is_no_contest')->default(false);
            $table->enum('method', ['ko_tko', 'submission', 'decision', 'dq', 'other'])->nullable();
            $table->string('method_detail')->nullable();
            $table->unsignedTinyInteger('end_round')->nullable();
            $table->unsignedSmallInteger('end_time_seconds')->nullable();

            // Полное время боя в секундах — нужно для расчёта тотала раундов
            $table->unsignedSmallInteger('total_seconds')->nullable();

            $table->string('source', 60)->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('entered_manually')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
