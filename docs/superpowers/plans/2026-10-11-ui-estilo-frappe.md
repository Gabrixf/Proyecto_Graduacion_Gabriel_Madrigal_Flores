# Estilo visual con el orden de Frappe — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a todas las pantallas el orden visual de Frappe HRMS (encabezado fijo con ruta, conteo y acciones; etiquetas de estado suaves; tablas planas; secciones planas) manteniendo los colores navy y rojo de Lubrimotos.

**Architecture:** Cuatro componentes Twig compartidos (`ui.encabezado_pagina`, `ui.estado`, `ui.etiqueta` en `partials/ui.html.twig`, y la plantilla embebible `partials/seccion.html.twig`) más clases nuevas en `app.css`. El layout gana un bloque `encabezado` dentro de la barra superior fija; cada plantilla migrada lo sobrescribe. Se migra por grupos de módulos, un commit por grupo, con revisión manual entre grupos.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3.25 (`slim/twig-view`), Bootstrap 5 + Bootstrap Icons, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-11-ui-estilo-frappe-design.md`

## Global Constraints

- Stack bloqueado: sin npm, Node, React, Vue ni frameworks JS; solo Twig, Bootstrap 5 y `public/assets/css/app.css` (CLAUDE.md).
- Colores: no se cambia ningún token existente de `:root` (`--lb-navy: #003876`, `--lb-red: #E31837`, etc.). Botón principal = `btn-primary` (rojo, ya definido en `app.css`).
- No se tocan controllers, servicios, repositorios, rutas ni `database/schema.sql`. El conteo sale de las variables que cada vista ya recibe.
- Los `id` que usa JavaScript se conservan: `formPuesto`, `btnEnviar`, `campoDias`, `saldoBarra*`, `formEliminar`, `modalEliminar`, `formCalcular`, `formGenerar`, `formRenovar`, `formResolver`, `formCancelar`, `formPagar`, `lbBackBtn`, `lbSidebarToggle`.
- Mapa de estados (único, en `ui.estado`): verde = `activo`, `aprobada`, `pagado`; ámbar = `pendiente`, `borrador`; azul = `abierto`, `aprobado`, `calculado`; rojo = `rechazada`; gris = `inactivo`, `cerrado`, `finalizado`; cualquier otro valor = gris con su texto.
- Rutas del encabezado: usan los grupos actuales del sidebar — Plataforma (Dashboard, Empleados, Reportes), Operaciones (Solicitudes, Asistencia, Horas Extra, Vacaciones, Incapacidades, Permisos), Nómina (Nóminas, Aguinaldo, Liquidaciones, Evaluaciones), Administración (Puestos, Períodos de Pago, Feriados, Contratos, Usuarios), Mi cuenta (portal del empleado).
- Rama: `feature/ui-estilo-frappe` desde `develop`; un commit por grupo; mensajes en el estilo del repo (`feat(ui): …`), terminando con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Si `.env` tiene `APP_DEBUG=false`, Twig usa caché (`config/dependencies.php`): borrar la carpeta de caché configurada en `settings['twig']['cache_path']` antes de revisar en el navegador.

## Review Focus

1. **Formulario con errores de validación (respuesta 422):** al re-renderizar, el encabezado con Guardar y Cancelar debe seguir apareciendo y el `alert-danger` con los errores debe verse arriba del formulario. → Paso de verificación manual en Tasks 3–8.
2. **Lista vacía:** con 0 registros el encabezado muestra `· 0 registros` y el mensaje `alert-info` de "no hay registros" sigue apareciendo. → Test `testConteoCeroSeMuestra` (Task 1) + verificación manual.
3. **Estado nulo o vacío en la BD** (registro viejo sin estado): `ui.estado` no debe romper ni quedar vacío; muestra `—` en gris. → Test `testEstadoVacioMuestraGuion` (Task 1).
4. **Pantalla de celular (375 px):** el encabezado no desborda; título arriba, botones abajo; el chip de usuario se oculta. → Verificación manual en Task 2 y en cada grupo.
5. **Texto con caracteres especiales en ruta o título** (ej. un nombre con `<` o comillas): se escapa, no rompe el HTML. → Test `testTituloSeEscapa` (Task 1).

---

### Task 0: Rama de trabajo

**Precondición (la verifica Gabriel, no el ejecutor):** `feature/vacaciones-antiguedad` (vacaciones por antigüedad + eliminación de `comision`) está probada en XAMPP, commiteada e integrada a `develop`. Si no lo está, **detenerse**: no crear la rama.

- [ ] **Step 1: Verificar que el árbol está limpio y `develop` al día**

Run: `git status --short` → Expected: sin líneas (salvo archivos ignorados).
Run: `git fetch origin && git checkout develop && git pull --ff-only`

- [ ] **Step 2: Crear la rama**

Run: `git checkout -b feature/ui-estilo-frappe`

- [ ] **Step 3: Confirmar la suite en verde antes de empezar**

Run: `vendor/bin/phpunit`
Expected: `OK` (141 tests o más).

---

### Task 1: Componentes Twig + pruebas

**Files:**
- Create: `templates/partials/ui.html.twig`
- Create: `templates/partials/seccion.html.twig`
- Test: `tests/Templates/UiMacrosTest.php`

**Interfaces:**
- Produces:
  - `ui.etiqueta(texto: string, tono: 'verde'|'ambar'|'azul'|'rojo'|'gris' = 'gris')` → `<span class="lb-estado lb-estado--{tono}">`
  - `ui.estado(valor: ?string)` → `ui.etiqueta(Valor capitalizado o '—', tono del mapa)`
  - `ui.encabezado_pagina(titulo: string, opciones: {ruta?: [{texto, url?}], conteo?: int, estado?: string, acciones?: [{texto, url?, icono?, tipo?: 'primario'|'secundario'|'peligro', form?, id?, atributos?: {nombre: valor}}]})`
  - `{% embed 'partials/seccion.html.twig' with {titulo: string} %}{% block cuerpo %}…{% endblock %}{% endembed %}`

- [ ] **Step 1: Escribir las pruebas (fallan porque los archivos no existen)**

```php
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
}
```

- [ ] **Step 2: Correr las pruebas y verificar que fallan**

Run: `vendor/bin/phpunit tests/Templates/UiMacrosTest.php`
Expected: errores `Unable to find template "partials/ui.html.twig"`.

- [ ] **Step 3: Crear `templates/partials/ui.html.twig`**

