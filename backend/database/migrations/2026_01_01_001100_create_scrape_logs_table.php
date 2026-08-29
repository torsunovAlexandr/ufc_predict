<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал обращений к внешним источникам. Решает три задачи:
 *  1) кэш страниц (повторный запрос не чаще, чем раз в page_ttl_hours);
 *  2) соблюдение лимита частоты запросов к источнику;
 *  3) учёт дневных квот API (The Odds API — 500/день, Google CSE — 100/день).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->index();
            $table->string('url', 1000);
            $table->char('url_hash', 40)->index();
            $table->char('content_hash', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->enum('status', ['ok', 'cached', 'failed', 'skipped'])->default('ok')->index();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->index();
            $table->timestamp('expires_at')->nullable();
            $table->longText('body')->nullable(); // тело ответа для кэша
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_logs');
    }
};
