<?php

declare(strict_types=1);

namespace Tests\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class UiMacrosTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
    }

    /** @param array<string, mixed> $vars */
    private function render(string $codigo, array $vars = []): string
    {
        $fuente = "{% import 'partials/ui.html.twig' as ui %}" . $codigo;
        return $this->twig->createTemplate($fuente)->render($vars);
    }

    /** @return array<string, array{string, string, string}> */
    public static function estados(): array
    {
        return [
            'activo'     => ['activo', 'verde', 'Activo'],
            'aprobada'   => ['aprobada', 'verde', 'Aprobada'],
            'pagado'     => ['pagado', 'verde', 'Pagado'],
            'pendiente'  => ['pendiente', 'ambar', 'Pendiente'],
            'borrador'   => ['borrador', 'ambar', 'Borrador'],
            'abierto'    => ['abierto', 'azul', 'Abierto'],
            'aprobado'   => ['aprobado', 'azul', 'Aprobado'],
            'calculado'  => ['calculado', 'azul', 'Calculado'],
            'rechazada'  => ['rechazada', 'rojo', 'Rechazada'],
            'inactivo'   => ['inactivo', 'gris', 'Inactivo'],
            'cerrado'    => ['cerrado', 'gris', 'Cerrado'],
            'finalizado' => ['finalizado', 'gris', 'Finalizado'],
        ];
    }

    #[DataProvider('estados')]
    public function testCadaEstadoSaleConSuTono(string $valor, string $tono, string $texto): void
    {
        $html = $this->render('{{ ui.estado(v) }}', ['v' => $valor]);

        self::assertStringContainsString("lb-estado--{$tono}", $html);
        self::assertStringContainsString($texto, $html);
    }

    public function testEstadoEnMayusculasUsaElMismoTono(): void
    {
        $html = $this->render("{{ ui.estado('APROBADA') }}");

        self::assertStringContainsString('lb-estado--verde', $html);
        self::assertStringContainsString('Aprobada', $html);
    }

    public function testEstadoDesconocidoSaleGrisConSuTexto(): void
    {
        $html = $this->render("{{ ui.estado('en_revision') }}");

        self::assertStringContainsString('lb-estado--gris', $html);
        self::assertStringContainsString('En_revision', $html);
    }

    public function testEstadoVacioMuestraGuion(): void
    {
        $html = $this->render('{{ ui.estado(v) }}', ['v' => null]);

        self::assertStringContainsString('lb-estado--gris', $html);
        self::assertStringContainsString('—', $html);
    }

    public function testEtiquetaConTonoInexistenteCaeEnGris(): void
    {
        $html = $this->render("{{ ui.etiqueta('CCSS', 'morado') }}");

        self::assertStringContainsString('lb-estado--gris', $html);
        self::assertStringContainsString('CCSS', $html);
    }

    public function testEtiquetaConTonoValido(): void
    {
        $html = $this->render("{{ ui.etiqueta('Con goce', 'verde') }}");

        self::assertStringContainsString('lb-estado--verde', $html);
    }

    public function testEncabezadoDibujaRutaTituloYConteo(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('Puestos', {ruta: [{texto: 'Administración'}, {texto: 'Puestos', url: '/p'}], conteo: 6}) }}"
        );

        self::assertStringContainsString('lb-page-header', $html);
        self::assertStringContainsString('Administración', $html);
        self::assertStringContainsString('<a href="/p">Puestos</a>', $html);
        self::assertStringContainsString('6 registros', $html);
    }

    public function testConteoUnoEsSingular(): void
    {
        $html = $this->render("{{ ui.encabezado_pagina('Puestos', {conteo: 1}) }}");

        self::assertStringContainsString('1 registro<', $html);
    }

    public function testConteoCeroSeMuestra(): void
    {
        $html = $this->render("{{ ui.encabezado_pagina('Puestos', {conteo: 0}) }}");

        self::assertStringContainsString('0 registros', $html);
    }

    public function testSinConteoNoMuestraRegistros(): void
    {
        $html = $this->render("{{ ui.encabezado_pagina('Nuevo puesto') }}");

        self::assertStringNotContainsString('registro', $html);
    }

    public function testAccionConFormDibujaBotonSubmit(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('Nuevo puesto', {acciones: [{texto: 'Guardar', form: 'formPuesto', icono: 'floppy'}]}) }}"
        );

        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('form="formPuesto"', $html);
        self::assertStringContainsString('bi-floppy', $html);
    }

    public function testAccionConservaSuId(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('Nueva solicitud', {acciones: [{texto: 'Enviar', form: 'form-solicitud', id: 'btnEnviar'}]}) }}"
        );

        self::assertStringContainsString('id="btnEnviar"', $html);
    }

    public function testAccionConUrlDibujaEnlace(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('Puestos', {acciones: [{texto: 'Nuevo', url: '/p/crear'}]}) }}"
        );

        self::assertStringContainsString('<a href="/p/crear"', $html);
        self::assertStringContainsString('btn-primary', $html);
    }

    public function testAccionSecundariaYPeligro(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('X', {acciones: [{texto: 'Cancelar', url: '/x', tipo: 'secundario'}, {texto: 'Eliminar', url: '/y', tipo: 'peligro'}]}) }}"
        );

        self::assertStringContainsString('btn-outline-secondary', $html);
        self::assertStringContainsString('btn-outline-danger', $html);
    }

    public function testAccionConAtributosAbreModal(): void
    {
        $html = $this->render(
            "{{ ui.encabezado_pagina('Nóminas', {acciones: [{texto: 'Generar', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalGenerar'}}]}) }}"
        );

        self::assertStringContainsString('type="button"', $html);
        self::assertStringContainsString('data-bs-toggle="modal"', $html);
        self::assertStringContainsString('data-bs-target="#modalGenerar"', $html);
    }

    public function testEncabezadoConEstadoMuestraEtiqueta(): void
    {
        $html = $this->render("{{ ui.encabezado_pagina('Nómina', {estado: 'borrador'}) }}");

        self::assertStringContainsString('lb-estado--ambar', $html);
        self::assertStringContainsString('Borrador', $html);
    }

    public function testTituloSeEscapa(): void
    {
        $html = $this->render('{{ ui.encabezado_pagina(t, {ruta: [{texto: t}]}) }}', ['t' => 'A <b>"x"</b>']);

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testSeccionDibujaTituloYCuerpo(): void
    {
        $html = $this->twig->createTemplate(
            "{% embed 'partials/seccion.html.twig' with {titulo: 'Datos personales'} %}{% block cuerpo %}<p>campos</p>{% endblock %}{% endembed %}"
        )->render([]);

        self::assertStringContainsString('lb-seccion', $html);
        self::assertStringContainsString('Datos personales', $html);
        self::assertStringContainsString('<p>campos</p>', $html);
    }

    public function testSeccionSinTituloNoDibujaEncabezado(): void
    {
        $html = $this->twig->createTemplate(
            "{% embed 'partials/seccion.html.twig' %}{% block cuerpo %}<p>x</p>{% endblock %}{% endembed %}"
        )->render([]);

        self::assertStringNotContainsString('lb-seccion__titulo', $html);
    }

    /** @return array<string, array{int, string, string}> */
    public static function calificaciones(): array
    {
        return [
            'excelente'  => [95, 'verde', 'Excelente'],
            'muy bueno'  => [85, 'azul', 'Muy bueno'],
            'bueno'      => [75, 'ambar', 'Bueno'],
            'regular'    => [60, 'ambar', 'Regular'],
            'deficiente' => [59, 'rojo', 'Deficiente'],
        ];
    }

    #[DataProvider('calificaciones')]
    public function testEvalBadgeUsaEtiquetaSuavePorRango(int $puntaje, string $tono, string $texto): void
    {
        $html = $this->twig->createTemplate(
            "{% include 'partials/eval_badge.html.twig' with {puntaje: p} only %}"
        )->render(['p' => $puntaje]);

        self::assertStringContainsString('lb-estado--' . $tono, $html);
        self::assertStringContainsString($texto, $html);
        self::assertStringNotContainsString('badge', $html);
    }
}
