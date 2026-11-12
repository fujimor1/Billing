<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\DocumentoIdentidadInvalidoException;

/**
 * Value Object para el Registro Único de Contribuyentes (RUC - Perú SUNAT)
 * Implementa el algoritmo oficial Módulo 11 de dígito verificador.
 */
final class Ruc
{
    private const PESOS_MODULO_11 = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    private const PREFIJOS_VALIDOS = ['10', '15', '17', '20'];

    private string $valor;

    public function __construct(string $valor)
    {
        $limpio = trim($valor);

        if (!preg_match('/^\d{11}$/', $limpio)) {
            throw new DocumentoIdentidadInvalidoException(
                "El RUC '{$valor}' debe contener exactamente 11 dígitos numéricos."
            );
        }

        $prefijo = substr($limpio, 0, 2);
        if (!in_array($prefijo, self::PREFIJOS_VALIDOS, true)) {
            throw new DocumentoIdentidadInvalidoException(
                "El RUC '{$limpio}' tiene un prefijo inválido ({$prefijo}). Debe iniciar con 10, 15, 17 o 20."
            );
        }

        if (!$this->validarDigitoVerificador($limpio)) {
            throw new DocumentoIdentidadInvalidoException(
                "El RUC '{$limpio}' no supera la validación del dígito verificador (Módulo 11 SUNAT)."
            );
        }

        $this->valor = $limpio;
    }

    private function validarDigitoVerificador(string $ruc): bool
    {
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += ((int) $ruc[$i]) * self::PESOS_MODULO_11[$i];
        }

        $resto = $suma % 11;
        $complemento = 11 - $resto;

        $digitoEsperado = match ($complemento) {
            10 => 0,
            11 => 1,
            default => $complemento,
        };

        return ((int) $ruc[10]) === $digitoEsperado;
    }

    public static function calcularDigitoVerificador(string $primerosDiezDigitos): int
    {
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += ((int) $primerosDiezDigitos[$i]) * self::PESOS_MODULO_11[$i];
        }
        $complemento = 11 - ($suma % 11);
        return match ($complemento) {
            10 => 0,
            11 => 1,
            default => $complemento,
        };
    }

    public function esPersonaJuridica(): bool
    {
        return str_starts_with($this->valor, '20');
    }

    public function esPersonaNatural(): bool
    {
        return str_starts_with($this->valor, '10');
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
