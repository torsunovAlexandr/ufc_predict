<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Детальная статистика бойца в конкретном бою. Это основной источник данных
 * для расчёта формы по последним 5–10 боям и для оценки кардио
 * (сравнение показателей 1–2 раундов и 3+ раундов).
 *
 * fight_id может быть null — для боёв вне базы `fights` (история до UFC,
 * импортированная из Sherdog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fighter_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_id')->nullable()->constrained('fights')->nullOnDelete();
            $table->foreignId('fighter_id')->constrained('fighters')->cascadeOnDelete();
            $table->foreignId('opponent_id')->nullable()->constrained('fighters')->nullOnDelete();

            $table->date('fight_date')->nullable()->index();
            $table->string('event_name')->nullable();
            $table->enum('result', ['win', 'loss', 'draw', 'nc'])->nullable();
            $table->enum('method', ['ko_tko', 'submission', 'decision', 'dq', 'other'])->nullable();
            $table->string('method_detail')->nullable();
            $table->unsignedTinyInteger('end_round')->nullable();
            $table->unsignedSmallInteger('end_time_seconds')->nullable();
            $table->unsignedTinyInteger('scheduled_rounds')->default(3);
            $table->boolean('is_title_fight')->default(false);

            // Итоговые показатели боя
            $table->unsignedSmallInteger('knockdowns')->default(0);
            $table->unsignedSmallInteger('sig_strikes_landed')->default(0);
            $table->unsignedSmallInteger('sig_strikes_attempted')->default(0);
            $table->unsignedSmallInteger('sig_strikes_absorbed')->default(0);
            $table->unsignedSmallInteger('opponent_sig_strikes_attempted')->default(0);
            $table->unsignedSmallInteger('total_strikes_landed')->default(0);
            $table->unsignedSmallInteger('total_strikes_attempted')->default(0);
            $table->unsignedSmallInteger('takedowns_landed')->default(0);
            $table->unsignedSmallInteger('takedowns_attempted')->default(0);
            $table->unsignedSmallInteger('takedowns_conceded')->default(0);
            $table->unsignedSmallInteger('takedowns_faced')->default(0);
            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->unsignedSmallInteger('reversals')->default(0);
            $table->unsignedSmallInteger('control_time_seconds')->default(0);
            $table->unsignedSmallInteger('fight_time_seconds')->default(0);

            // Кардио: разбивка «ранние раунды» / «поздние раунды»
            $table->unsignedSmallInteger('sig_strikes_landed_early')->default(0);
            $table->unsignedSmallInteger('sig_strikes_landed_late')->default(0);
            $table->unsignedSmallInteger('fight_time_seconds_early')->default(0);
            $table->unsignedSmallInteger('fight_time_seconds_late')->default(0);

            // Полная разбивка по раундам как пришла из источника
            $table->json('rounds')->nullable();

            // Уровень соперника на момент боя (0..1) — для взвешивания формы
            $table->decimal('opponent_quality', 4, 3)->nullable();

            $table->string('source', 40)->default('ufc');
            $table->timestamps();

            $table->index(['fighter_id', 'fight_date']);
            $table->unique(['fighter_id', 'fight_id'], 'fighter_stats_unique_per_fight');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fighter_stats');
    }
};
