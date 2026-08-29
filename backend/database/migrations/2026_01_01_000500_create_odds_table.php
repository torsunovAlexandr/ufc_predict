<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Котировки букмекеров. Одна строка — один исход у одного букмекера
 * на определённый момент времени (история котировок сохраняется).
 *
 * market:     moneyline | draw | totals | method
 * selection:  fighter1 | fighter2 | draw | over | under | ko_tko | submission | decision
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_id')->constrained('fights')->cascadeOnDelete();
            $table->foreignId('fighter_id')->nullable()->constrained('fighters')->nullOnDelete();

            $table->string('bookmaker', 60)->index();
            $table->enum('market', ['moneyline', 'draw', 'totals', 'method'])->index();
            $table->string('selection', 30);
            $table->decimal('line', 4, 1)->nullable();  // напр. 2.5 для тотала раундов
            $table->decimal('price', 8, 3);             // десятичный коэффициент
            $table->decimal('implied_probability', 6, 5)->nullable();

            $table->string('source', 40)->default('the_odds_api');
            $table->boolean('is_latest')->default(true)->index();
            $table->timestamp('fetched_at')->index();
            $table->timestamps();

            $table->index(['fight_id', 'market', 'is_latest'], 'odds_fight_market_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds');
    }
};