```twig
{#
 # Componentes de UI compartidos — orden visual de Frappe HRMS con los colores de Lubrimotos.
 # Uso en cada plantilla:  {% import 'partials/ui.html.twig' as ui %}
 # Las URL se resuelven en la plantilla (url_for) y se pasan como parámetro.
 #}

{# Etiqueta suave con punto. tono: verde | ambar | azul | rojo | gris (cualquier otro → gris). #}
{% macro etiqueta(texto, tono = 'gris') %}
    {%- set tono = tono in ['verde', 'ambar', 'azul', 'rojo', 'gris'] ? tono : 'gris' -%}
    <span class="lb-estado lb-estado--{{ tono }}"><span class="lb-estado__punto" aria-hidden="true"></span>{{ texto }}</span>
{%- endmacro %}

{# Estado de un registro tal como viene de la BD (ej. 'aprobada'). Mapa único de tonos. #}
{% macro estado(valor) %}
    {%- import _self as ui -%}
    {%- set mapa = {
        activo: 'verde', aprobada: 'verde', pagado: 'verde',
        pendiente: 'ambar', borrador: 'ambar',
        abierto: 'azul', aprobado: 'azul', calculado: 'azul',
        rechazada: 'rojo',
        inactivo: 'gris', cerrado: 'gris', finalizado: 'gris'
    } -%}
    {%- set clave = (valor ?? '')|lower -%}
    {{- ui.etiqueta(clave is empty ? '—' : clave|capitalize, mapa[clave] ?? 'gris') -}}
{%- endmacro %}

{# Encabezado de página (vive en la barra superior fija del layout). #}
{% macro encabezado_pagina(titulo, opciones = {}) %}
    {%- import _self as ui -%}
    {%- set ruta = opciones.ruta ?? [] -%}
    {%- set acciones = opciones.acciones ?? [] -%}
    <div class="lb-page-header">
        <div class="lb-page-header__texto">
            {% if ruta is not empty %}
            <nav class="lb-page-header__ruta" aria-label="Ruta">
                {% for paso in ruta %}
                    {% if not loop.first %}<span class="lb-page-header__sep" aria-hidden="true">›</span>{% endif %}
                    {% if paso.url ?? false %}<a href="{{ paso.url }}">{{ paso.texto }}</a>{% else %}<span>{{ paso.texto }}</span>{% endif %}
                {% endfor %}
            </nav>
            {% endif %}
            <h1 class="lb-page-header__titulo">
                <span>{{ titulo }}</span>
                {% if opciones.conteo is defined and opciones.conteo is not null %}
                <span class="lb-page-header__conteo">· {{ opciones.conteo }} {{ opciones.conteo == 1 ? 'registro' : 'registros' }}</span>
                {% endif %}
                {% if opciones.estado ?? null %}{{ ui.estado(opciones.estado) }}{% endif %}
            </h1>
        </div>
        {% if acciones is not empty %}
        <div class="lb-page-header__acciones">
            {% for a in acciones %}
                {% set tipo = a.tipo ?? 'primario' %}
                {% set clase = 'btn btn-sm ' ~ (tipo == 'secundario' ? 'btn-outline-secondary' : (tipo == 'peligro' ? 'btn-outline-danger' : 'btn-primary')) %}
                {% set extra %}{% if a.id ?? false %} id="{{ a.id }}"{% endif %}{% for nombre, v in a.atributos ?? {} %} {{ nombre|e('html_attr') }}="{{ v }}"{% endfor %}{% endset %}
                {% set icono %}{% if a.icono ?? false %}<i class="bi bi-{{ a.icono }} me-1" aria-hidden="true"></i>{% endif %}{% endset %}
                {% if a.form ?? false %}
                    <button type="submit" form="{{ a.form }}" class="{{ clase }}"{{ extra }}>{{ icono }}{{ a.texto }}</button>
                {% elseif a.url ?? false %}
                    <a href="{{ a.url }}" class="{{ clase }}"{{ extra }}>{{ icono }}{{ a.texto }}</a>
                {% else %}
                    <button type="button" class="{{ clase }}"{{ extra }}>{{ icono }}{{ a.texto }}</button>
                {% endif %}
            {% endfor %}
        </div>
        {% endif %}
    </div>
{%- endmacro %}
```

- [ ] **Step 4: Crear `templates/partials/seccion.html.twig`**

```twig
{#
 # Tarjeta plana con título para agrupar campos.
 # Uso: {% embed 'partials/seccion.html.twig' with {titulo: 'Datos personales'} %}
 #          {% block cuerpo %} … campos … {% endblock %}
 #      {% endembed %}
 #}
<section class="lb-seccion">
    {% if titulo ?? false %}<h2 class="lb-seccion__titulo">{{ titulo }}</h2>{% endif %}
    {% block cuerpo %}{% endblock %}
</section>
```

- [ ] **Step 5: Correr las pruebas y verificar que pasan**

Run: `vendor/bin/phpunit tests/Templates/UiMacrosTest.php`
Expected: `OK (30 tests, …)` (12 estados del data provider + 18 tests).
Si `testAccionConUrlDibujaEnlace` falla por espacios, revisar que no haya espacio entre `href="…"` y `class`; el assert solo exige `<a href="/p/crear"`.

- [ ] **Step 6: Suite completa**

Run: `vendor/bin/phpunit` → Expected: `OK`.

(No se commitea todavía: el commit del grupo 1 se hace en Task 3, con el piloto funcionando.)

---

### Task 2: Layout y CSS

**Files:**
- Modify: `templates/layouts/base.html.twig:116-127` (barra superior)
- Modify: `public/assets/css/app.css` (agregar al final, antes del bloque `@media print`)

**Interfaces:**
- Consumes: nada.
- Produces: bloque Twig `encabezado` en el layout; clases `.lb-page-header*`, `.lb-estado*`, `.lb-seccion*`, `.lb-tabla*`, `.lb-topbar-left`.

- [ ] **Step 1: Envolver el título de la barra superior en el bloque `encabezado`**

En `templates/layouts/base.html.twig`, reemplazar:

```twig
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm lb-burger d-lg-none" id="lbSidebarToggle" type="button">
                    <i class="bi bi-list"></i>
                </button>
                <button class="btn btn-sm lb-back-btn" id="lbBackBtn" onclick="history.back()" title="Volver" type="button">
                    <i class="bi bi-arrow-left"></i>
                </button>
                <div>
                    <div class="lb-crumb">{% block breadcrumb %}Inicio{% endblock %}</div>
                    <h1 class="lb-title">{{ block('titulo') }}</h1>
                </div>
            </div>
```

por:

```twig
            <div class="lb-topbar-left">
                <button class="btn btn-sm lb-burger d-lg-none" id="lbSidebarToggle" type="button">
                    <i class="bi bi-list"></i>
                </button>
                <button class="btn btn-sm lb-back-btn" id="lbBackBtn" onclick="history.back()" title="Volver" type="button">
                    <i class="bi bi-arrow-left"></i>
                </button>
                {% block encabezado %}
                <div>
                    <div class="lb-crumb">{% block breadcrumb %}Inicio{% endblock %}</div>
                    <h1 class="lb-title">{{ block('titulo') }}</h1>
                </div>
                {% endblock %}
            </div>
```

- [ ] **Step 2: Agregar los estilos a `public/assets/css/app.css`**

Insertar justo antes de la línea `/* ── Impresión de reportes y colillas ───────────────────── */`:

