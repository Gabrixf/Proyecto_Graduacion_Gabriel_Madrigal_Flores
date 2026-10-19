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

    /** @return array<string, mixed> */
    private function vistaAsistencia(string $estadoHoy, array $pendientes = []): array
    {
        $registro = ['id_asistencia' => 30, 'fecha' => '2026-10-12', 'hora_entrada' => '07:58:00',
                     'hora_salida' => $estadoHoy === 'completo' ? '17:00:00' : '00:00:00',
                     'horas_trabajadas' => '9.03', 'salida_manual' => 0];
        $cal = (new \App\Helpers\CalendarioAsistencia())->construir(
            '2026-10', [$registro + ['fecha' => '2026-10-01', 'hora_salida' => '17:00:00']],
            ['2026-10-05' => 'Prueba'],
            new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-10-12')
        );
        return [
            'vinculado' => true, 'activo' => true, 'mes' => '2026-10', 'mesTexto' => 'Octubre 2026',
            'mesAnterior' => '2026-09', 'mesSiguiente' => null, 'calendario' => $cal,
            'hoy' => ['estado' => $estadoHoy, 'registro' => $estadoHoy === 'sin_entrada' ? null : $registro,
                      'pendientes' => $pendientes],
        ];
    }

    public function testMiAsistenciaSinEntradaOfreceMarcarEntrada(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('sin_entrada'));

        self::assertStringContainsString('action="/portal.asistencia.entrada"', $html);
        self::assertStringContainsString('Marcar entrada', $html);
        self::assertStringContainsString('id="calAsistencia"', $html);
        self::assertStringContainsString('lb-dia--asistio', $html);
        self::assertStringContainsString('lb-dia--feriado', $html);
        self::assertStringContainsString('Octubre 2026', $html);
    }

    public function testMiAsistenciaConEntradaOfreceMarcarSalida(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('con_entrada'));

        self::assertStringContainsString('action="/portal.asistencia.salida"', $html);
        self::assertStringContainsString('07:58', $html);
    }

    public function testMiAsistenciaCompletaNoMuestraBotones(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('completo'));

        self::assertStringNotContainsString('portal.asistencia.entrada', $html);
        self::assertStringNotContainsString('portal.asistencia.salida"', $html);
        self::assertStringContainsString('Jornada completa', $html);
    }

    public function testMiAsistenciaMuestraSalidasPendientes(): void
    {
        $pendiente = ['id_asistencia' => 21, 'fecha' => '2026-10-08', 'hora_entrada' => '08:05:00', 'corregible' => true];
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('sin_entrada', [$pendiente]));

        self::assertStringContainsString('Te faltó marcar la salida del 08/10/2026', $html);
        self::assertStringContainsString('action="/portal.asistencia.corregir"', $html);
        self::assertStringContainsString('name="hora_salida"', $html);
    }

    public function testPendienteNoCorregibleMandaARrhhSinFormulario(): void
    {
        $pendiente = ['id_asistencia' => 22, 'fecha' => '2026-09-29', 'hora_entrada' => '08:05:00', 'corregible' => false];
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('sin_entrada', [$pendiente]));

        self::assertStringContainsString('Te faltó marcar la salida del 29/09/2026', $html);
        self::assertStringContainsString('pida a RRHH que la corrija', $html);
        self::assertStringNotContainsString('name="hora_salida"', $html);
    }

    public function testEmpleadoInactivoNoVeFormularioDeSalidaPendiente(): void
    {
        $pendiente = ['id_asistencia' => 21, 'fecha' => '2026-10-08', 'hora_entrada' => '08:05:00', 'corregible' => true];
        $html = $this->render('portal/mi_asistencia.html.twig',
            ['activo' => false] + $this->vistaAsistencia('sin_entrada', [$pendiente]));

        self::assertStringNotContainsString('name="hora_salida"', $html);
        self::assertStringContainsString('pida a RRHH que la corrija', $html);
    }

    public function testMiAsistenciaSinVinculoNoMuestraCalendario(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', ['vinculado' => false]);

        self::assertStringNotContainsString('calAsistencia', $html);
        self::assertStringContainsString('no está vinculado', $html);
    }

    public function testAdminAsistenciaMuestraSinSalidaYEscritaPorElEmpleado(): void
    {
        $fila = ['id_asistencia' => 1, 'nombre' => 'Luis', 'apellidos' => 'Rojas', 'fecha' => '2026-10-08',
                 'hora_entrada' => '08:00:00', 'horas_trabajadas' => '0.00', 'feriado_nombre' => null];
        $html = $this->render('asistencia/index.html.twig', [
            'asistencias' => [
                $fila + ['hora_salida' => '00:00:00', 'salida_manual' => 0],
                $fila + ['hora_salida' => '17:00:00', 'salida_manual' => 1],
            ],
            'empleados' => [], 'periodos' => [],
        ]);

        self::assertStringContainsString('Sin salida', $html);
        self::assertStringContainsString('Escrita por el empleado', $html);
    }

    public function testMiAsistenciaMuestraElMensajeFlashUnaSolaVez(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig',
            $this->vistaAsistencia('con_entrada') + ['flashSuccess' => 'Entrada registrada a las 07:58.']);

        self::assertSame(1, substr_count($html, 'Entrada registrada a las 07:58.'));
    }

    public function testFormularioDeLiquidacionNoPideDiasDeVacacionesYMuestraMotivosConEtiqueta(): void
    {
        $html = $this->render('liquidacion/form.html.twig', [
            'titulo' => 'Nueva Liquidación', 'empleados' => [], 'datos' => [], 'errores' => [],
            'motivos' => \App\Services\LiquidacionService::MOTIVOS,
        ]);

        self::assertStringNotContainsString('name="dias_vacaciones"', $html);
        self::assertStringContainsString('value="renuncia_justificada"', $html);
        self::assertStringContainsString('Renuncia justificada (Art. 83)', $html);
        self::assertStringContainsString('Muerte del trabajador', $html);
    }

    public function testDetalleDeLiquidacionMuestraBaseYDiasYAvisaSiLaBaseFueEstimada(): void
    {
        $html = $this->render('liquidacion/detalle.html.twig', [
            'motivos' => \App\Services\LiquidacionService::MOTIVOS,
            'liquidacion' => [
                'id_liquidacion' => 3, 'nombre' => 'Andrés', 'apellidos' => 'Rojas', 'motivo' => 'despido_sin_causa',
                'fecha_salida' => '2026-08-31', 'fecha_ingreso' => '2020-01-01', 'salario_promedio' => 300000,
                'dias_preaviso' => 30, 'dias_cesantia' => 129, 'dias_vacaciones' => 10, 'base_estimada' => 1,
                'preaviso' => 300000, 'cesantia' => 1290000, 'vacaciones_pendientes' => 100000,
                'aguinaldo_proporcional' => 175000, 'total_liquidacion' => 1865000,
            ],
        ]);

        self::assertStringContainsString('Despido sin causa', $html);
        self::assertStringContainsString('129 días', $html);
        self::assertStringContainsString('₡300,000.00', $html);
        self::assertStringContainsString('se usó el salario del puesto', $html);
    }
}
