<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Виртуальные ставки. Строка создаётся как рекомендация (status = recommended),
 * а после подтверждения пользователем становится размещённой ставкой
 * (status = placed) и участвует в расчёте банкролла.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->foreignId('fight_id')->constrained('fights')->cascadeOnDelete();
            $table->foreignId('fighter_id')->nullable()->constrained('fighters')->nullOnDelete();

            $table->enum('market', ['moneyline', 'draw', 'totals', 'method']);
            $table->string('selection', 30);
            $table->decimal('line', 4, 1)->nullable();
            $table->string('bookmaker', 60)->nullable();

            $table->decimal('odds', 8, 3);
            $table->decimal('model_probability', 6, 5);
            $table->decimal('implied_probability', 6, 5);
            $table->decimal('expected_value', 8, 5);
            $table->decimal('kelly_fraction', 8, 5);
            $table->decimal('stake_fraction', 8, 5);
            $table->decimal('stake', 12, 2);

            $table->enum('status', ['recommended', 'placed', 'won', 'lost', 'void', 'skipped'])
                ->default('recommended')->index();

            $table->decimal('payout', 12, 2)->nullable();
            $table->decimal('profit', 12, 2)->nullable();
            $table->decimal('bankroll_before', 12, 2)->nullable();
            $table->decimal('bankroll_after', 12, 2)->nullable();

            // Флаги для сравнения с бенчмарками (раздел 6.3)
            $table->boolean('is_benchmark')->default(false)->index();
            $table->string('benchmark_strategy', 30)->nullable();

            $table->text('reason')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['fight_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