```css
/* ── Orden visual Frappe (subproyecto 1) ─────────────────── */
.lb-topbar-left { display: flex; align-items: center; gap: .5rem; flex: 1; min-width: 0; }

/* Encabezado de página: ruta + título + conteo + acciones (dentro de la barra fija) */
.lb-page-header {
    flex: 1; min-width: 0;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.lb-page-header__texto { min-width: 0; }
.lb-page-header__ruta { font-size: .76rem; color: var(--lb-muted); display: flex; flex-wrap: wrap; gap: .35rem; }
.lb-page-header__ruta a { color: var(--lb-muted); text-decoration: none; }
.lb-page-header__ruta a:hover { color: var(--lb-navy); text-decoration: underline; }
.lb-page-header__titulo {
    font-size: 1.2rem; font-weight: 700; margin: 0; color: var(--lb-text);
    display: flex; align-items: center; flex-wrap: wrap; gap: .5rem;
}
.lb-page-header__conteo { font-size: .85rem; font-weight: 400; color: var(--lb-muted); }
.lb-page-header__acciones { display: flex; gap: .5rem; flex: none; }

/* Etiquetas suaves (estados y tipos) */
.lb-estado {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .18rem .6rem; border-radius: 999px;
    font-size: .75rem; font-weight: 600; line-height: 1.3; white-space: nowrap;
}
.lb-estado__punto { width: 6px; height: 6px; border-radius: 50%; flex: none; background: currentColor; }
.lb-estado--verde { background: #e7f6ee; color: #0f7a4a; }
.lb-estado--verde .lb-estado__punto { background: var(--lb-green); }
.lb-estado--ambar { background: #fef3e2; color: #92400e; }
.lb-estado--ambar .lb-estado__punto { background: var(--lb-amber); }
.lb-estado--azul  { background: #e6f0ff; color: var(--lb-navy); }
.lb-estado--rojo  { background: #ffe6ec; color: var(--lb-red-700); }
.lb-estado--rojo  .lb-estado__punto { background: var(--lb-red); }
.lb-estado--gris  { background: #f1f1f3; color: #4b5563; }
.lb-estado--gris  .lb-estado__punto { background: #9ca3af; }

/* Secciones planas de formulario */
.lb-seccion {
    background: var(--lb-surface); border: 1px solid var(--lb-border);
    border-radius: 8px; padding: 1rem 1.15rem; margin-bottom: 1rem;
}
.lb-seccion__titulo { font-size: .95rem; font-weight: 700; color: var(--lb-text); margin: 0 0 .8rem; }

/* Tablas planas */
.lb-tabla { background: var(--lb-surface); border: 1px solid var(--lb-border); border-radius: 8px; overflow: hidden; }
.lb-tabla .table { margin-bottom: 0; }
.lb-tabla .table thead th { background: #fafafa; }
.lb-tabla__pie { font-size: .8rem; color: var(--lb-muted); padding: .55rem .9rem; border-top: 1px solid var(--lb-border); }

/* Celular: título arriba, botones abajo a todo el ancho; sin chip de usuario */
@media (max-width: 575.98px) {
    .lb-topbar { padding: .65rem .9rem; }
    .lb-page-header { flex-direction: column; align-items: stretch; gap: .5rem; }
    .lb-page-header__acciones { width: 100%; }
    .lb-page-header__acciones .btn { flex: 1; }
    .lb-topbar-right { display: none; }
}
```

- [ ] **Step 3: Verificar que nada cambió todavía para las pantallas no migradas**

Run: `vendor/bin/phpunit` → Expected: `OK`.
Manual (XAMPP): abrir `/dashboard` y `/mantenimientos/puestos`. Expected: se ven igual que antes (ruta y título en la barra superior).

---

### Task 3: Grupo 1 — Piloto Puestos

**Files:**
- Modify: `templates/puestos/index.html.twig`
- Modify: `templates/puestos/form.html.twig`

**Interfaces:**
- Consumes: `ui.encabezado_pagina`, `seccion.html.twig`, clases `.lb-tabla*` (Tasks 1–2).

- [ ] **Step 1: Migrar `templates/puestos/index.html.twig`**

1. Debajo de `{% extends 'layouts/base.html.twig' %}` agregar `{% import 'partials/ui.html.twig' as ui %}`.
2. Reemplazar todo el bloque `{% block breadcrumb %} … {% endblock %}` por:

```twig
{% block encabezado %}
{{ ui.encabezado_pagina('Puestos de trabajo', {
    ruta: [{texto: 'Administración'}, {texto: 'Puestos'}],
    conteo: puestos|length,
    acciones: [{texto: 'Nuevo puesto', icono: 'plus-lg', url: url_for('puestos.create')}]
}) }}
{% endblock %}
```

3. Dentro de `{% block contenido %}`, borrar el `<div class="d-flex justify-content-between align-items-center mb-4"> … </div>` del título (líneas con `<h2>` y el botón "Nuevo Puesto").
4. Reemplazar el envoltorio de la tabla. Antes:

```twig
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
```

Después:

```twig
<div class="lb-tabla">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
```

5. Reemplazar el pie. Antes:

```twig
    <div class="card-footer text-muted small">
        Total: {{ puestos|length }} puesto(s) registrado(s).
    </div>
```

Después:

```twig
    <div class="lb-tabla__pie">{{ puestos|length }} {{ puestos|length == 1 ? 'puesto' : 'puestos' }}</div>
```

El formulario de búsqueda (`GET q`), el `alert-info` de lista vacía, el modal y el bloque `scripts` no cambian.

- [ ] **Step 2: Migrar `templates/puestos/form.html.twig`**

1. Debajo de `{% extends %}` agregar `{% import 'partials/ui.html.twig' as ui %}`.
2. Reemplazar el bloque `breadcrumb` por:

```twig
{% block encabezado %}
{{ ui.encabezado_pagina(accion == 'crear' ? 'Nuevo puesto' : 'Editar puesto', {
    ruta: [{texto: 'Administración'}, {texto: 'Puestos', url: url_for('puestos.index')}, {texto: accion == 'crear' ? 'Nuevo' : 'Editar'}],
    acciones: [
        {texto: 'Cancelar', tipo: 'secundario', url: url_for('puestos.index')},
        {texto: accion == 'crear' ? 'Guardar puesto' : 'Actualizar puesto', icono: 'floppy', form: 'formPuesto'}
    ]
}) }}
{% endblock %}
```

3. Reemplazar el bloque `contenido` completo por:

