<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('fighter1_id')->constrained('fighters')->cascadeOnDelete();
            $table->foreignId('fighter2_id')->constrained('fighters')->cascadeOnDelete();

            $table->string('weight_class', 60)->nullable();
            $table->unsignedTinyInteger('scheduled_rounds')->default(3);
            $table->boolean('is_title_fight')->default(false);
            $table->boolean('is_main_event')->default(false);
            $table->enum('card_segment', ['main', 'prelim', 'early_prelim'])->default('main');
            $table->unsignedTinyInteger('bout_order')->default(0);

            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'postponed'])->default('scheduled')->index();
            $table->string('external_id')->nullable()->index();
            $table->string('ufc_url')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'bout_order']);
            $table->unique(['event_id', 'fighter1_id', 'fighter2_id'], 'fights_event_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fights');
    }
};
