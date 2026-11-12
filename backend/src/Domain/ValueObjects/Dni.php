<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\DocumentoIdentidadInvalidoException;

final class Dni
{
    private string $valor;

    public function __construct(string $valor)
    {
        $limpio = trim($valor);

        if (!preg_match('/^\d{8}$/', $limpio)) {
            throw new DocumentoIdentidadInvalidoException(
                "El DNI '{$valor}' debe contener exactamente 8 dígitos numéricos."
            );
        }

        if ($limpio === '00000000') {
            throw new DocumentoIdentidadInvalidoException(
                "El DNI no puede estar compuesto únicamente por ceros."
            );
        }

        $this->valor = $limpio;
    }

    public function valor(): string
    {
        return $this->valor;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