```twig
{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">

        {% if errores|length > 0 %}
        <div class="alert alert-danger">
            <ul class="mb-0">
                {% for error in errores %}<li>{{ error }}</li>{% endfor %}
            </ul>
        </div>
        {% endif %}

        {% if accion == 'crear' %}
            {% set form_action = url_for('puestos.store') %}
        {% else %}
            {% set form_action = url_for('puestos.update', {'id': puesto.id_puesto}) %}
        {% endif %}

        <form method="POST" action="{{ form_action }}" novalidate id="formPuesto">
            {% embed 'partials/seccion.html.twig' with {titulo: 'Datos del puesto'} %}
            {% block cuerpo %}
                <div class="mb-3">
                    <label for="nombre" class="form-label fw-semibold">Nombre del puesto <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nombre" name="nombre" maxlength="100" required
                           placeholder="Ej: Mecánico, Administrador, Vendedor…" value="{{ puesto.nombre ?? '' }}">
                    <div class="form-text">Máximo 100 caracteres. Debe ser único.</div>
                </div>

                <div class="mb-3">
                    <label for="salario_base" class="form-label fw-semibold">Salario base mensual (₡) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">₡</span>
                        <input type="number" class="form-control" id="salario_base" name="salario_base" min="1" step="0.01" required
                               placeholder="0.00" value="{{ puesto.salario_base ?? '' }}">
                    </div>
                    <div class="form-text">Monto mensual en colones costarricenses.</div>
                </div>

                <div class="mb-0">
                    <label for="descripcion" class="form-label fw-semibold">Descripción <span class="text-muted small">(opcional)</span></label>
                    <textarea class="form-control" id="descripcion" name="descripcion" rows="3" maxlength="255"
                              placeholder="Funciones principales del puesto…">{{ puesto.descripcion ?? '' }}</textarea>
                    <div class="form-text">Máximo 255 caracteres.</div>
                </div>
            {% endblock %}
            {% endembed %}
        </form>

    </div>
</div>
{% endblock %}
```

Ojo: dentro de `{% embed %}` las variables del template padre (`puesto`) **sí** están disponibles porque `embed` hereda el contexto. El bloque `scripts` (validación con `formPuesto`) no cambia: el evento `submit` del form se dispara igual cuando el botón está fuera del form con `form="formPuesto"`.

- [ ] **Step 3: Suite**

Run: `vendor/bin/phpunit` → Expected: `OK`.

- [ ] **Step 4: Verificación manual (Gabriel, XAMPP)**

1. `/mantenimientos/puestos`: encabezado "Puestos de trabajo · N registros" con ruta "Administración › Puestos" y botón rojo "Nuevo puesto" en la barra superior; tabla plana con borde fino y pie con el conteo.
2. Crear un puesto con nombre vacío y salario 0 → la validación JS (`alert`) sigue saltando al presionar "Guardar puesto" de arriba.
3. Crear con nombre repetido (pasa el JS, falla en servidor 422) → la página vuelve con el `alert-danger` y el encabezado con Guardar/Cancelar.
4. Crear y editar un puesto válido → guarda y redirige con el mensaje flash.
5. Hacer scroll en el formulario → el botón Guardar sigue visible arriba.
6. DevTools a 375 px → título arriba, botones abajo a todo el ancho, sin desborde.

**Checkpoint:** no seguir al grupo 2 sin el visto bueno de Gabriel sobre el piloto.

- [ ] **Step 5: Commit del grupo 1**

```bash
git add templates/partials/ui.html.twig templates/partials/seccion.html.twig tests/Templates/UiMacrosTest.php templates/layouts/base.html.twig public/assets/css/app.css templates/puestos/index.html.twig templates/puestos/form.html.twig
git commit -m "feat(ui): componentes de encabezado, estados y secciones; piloto en Puestos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Recetas para los grupos 2–6

Las Tasks 4–8 aplican estas recetas a cada plantilla con los parámetros de su tabla. Cada receta muestra el código exacto de antes y después.

### Receta L — plantilla de lista (`index`)

1. Agregar `{% import 'partials/ui.html.twig' as ui %}` debajo de `{% extends %}`.
2. Borrar el bloque `{% block breadcrumb %}…{% endblock %}` y agregar:

```twig
{% block encabezado %}
{{ ui.encabezado_pagina('<TÍTULO>', {
    ruta: [{texto: '<GRUPO>'}, {texto: '<MÓDULO>'}],
    conteo: <LISTA>|length,
    acciones: [<ACCIONES>]
}) }}
{% endblock %}
```

3. Borrar del bloque `contenido` el `<div class="d-flex justify-content-between align-items-center …">` que contiene el `<h2>` y el botón de crear (el botón ya está en el encabezado). Si el `<div>` contiene además otros controles (ej. filtros de estado), conservar esos controles y borrar solo el `<h2>` y el botón de crear.
4. Tabla: `<div class="card shadow-sm"><div class="card-body p-0">` → `<div class="lb-tabla"><div class="table-responsive">`; en el `<table>` quitar `table-striped`; `<thead class="table-dark">` → `<thead>`.
5. Pie: `<div class="card-footer text-muted small">Total: … </div>` → `<div class="lb-tabla__pie">{{ <LISTA>|length }} <SUSTANTIVO_PLURAL></div>`.
6. Estados y etiquetas: reemplazar cada `<span class="badge …">` según la columna "Etiquetas" de la tabla del grupo.

### Receta F — plantilla de formulario (`form`)

1. Import de `ui` como en la receta L.
2. Borrar el bloque `breadcrumb` y agregar:

```twig
{% block encabezado %}
{{ ui.encabezado_pagina(<TÍTULO_EXPR>, {
    ruta: [{texto: '<GRUPO>'}, {texto: '<MÓDULO>', url: url_for('<RUTA_INDEX>')}, {texto: <PASO_EXPR>}],
    acciones: [
        {texto: 'Cancelar', tipo: 'secundario', url: url_for('<RUTA_INDEX>')},
        {texto: <GUARDAR_EXPR>, icono: 'floppy', form: '<FORM_ID>'}
    ]
}) }}
{% endblock %}
```

3. En el bloque `contenido`, reemplazar el envoltorio de tarjeta. Antes:

```twig
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-… me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">
                … errores … form …
            </div>
        </div>
```

Después: quitar esos tres `<div>` (y sus cierres) y el `<h5>`, dejando los errores y el `<form>` directamente dentro de la columna.
4. En el `<form …>` agregar `id="<FORM_ID>"` si no tiene `id`.
5. Si el form tiene `<fieldset>`/`<legend>` (formularios largos), cada `<fieldset class="mb-4"><legend …>Título</legend> … </fieldset>` pasa a:

```twig
{% embed 'partials/seccion.html.twig' with {titulo: 'Título'} %}
{% block cuerpo %} … mismos campos … {% endblock %}
{% endembed %}
```

Si no tiene fieldsets, envolver todos los campos en un único `embed` con el título de la columna "Sección única" de la tabla.
6. Borrar el `<div class="d-flex justify-content-between …">` del final con los botones Cancelar/Guardar (ahora están arriba). **Excepción:** si el botón submit tiene `id` (ej. `btnEnviar`), ese `id` pasa a la acción del encabezado (`id: 'btnEnviar'`).

### Receta D — vista de detalle (`detalle`) y pantallas especiales

1. Import de `ui`; borrar `breadcrumb`; `encabezado` con título, ruta, `estado` (si el registro tiene estado) y como acciones los botones que hoy están en la tarjeta de cabecera (Editar, Aprobar, Pagar…). Botones que abren un modal usan `atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#<id del modal>'}`.
2. Las tarjetas internas `card`/`card-fo` con tablas pasan a `.lb-tabla` (receta L paso 4); las de datos pasan a `seccion.html.twig`.
3. Estados según la columna "Etiquetas".

