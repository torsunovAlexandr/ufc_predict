<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bankroll_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_id')->nullable()->constrained('bets')->nullOnDelete();

            $table->enum('type', [
                'initial',      // стартовый банк
                'bet_placed',   // списание при размещении ставки
                'bet_won',      // зачисление выигрыша
                'bet_lost',     // проигрыш (баланс уже списан, дельта = 0)
                'bet_void',     // возврат
                'adjustment',   // ручная корректировка
                'reset',        // сброс банка в настройках
            ])->index();

            $table->decimal('amount', 12, 2);          // дельта (может быть отрицательной)
            $table->decimal('balance_after', 12, 2);   // баланс после операции
            $table->string('description')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bankroll_history');
    }
};
