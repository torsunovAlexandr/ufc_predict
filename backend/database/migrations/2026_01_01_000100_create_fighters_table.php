<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бойцы. Хранит как «карточку» бойца (физика, рекорд), так и агрегированную
 * карьерную статистику с ufc.com. Показатели формы по последним боям
 * считаются на лету из fighter_stats и здесь не дублируются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fighters', function (Blueprint $table) {
            $table->id();

            // Идентификация и источники
            $table->string('name')->index();
            $table->string('slug')->unique();
            $table->string('nickname')->nullable();
            $table->string('ufc_id')->nullable()->index();
            $table->string('ufc_url')->nullable();
            $table->string('sherdog_url')->nullable();
            $table->string('image_url')->nullable();
            $table->string('country', 100)->nullable();

            // Физические данные
            $table->date('date_of_birth')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('reach_cm')->nullable();
            $table->unsignedSmallInteger('leg_reach_cm')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->enum('stance', ['orthodox', 'southpaw', 'switch', 'unknown'])->default('unknown');
            $table->string('weight_class', 60)->nullable()->index();

            // Рекорд (общий)
            $table->unsignedSmallInteger('wins')->default(0);
            $table->unsignedSmallInteger('losses')->default(0);
            $table->unsignedSmallInteger('draws')->default(0);
            $table->unsignedSmallInteger('no_contests')->default(0);

            // Рекорд в UFC
            $table->unsignedSmallInteger('ufc_wins')->default(0);
            $table->unsignedSmallInteger('ufc_losses')->default(0);
            $table->unsignedSmallInteger('ufc_draws')->default(0);

            // Разбивка побед и поражений по методам
            $table->unsignedSmallInteger('wins_by_ko')->default(0);
            $table->unsignedSmallInteger('wins_by_submission')->default(0);
            $table->unsignedSmallInteger('wins_by_decision')->default(0);
            $table->unsignedSmallInteger('losses_by_ko')->default(0);
            $table->unsignedSmallInteger('losses_by_submission')->default(0);
            $table->unsignedSmallInteger('losses_by_decision')->default(0);

            // Карьерная статистика (доли хранятся как 0..1)
            $table->decimal('sig_strikes_landed_per_min', 6, 2)->nullable();
            $table->decimal('sig_strikes_absorbed_per_min', 6, 2)->nullable();
            $table->decimal('striking_accuracy', 5, 4)->nullable();
            $table->decimal('striking_defense', 5, 4)->nullable();
            $table->decimal('takedown_avg_per_15min', 6, 2)->nullable();
            $table->decimal('takedown_accuracy', 5, 4)->nullable();
            $table->decimal('takedown_defense', 5, 4)->nullable();
            $table->decimal('submission_avg_per_15min', 6, 2)->nullable();
            $table->decimal('knockdown_avg', 6, 2)->nullable();
            $table->unsignedInteger('avg_fight_time_seconds')->nullable();

            // Опыт
            $table->unsignedSmallInteger('five_round_fights')->default(0);
            $table->unsignedSmallInteger('title_fights')->default(0);

            // Классификация стиля: wrestler | striker | grappler | balanced
            $table->enum('style', ['wrestler', 'striker', 'grappler', 'balanced', 'unknown'])->default('unknown');

            // Служебное
            $table->json('raw_data')->nullable();
            $table->timestamp('stats_updated_at')->nullable();
            $table->timestamp('last_scraped_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fighters');
    }
};