---

### Task 4: Grupo 2 — Mantenimientos

**Files:** Modify: `templates/periodos/index.html.twig`, `templates/periodos/form.html.twig`, `templates/feriados/index.html.twig`, `templates/feriados/form.html.twig`, `templates/contratos/index.html.twig`, `templates/contratos/form.html.twig`, `templates/usuarios/index.html.twig`, `templates/usuarios/form.html.twig`, `templates/usuarios/password.html.twig`

**Interfaces:** Consumes `ui.*` y `seccion.html.twig` (Task 1).

- [ ] **Step 1: Listas (receta L)**

| Plantilla | Título | Ruta | Lista | Acciones | Pie | Etiquetas |
|---|---|---|---|---|---|---|
| `periodos/index` | `'Períodos de pago'` | Administración › Períodos de pago | `periodos` | `{texto: 'Nuevo período', icono: 'plus-lg', url: url_for('periodos.create')}` | `períodos` | `<span class="badge bg-success">Abierto</span>` / `…bg-secondary">Cerrado` → `{{ ui.estado(p.estado) }}` (reemplaza todo el `if/else`) |
| `feriados/index` | `'Feriados nacionales'` | Administración › Feriados | `feriados` | `{texto: 'Nuevo feriado', icono: 'plus-lg', url: url_for('feriados.create')}` | `feriados` | `bg-danger "Obligatorio con pago"` → `{{ ui.etiqueta('Obligatorio con pago', 'rojo') }}`; `bg-secondary "No obligatorio con pago"` → `{{ ui.etiqueta('No obligatorio con pago', 'gris') }}` |
| `contratos/index` | `'Contratos'` | Administración › Contratos | `contratos` | `{texto: 'Nuevo contrato', icono: 'plus-lg', url: url_for('contratos.create')}` | `contratos` | `<span class="badge {{ c.estado == 'activo' ? 'bg-success' : 'bg-secondary' }}">…</span>` → `{{ ui.estado(c.estado) }}` |
| `usuarios/index` | `'Usuarios del sistema'` | Administración › Usuarios | `usuarios` | `{texto: 'Nuevo usuario', icono: 'plus-lg', url: url_for('usuarios.create')}` | `usuarios` | Rol: `bg-dark "Super Admin"` → `ui.etiqueta('Super Admin', 'azul')`, `bg-danger "Admin"` → `ui.etiqueta('Admin', 'rojo')`, `bg-secondary "Empleado"` → `ui.etiqueta('Empleado', 'gris')`. Estado: el `if u.activo … else …` completo → `{{ ui.estado(u.activo ? 'activo' : 'inactivo') }}` |

- [ ] **Step 2: Formularios (receta F)**

| Plantilla | Título | Ruta (paso) | Ruta index | Guardar | Form id | Sección única |
|---|---|---|---|---|---|---|
| `periodos/form` | `accion == 'crear' ? 'Nuevo período' : 'Editar período'` | Administración › Períodos de pago › `accion == 'crear' ? 'Nuevo' : 'Editar'` | `periodos.index` | `accion == 'crear' ? 'Guardar período' : 'Actualizar período'` | `form-periodo` | `'Datos del período'` |
| `feriados/form` | `accion == 'crear' ? 'Nuevo feriado' : 'Editar feriado'` | Administración › Feriados › ídem | `feriados.index` | `accion == 'crear' ? 'Guardar feriado' : 'Actualizar feriado'` | `form-feriado` | `'Datos del feriado'` |
| `contratos/form` | `accion == 'crear' ? 'Nuevo contrato' : 'Editar contrato'` | Administración › Contratos › ídem | `contratos.index` | `accion == 'crear' ? 'Guardar contrato' : 'Actualizar contrato'` | `form-contrato` | `'Datos del contrato'` |
| `usuarios/form` | `accion == 'crear' ? 'Nuevo usuario' : 'Editar usuario'` | Administración › Usuarios › ídem | `usuarios.index` | `accion == 'crear' ? 'Guardar usuario' : 'Actualizar usuario'` | `form-usuario` | `'Datos de la cuenta'` |
| `usuarios/password` | `'Restablecer contraseña'` | Administración › Usuarios › `'Restablecer contraseña'` | `usuarios.index` | `'Restablecer contraseña'` | `form-password` | `'Nueva contraseña'` (aquí la tarjeta tenía `card-header bg-warning`; se quita igual) |

- [ ] **Step 3: Suite** — Run: `vendor/bin/phpunit` → Expected: `OK`.

- [ ] **Step 4: Verificación manual (Gabriel)** — por cada módulo: lista con conteo y etiquetas suaves; crear con error de validación (422) → errores visibles y encabezado intacto; crear y editar válidos → guardan; en Contratos, el modal "Renovar" (`formRenovar`) y el de eliminar siguen funcionando; en Usuarios, "Restablecer contraseña" guarda.

- [ ] **Step 5: Commit**

