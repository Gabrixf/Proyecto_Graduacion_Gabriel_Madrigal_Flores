<?php

declare(strict_types=1);

namespace Tests\Templates;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renderiza pantallas completas (con el layout) para comprobar detalles de la interfaz.
 * url_for y base_path se reemplazan por funciones simples porque aquí no hay Slim.
 */
final class PantallasTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
        $this->twig->addFunction(new TwigFunction('url_for', fn (string $nombre, array $args = []) => '/' . $nombre));
        $this->twig->addFunction(new TwigFunction('base_path', fn () => ''));
    }

    /** @param array<string, mixed> $vars */
    private function render(string $plantilla, array $vars): string
    {
        $vars['session'] = ['usuario_id' => 3, 'usuario_nombre' => 'jperez', 'usuario_rol' => 'empleado'];
        return $this->twig->render($plantilla, $vars);
    }

    public function testMisColillasSinVinculoNoMuestraConteo(): void
    {
        $html = $this->render('portal/mis_colillas.html.twig', ['vinculado' => false]);

        self::assertStringNotContainsString('lb-page-header__conteo', $html);
    }

    public function testMisColillasVinculadoMuestraConteo(): void
    {
        $html = $this->render('portal/mis_colillas.html.twig', ['vinculado' => true, 'colillas' => []]);

        self::assertStringContainsString('· 0 registros', $html);
    }

    public function testMisSolicitudesSinVinculoNoMuestraConteo(): void
    {
        $html = $this->render('portal/mis_solicitudes.html.twig', ['vinculado' => false]);

        self::assertStringNotContainsString('lb-page-header__conteo', $html);
    }

    public function testDetalleEvaluacionTieneEliminarEnElEncabezado(): void
    {
        $html = $this->render('evaluaciones/detalle.html.twig', ['evaluacion' => [
            'id_evaluacion' => 7, 'nombre' => 'Juan', 'apellidos' => 'Pérez', 'periodo_evaluado' => 2026,
            'fecha_evaluacion' => '2026-09-30', 'puntaje_total' => 90, 'observaciones' => '', 'detalles' => [],
        ]]);

        $encabezado = substr($html, 0, (int) strpos($html, '</header>'));
        self::assertMatchesRegularExpression('/<button type="submit" form="form-eliminar-evaluacion"[^>]*btn-outline-danger/', $encabezado);
        self::assertMatchesRegularExpression('/<form id="form-eliminar-evaluacion"[^>]*onsubmit="return confirm/', $html);
    }

    public function testEnCelularLaBarraFijaNoTapaElCampoAlHacerScroll(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/app.css');
        preg_match('/@media \(max-width: 575\.98px\) \{(.*?)\n\}/s', $css, $movil);

        self::assertStringContainsString('scroll-padding-top', $movil[1] ?? '');
    }
}
