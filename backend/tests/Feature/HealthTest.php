<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Функциональная проверка единственного эндпоинта этапа 0.
 *
 * Набор `Feature` работает на SQLite в памяти (см. phpunit.xml), поэтому
 * тест доказывает не только то, что маршрут существует, но и то, что
 * соединение с базой из приложения действительно открывается.
 */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_endpoint_reports_a_reachable_database(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', true)
            ->assertJsonPath('php', PHP_VERSION)
            ->assertJsonStructure(['status', 'php', 'laravel', 'environment', 'database', 'time']);
    }

    #[Test]
    public function laravel_health_route_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * Проверяем именно то, ради чего добавлен ForceJsonResponse: запрос без
     * заголовка Accept всё равно получает JSON, а не HTML-страницу Laravel.
     */
    #[Test]
    public function api_answers_json_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function unknown_api_route_answers_json_not_html(): void
    {
        $response = $this->get('/api/there-is-no-such-route');

        $response->assertNotFound();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }
}