```bash
git add templates/periodos templates/feriados templates/contratos templates/usuarios
git commit -m "feat(ui): orden visual Frappe en Mantenimientos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Grupo 3 — Personal

**Files:** Modify: `templates/empleados/index.html.twig`, `templates/empleados/form.html.twig`, `templates/evaluaciones/index.html.twig`, `templates/evaluaciones/form.html.twig`, `templates/evaluaciones/detalle.html.twig`, `templates/evaluaciones/mis_evaluaciones.html.twig`, `templates/partials/eval_badge.html.twig`

- [ ] **Step 1: `eval_badge` con las clases nuevas**

Reemplazar todo `templates/partials/eval_badge.html.twig` por:

```twig
{# Calificación por rango de puntaje (no es un estado). Uso: include with {puntaje: x} only #}
{% import 'partials/ui.html.twig' as ui %}
{% if puntaje >= 90 %}{{ ui.etiqueta('Excelente', 'verde') }}
{% elseif puntaje >= 80 %}{{ ui.etiqueta('Muy bueno', 'azul') }}
{% elseif puntaje >= 70 %}{{ ui.etiqueta('Bueno', 'ambar') }}
{% elseif puntaje >= 60 %}{{ ui.etiqueta('Regular', 'ambar') }}
{% else %}{{ ui.etiqueta('Deficiente', 'rojo') }}{% endif %}
```

(Se incluye con `only`, por eso el import va dentro del partial.)

- [ ] **Step 2: Listas (receta L)**

| Plantilla | Título | Ruta | Lista | Acciones | Pie | Etiquetas |
|---|---|---|---|---|---|---|
| `empleados/index` | `'Empleados'` | Plataforma › Empleados | `empleados` | `{texto: 'Nuevo empleado', icono: 'plus-lg', url: url_for('empleados.create')}` | `empleados` | el `if e.estado == 'activo' … else …` → `{{ ui.estado(e.estado) }}`. Los botones Activos/Inactivos/Todos y el buscador se conservan (receta L paso 3). |
| `evaluaciones/index` | `'Evaluaciones'` | Nómina › Evaluaciones | `evaluaciones` | `{texto: 'Nueva evaluación', icono: 'plus-lg', url: url_for('evaluaciones.create')}` | `evaluaciones` | `eval_badge` ya migrado en Step 1 |
| `evaluaciones/mis_evaluaciones` | `'Mis evaluaciones'` | Mi cuenta › Mis evaluaciones | `evaluaciones` | ninguna (`acciones` se omite) | — (no tiene pie) | `eval_badge` |

En `evaluaciones/index` el botón de crear está dentro de un contenedor junto al buscador: borrar solo el `<a … btn-primary btn-sm>` y conservar el buscador.

- [ ] **Step 3: Formularios (receta F)**

| Plantilla | Título | Ruta (paso) | Ruta index | Guardar | Form id | Secciones |
|---|---|---|---|---|---|---|
| `empleados/form` | `accion == 'crear' ? 'Nuevo empleado' : 'Editar empleado'` | Plataforma › Empleados › `accion == 'crear' ? 'Nuevo' : 'Editar'` | `empleados.index` | `accion == 'crear' ? 'Guardar empleado' : 'Actualizar empleado'` | `form-empleado` | cada `<fieldset>`/`<legend>` existente → un `embed` con el texto de su `legend` (receta F paso 5) |
| `evaluaciones/form` | `titulo` (variable que ya llega: "Nueva Evaluación"/"Editar Evaluación") | Nómina › Evaluaciones › `titulo == 'Editar Evaluación' ? 'Editar' : 'Nueva'` | `evaluaciones.index` | `'Guardar evaluación'` | `form-evaluacion` | `'Datos de la evaluación'` para los campos de empleado/período; la tabla de criterios queda debajo, dentro de un `<div class="lb-tabla">` |

En `evaluaciones/form` el `<form>` usa `action="{{ accion }}"` (aquí `accion` es la URL): no cambiarlo.

- [ ] **Step 4: Detalle (receta D) — `evaluaciones/detalle`**

```twig
{% block encabezado %}
{{ ui.encabezado_pagina(evaluacion.nombre ~ ' ' ~ evaluacion.apellidos, {
    ruta: [{texto: 'Nómina'}, {texto: 'Evaluaciones', url: url_for('evaluaciones.index')}, {texto: 'Detalle'}],
    acciones: [{texto: 'Editar', icono: 'pencil-square', url: url_for('evaluaciones.edit', {id: evaluacion.id_evaluacion})}]
}) }}
{% endblock %}
```

Borrar del contenido el `<h5>` con el nombre y el `<a>` Editar del final; el `<form>` de eliminar (`evaluaciones.destroy`) se conserva donde está, con su botón cambiado a `btn btn-sm btn-outline-danger`. La tabla de criterios pasa a `.lb-tabla`.

- [ ] **Step 5: Suite** — `vendor/bin/phpunit` → `OK`.

- [ ] **Step 6: Verificación manual (Gabriel)** — Empleados: filtros Activos/Inactivos/Todos y buscador funcionan; formulario largo con secciones; crear con cédula repetida (422) muestra errores; editar guarda (incluye datos bancarios si el form los tiene). Evaluaciones: crear, ver detalle, editar, eliminar; "Mis evaluaciones" con un usuario empleado.

- [ ] **Step 7: Commit**

```bash
git add templates/empleados templates/evaluaciones templates/partials/eval_badge.html.twig
git commit -m "feat(ui): orden visual Frappe en Empleados y Evaluaciones

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Grupo 4 — Tiempo y ausencias

**Files:** Modify: `templates/solicitudes/index.html.twig`, `templates/solicitudes/form.html.twig`, `templates/asistencia/index.html.twig`, `templates/asistencia/form.html.twig`, `templates/horas_extra/index.html.twig`, `templates/horas_extra/form.html.twig`, `templates/vacaciones/index.html.twig`, `templates/vacaciones/form.html.twig`, `templates/incapacidades/index.html.twig`, `templates/incapacidades/form.html.twig`, `templates/permisos/index.html.twig`, `templates/permisos/form.html.twig`

- [ ] **Step 1: Listas (receta L)** — todas con ruta `Operaciones › <Módulo>`

| Plantilla | Título | Lista | Acción crear (`url_for`) | Pie | Etiquetas |
|---|---|---|---|---|---|
| `solicitudes/index` | `'Solicitudes'` | `solicitudes` | `'Nueva solicitud'` → `solicitudes.create` | `solicitudes` | `if s.estado == 'pendiente' … elseif 'aprobada' … else …` completo → `{{ ui.estado(s.estado) }}` |
| `asistencia/index` | `'Asistencia'` | `asistencias` | `'Registrar asistencia'` → `asistencia.create` | `registros` | `badge bg-warning text-dark` con `a.feriado_nombre` → `{{ ui.etiqueta(a.feriado_nombre, 'ambar') }}` |
| `horas_extra/index` | `'Horas extra'` | `horasExtra` | `'Registrar horas extra'` → `horas_extra.create` | `registros` | `badge bg-warning text-dark ms-1 "Feriado"` → `{{ ui.etiqueta('Feriado', 'ambar') }}` |
| `vacaciones/index` | `'Vacaciones'` | `vacaciones` | `'Registrar vacaciones'` → `vacaciones.create` | `registros` | (sin badges) |
| `incapacidades/index` | `'Incapacidades'` | `incapacidades` | `'Registrar incapacidad'` → `incapacidades.create` | `registros` | `CCSS` (`bg-primary`) → `ui.etiqueta('CCSS', 'azul')`; `INS` (`bg-info`) → `ui.etiqueta('INS', 'verde')`; `Particular` → `ui.etiqueta('Particular', 'gris')` |
| `permisos/index` | `'Permisos'` | `permisos` | `'Registrar permiso'` → `permisos.create` | `registros` | `Con goce` → `ui.etiqueta('Con goce', 'verde')`; `Sin goce` → `ui.etiqueta('Sin goce', 'gris')` |

Los formularios GET de filtros (período, empleado, estado) se conservan tal cual.

- [ ] **Step 2: Formularios (receta F)** — ruta `Operaciones › <Módulo> › (accion == 'crear' ? 'Registrar' : 'Editar')`, salvo Solicitudes (`'Nueva'`/`'Editar'`)

| Plantilla | Título (`crear` / editar) | Ruta index | Guardar | Form id | Sección única |
|---|---|---|---|---|---|
| `solicitudes/form` | `'Nueva solicitud'` / `'Editar solicitud'` | `solicitudes.index` | `accion == 'crear' ? 'Guardar' : 'Actualizar'` | `form-solicitud` | `'Datos de la solicitud'` |
| `asistencia/form` | `'Registrar asistencia'` / `'Editar asistencia'` | `asistencia.index` | ídem | `form-asistencia` | `'Datos de la asistencia'` |
| `horas_extra/form` | `'Registrar horas extra'` / `'Editar horas extra'` | `horas_extra.index` | ídem | `form-horas-extra` | `'Datos de las horas extra'` |
| `vacaciones/form` | `'Registrar vacaciones'` / `'Editar vacaciones'` | `vacaciones.index` | ídem | `form-vacaciones` | `'Datos de las vacaciones'` |
| `incapacidades/form` | `'Registrar incapacidad'` / `'Editar incapacidad'` | `incapacidades.index` | ídem | `form-incapacidad` | `'Datos de la incapacidad'` |
| `permisos/form` | `'Registrar permiso'` / `'Editar permiso'` | `permisos.index` | ídem | `form-permiso` | `'Datos del permiso'` |

Antes de borrar los botones del final de `solicitudes/form`, revisar `public/assets/js/app.js` (`initSolicitudFormDinamico`): si busca el botón submit por `id`, pasar ese `id` a la acción Guardar.

- [ ] **Step 3: Suite** — `vendor/bin/phpunit` → `OK`.

- [ ] **Step 4: Verificación manual (Gabriel)** — Solicitudes: aprobar y rechazar desde el modal (`formResolver`), estados con sus tonos (pendiente ámbar, aprobada verde, rechazada rojo); el campo Horas se oculta para vacaciones/permiso. Cada módulo: registrar, editar, filtrar, eliminar; error de validación (ej. vacaciones sin saldo) se ve con el encabezado intacto.

- [ ] **Step 5: Commit**

```bash
git add templates/solicitudes templates/asistencia templates/horas_extra templates/vacaciones templates/incapacidades templates/permisos
git commit -m "feat(ui): orden visual Frappe en Tiempo y ausencias

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Grupo 5 — Nómina

**Files:** Modify: `templates/nominas/index.html.twig`, `templates/nominas/detalle.html.twig`, `templates/aguinaldo/index.html.twig`, `templates/liquidacion/index.html.twig`, `templates/liquidacion/form.html.twig`, `templates/liquidacion/detalle.html.twig`

- [ ] **Step 1: `nominas/index` (receta L)**

Encabezado: título `'Nóminas'`, ruta `Nómina › Nóminas`, conteo `nominas|length`, acción `{texto: 'Generar nóminas', icono: 'gear-fill', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#<id del modal de generar>'}}` — copiar el `data-bs-target` exacto del botón `btn-primary` actual (línea ~25) y borrar ese botón. El filtro por período y el `formGenerar` oculto se conservan. Estado: `<span class="badge-fo {{ n.estado == 'pagado' ? … }}">{{ n.estado }}</span>` → `{{ ui.estado(n.estado) }}`.

- [ ] **Step 2: `nominas/detalle` (receta D)**

```twig
{% block encabezado %}
{% set acciones_nomina = [] %}
{% if nomina.estado == 'borrador' %}
    {% set acciones_nomina = [{texto: 'Aprobar', icono: 'check2-circle', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalAprobar'}}] %}
{% elseif nomina.estado == 'aprobado' %}
    {% set acciones_nomina = [{texto: 'Marcar como pagada', icono: 'cash-coin', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalPagar'}}] %}
{% endif %}
{{ ui.encabezado_pagina(nomina.nombre ~ ' ' ~ nomina.apellidos, {
    ruta: [{texto: 'Nómina'}, {texto: 'Nóminas', url: url_for('nominas.index')}, {texto: 'Detalle'}],
    estado: nomina.estado,
    acciones: acciones_nomina
}) }}
{% endblock %}
```

Antes de escribirlo, confirmar en la plantilla actual (líneas ~20–32) las condiciones exactas bajo las que se muestran los botones `#modalAprobar` y `#modalPagar`, y copiarlas tal cual en los `if`. Borrar del contenido el `<h5>` con el nombre, la etiqueta `badge-fo` de estado y esos dos botones. La etiqueta "informativa" de `CCSS_patronal` (`badge-fo badge-pend`) → `{{ ui.etiqueta('informativa', 'ambar') }}`. Las tablas de ingresos y deducciones pasan a `.lb-tabla`; sus formularios de agregar/quitar línea se conservan.

- [ ] **Step 3: `aguinaldo/index` (receta L)**

Título `'Aguinaldo'`, ruta `Nómina › Aguinaldo`, conteo `aguinaldos|length`, acción `{texto: 'Calcular aguinaldo', icono: 'gift', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#<id del modal de calcular>'}}` (copiar el target del botón actual, línea ~19, y borrarlo). Estado: `<span class="badge-fo {{ a.estado == 'pagado' ? 'badge-ok' : 'badge-pend' }}">{{ a.estado }}</span>` → `{{ ui.estado(a.estado) }}`. El selector de año y `formCalcular`/`formPagar` se conservan.

- [ ] **Step 4: Liquidación**

| Plantilla | Receta | Encabezado |
|---|---|---|
| `liquidacion/index` | L | título `'Liquidaciones'`, ruta `Nómina › Liquidaciones`, conteo `liquidaciones|length`, acción `{texto: 'Nueva liquidación', icono: 'plus-lg', url: url_for('liquidacion.create')}` (borrar el `<a … btn-primary btn-sm>` y conservar el buscador) |
| `liquidacion/form` | F | título `'Nueva liquidación'`, ruta `Nómina › Liquidaciones (url liquidacion.index) › Nueva`, acciones Cancelar → `liquidacion.index` y `{texto: 'Calcular liquidación', icono: 'calculator', form: 'form-liquidacion'}`; sección única `'Datos de la salida'` |
| `liquidacion/detalle` | D | título `liquidacion.nombre ~ ' ' ~ liquidacion.apellidos`, ruta `Nómina › Liquidaciones › Detalle`, acción `{texto: 'Eliminar', icono: 'trash3', tipo: 'peligro', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#<id del modal de eliminar>'}}` (copiar el target del botón actual y borrarlo) |

- [ ] **Step 5: Suite** — `vendor/bin/phpunit` → `OK`.

- [ ] **Step 6: Verificación manual (Gabriel)** — Generar nóminas de un período; abrir el detalle (estado Borrador en ámbar junto al nombre); agregar y quitar una línea; Aprobar (pasa a Aprobado, azul) y Pagar (Pagado, verde) desde los botones del encabezado. Calcular y pagar aguinaldo. Crear, ver y eliminar una liquidación.

- [ ] **Step 7: Commit**

```bash
git add templates/nominas templates/aguinaldo templates/liquidacion
git commit -m "feat(ui): orden visual Frappe en Nómina, Aguinaldo y Liquidación

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Grupo 6 — Dashboard, Reportes, Portal y Login; cierre

**Files:** Modify: `templates/dashboard/index.html.twig`, `templates/reportes/index.html.twig`, `templates/reportes/planilla.html.twig`, `templates/reportes/historial.html.twig`, `templates/reportes/costos.html.twig`, `templates/reportes/auditoria.html.twig`, `templates/portal/*.html.twig` (8), `templates/auth/login.html.twig`, `templates/layouts/base.html.twig`

- [ ] **Step 1: Encabezados de pantallas especiales**

En cada plantilla: import de `ui`, borrar `breadcrumb`, agregar `encabezado`:

| Plantilla | Título | Ruta | Acciones |
|---|---|---|---|
| `dashboard/index` | `'Panel de control'` | Plataforma › Dashboard | ninguna |
| `reportes/index` | `'Reportes'` | Plataforma › Reportes | ninguna |
| `reportes/planilla` | `'Planilla por período'` | Plataforma › Reportes (url `reportes.index`) › Planilla | ninguna (los botones de exportar Excel/PDF se quedan en el contenido) |
| `reportes/historial` | `'Historial por empleado'` | … › Historial | ídem |
| `reportes/costos` | `'Costos patronales'` | … › Costos patronales | ídem |
| `reportes/auditoria` | `'Bitácora de auditoría'` | … › Auditoría | ídem |
| `portal/mi_perfil` | `'Mi perfil'` | Mi cuenta › Mi perfil | ninguna |
| `portal/mis_colillas` | `'Mis colillas'` | Mi cuenta › Mis colillas | ninguna; conteo `colillas|length` |
| `portal/colilla` | `'Colilla'` | Mi cuenta › Mis colillas (url `portal.colillas`) › Colilla | `{texto: 'Imprimir', icono: 'printer', atributos: {onclick: 'window.print()'}}` (borrar el botón actual de imprimir) |
| `portal/mis_vacaciones` | `'Mis vacaciones'` | Mi cuenta › Mis vacaciones | ninguna |
| `portal/mi_asistencia` | `'Mi asistencia'` | Mi cuenta › Mi asistencia | ninguna |
| `portal/mis_solicitudes` | `'Mis solicitudes'` | Mi cuenta › Mis solicitudes | `{texto: 'Nueva solicitud', icono: 'plus-lg', url: url_for('portal.solicitudes.crear')}` (borrar el `<a>` actual); conteo `solicitudes|length` |
| `portal/solicitud_form` | `accion == 'crear' ? 'Nueva solicitud' : 'Editar solicitud'` | Mi cuenta › Mis solicitudes (url `portal.solicitudes`) › `accion == 'crear' ? 'Nueva' : 'Editar'` | Cancelar → `portal.solicitudes`; `{texto: accion == 'crear' ? 'Enviar' : 'Actualizar', icono: 'floppy', form: 'form-solicitud-portal', id: 'btnEnviar'}`; agregar `id="form-solicitud-portal"` al `<form>` y borrar el botón `btnEnviar` del final |
| `portal/cambiar_contrasena` | `'Cambiar contraseña'` | Mi cuenta › Cambiar contraseña | `{texto: 'Guardar', icono: 'key', form: 'form-contrasena'}`; agregar `id="form-contrasena"` al `<form>` y borrar su botón submit |

`login.html.twig` no usa el layout con barra superior (bloque `contenido_publico`): no lleva encabezado; solo se revisa que no tenga `badge` sueltos (no tiene) y se deja igual.

- [ ] **Step 2: Etiquetas en estas pantallas**

| Plantilla | Antes | Después |
|---|---|---|
| `portal/mis_solicitudes` | `if s.estado == 'pendiente' … elseif 'aprobada' … else …` | `{{ ui.estado(s.estado) }}` |
| `portal/mis_colillas` | `<span class="badge {{ c.estado == 'pagado' ? 'text-bg-success' : 'text-bg-primary' }}">{{ c.estado }}</span>` | `{{ ui.estado(c.estado) }}` |
| `portal/mi_perfil` (2 lugares) | `<span class="badge-fo {{ empleado.estado == 'activo' ? 'badge-ok' : 'badge-pend' }}">…</span>` | `{{ ui.estado(empleado.estado) }}` |
| `portal/mi_perfil` | `<span class="badge-fo badge-emp">…</span>` (puesto/rol) | `{{ ui.etiqueta(<mismo texto>, 'azul') }}` |
| `portal/mi_asistencia` | `<span class="badge-fo badge-pend">{{ r.feriado_nombre }}</span>` | `{{ ui.etiqueta(r.feriado_nombre, 'ambar') }}` |
| `reportes/planilla` | `<span class="badge text-bg-secondary">{{ f.estado }}</span>` | `{{ ui.estado(f.estado) }}` |
| `reportes/historial` | `<span class="badge text-bg-secondary">{{ n.estado }}</span>` | `{{ ui.estado(n.estado) }}` |
| `reportes/historial` | `badge-fo badge-ok "Con goce"` / `badge-fo badge-pend "Sin goce"` | `ui.etiqueta('Con goce', 'verde')` / `ui.etiqueta('Sin goce', 'gris')` |
| `reportes/auditoria` | `<span class="badge text-bg-secondary">{{ r.accion }}</span>` | `{{ ui.etiqueta(r.accion, 'gris') }}` |

Revisar también el resto de `dashboard/index` y `reportes/*` con el grep del Step 4 y migrar lo que aparezca con el mismo criterio (estado de BD → `ui.estado`; tipo/rol → `ui.etiqueta`).

- [ ] **Step 3: Quitar `breadcrumb` del layout**

En `templates/layouts/base.html.twig`, dentro de `{% block encabezado %}`, reemplazar `<div class="lb-crumb">{% block breadcrumb %}Inicio{% endblock %}</div>` por `<div class="lb-crumb">Inicio</div>`.

- [ ] **Step 4: Verificación de cierre (grep)**

Run: `grep -rnE "badge (bg|text-bg)-|badge-fo badge-(ok|pend)|block breadcrumb" templates`
Expected: sin resultados. (`badge-admin`, `badge-emp`, `badge-super` en `layouts/base.html.twig` son el chip del usuario conectado y se quedan; `badge-emp` en `mi_perfil` ya se migró en Step 2.)

Run: `grep -rLE "block encabezado" templates/*/*.html.twig | grep -vE "layouts/|partials/|auth/login"`
Expected: sin resultados (todas las plantillas con barra superior definen `encabezado`).

- [ ] **Step 5: Suite** — `vendor/bin/phpunit` → `OK`.

- [ ] **Step 6: Verificación manual (Gabriel)** — Dashboard admin y empleado; cada reporte con exportar a Excel y PDF; la colilla se imprime con el botón del encabezado (y la barra superior no sale en la impresión, por el `@media print` existente); en el portal, crear una solicitud de vacaciones: la barra de saldo funciona y el botón "Enviar" de arriba se deshabilita cuando se pasa del saldo (`btnEnviar`); cambiar contraseña. Todo a 375 px de ancho.

- [ ] **Step 7: Commit**

```bash
git add templates/dashboard templates/reportes templates/portal templates/auth templates/layouts/base.html.twig
git commit -m "feat(ui): orden visual Frappe en Dashboard, Reportes y Portal; quita breadcrumb del layout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
