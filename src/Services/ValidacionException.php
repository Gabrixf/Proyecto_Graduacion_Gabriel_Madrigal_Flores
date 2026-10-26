<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Errores de validación asociados a su campo del formulario (clave = `name` del input).
 * Sigue siendo una InvalidArgumentException con todos los mensajes unidos, así que el
 * código que solo muestra el mensaje no cambia; el que necesita saber dónde ocurrió
 * cada error (p. ej. para marcar la pestaña) usa campos().
 */
class ValidacionException extends InvalidArgumentException
{
    /** @param array<string, string> $campos campo => mensaje */
    public function __construct(private readonly array $campos)
    {
        parent::__construct(implode(' ', $campos));
    }

    /** @return array<string, string> */
    public function campos(): array
    {
        return $this->campos;
    }
}
