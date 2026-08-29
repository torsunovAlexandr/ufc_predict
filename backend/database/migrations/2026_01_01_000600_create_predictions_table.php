<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прогноз системы по бою: вероятности исходов, вклад отдельных факторов,
 * текстовое объяснение и сводка по рекомендованной ставке.
 * Сами рекомендации (их может быть несколько на бой) лежат в `bets`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_id')->constrained('fights')->cascadeOnDelete();

            $table->string('model_version', 20)->default('1.0');

            // Вероятности исхода
            $table->decimal('probability_fighter1', 6, 5);
            $table->decimal('probability_fighter2', 6, 5);
            $table->decimal('probability_draw', 6, 5)->default(0);

            // Сырые баллы модели до сигмоиды
            $table->decimal('score_fighter1', 8, 5)->default(0);
            $table->decimal('score_fighter2', 8, 5)->default(0);

            // Вероятности метода победы (для каждого бойца) и тотала раундов
            $table->json('method_probabilities')->nullable();
            $table->decimal('probability_over_2_5', 6, 5)->nullable();
            $table->decimal('probability_under_2_5', 6, 5)->nullable();

            // Разбор: вклад каждого показателя и сработавшие экспертные правила
            $table->json('factors')->nullable();
            $table->json('applied_rules')->nullable();
            $table->text('explanation')->nullable();

            // Уверенность модели 0..1 (насколько далеко вероятность от 50/50
            // с поправкой на полноту данных)
            $table->decimal('confidence', 5, 4)->default(0);
            $table->decimal('data_completeness', 5, 4)->default(0);

            // Сводка по лучшей рекомендации (детали — в bets)
            $table->string('recommended_selection', 30)->nullable();
            $table->decimal('recommended_odds', 8, 3)->nullable();
            $table->decimal('recommended_stake', 12, 2)->nullable();
            $table->decimal('recommended_ev', 8, 5)->nullable();

            $table->boolean('is_current')->default(true)->index();
            $table->timestamps();

            $table->index(['fight_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
