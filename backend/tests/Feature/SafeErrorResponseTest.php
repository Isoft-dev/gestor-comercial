<?php

namespace Tests\Feature;

use App\Support\SafeErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PDOException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SafeErrorResponseTest extends TestCase
{
    public function test_duplicate_key_returns_friendly_json_even_with_debug(): void
    {
        config(['app.debug' => true]);
        Route::post('/api/__test-unique', function () {
            throw new UniqueConstraintViolationException(
                'mysql',
                'insert into TEC_USUARIO',
                [],
                new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'analisis@gmail.com' for key 'tec_usuario_usu_correo_unique'")
            );
        });

        $this->postJson('/api/__test-unique')
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'El correo ya está registrado.',
                'errors' => ['correo' => ['El correo ya está registrado.']],
            ])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertDontSee('SQLSTATE');
    }

    public function test_database_timeout_and_outage_hide_technical_details(): void
    {
        config(['app.debug' => true]);
        Route::post('/api/__test-timeout', function () {
            throw new FatalError('Maximum execution time of 30 seconds exceeded', 0, [
                'type' => E_ERROR,
                'message' => 'Maximum execution time of 30 seconds exceeded',
                'file' => __FILE__,
                'line' => __LINE__,
            ]);
        });
        Route::post('/api/__test-db', function () {
            throw new QueryException(
                'mysql',
                'select 1',
                [],
                new PDOException('SQLSTATE[HY000] [2002] No se puede establecer una conexión ya que el equipo de destino denegó expresamente dicha conexión')
            );
        });
        $this->postJson('/api/__test-timeout')
            ->assertStatus(504)
            ->assertJson(['message' => 'La operación tardó demasiado. Intente de nuevo.'])
            ->assertJsonMissingPath('trace')
            ->assertDontSee('Maximum execution');

        $this->postJson('/api/__test-db')
            ->assertStatus(503)
            ->assertJson(['message' => 'No hay conexión con la base de datos. Intente de nuevo en un momento.'])
            ->assertDontSee('SQLSTATE');

        $html = SafeErrorResponse::finalize(
            new Response('Whoops SQLSTATE Maximum execution', 500),
            new FatalError('Maximum execution time of 30 seconds exceeded', 0, [
                'type' => E_ERROR,
                'message' => 'Maximum execution time of 30 seconds exceeded',
                'file' => __FILE__,
                'line' => __LINE__,
            ]),
            Request::create('/operacion', 'GET')
        );

        $this->assertSame(504, $html->getStatusCode());
        $this->assertStringContainsString('tardó demasiado', $html->getContent());
        $this->assertStringNotContainsString('Whoops', $html->getContent());
        $this->assertStringNotContainsString('Maximum execution', $html->getContent());
    }

    public function test_controlled_abort_message_is_preserved(): void
    {
        Route::post('/api/__test-abort', function () {
            abort(422, 'Inventario insuficiente para uno o más productos.');
        });

        $this->postJson('/api/__test-abort')
            ->assertUnprocessable()
            ->assertJson(['message' => 'Inventario insuficiente para uno o más productos.']);
    }
}
