<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\DocumentoIdentidadInvalidoException;
use App\Domain\ValueObjects\Dni;
use App\Domain\ValueObjects\Ruc;

/**
 * Representa al receptor del comprobante (Catálogo N° 06 SUNAT: 6=RUC, 1=DNI, 0=Varios)
 */
final class Cliente
{
    public const TIPO_DOC_RUC = '6';
    public const TIPO_DOC_DNI = '1';
    public const TIPO_DOC_SIN_DOCUMENTO = '0';

    private string $tipoDocumento;
    private string $numeroDocumento;
    private string $razonSocial;
    private string $direccion;

    private function __construct(
        string $tipoDocumento,
        string $numeroDocumento,
        string $razonSocial,
        string $direccion = ''
    ) {
        $razonLimpia = trim($razonSocial);
        if (mb_strlen($razonLimpia) < 3) {
            throw new DocumentoIdentidadInvalidoException(
                "La razón social o nombre del cliente debe tener al menos 3 caracteres."
            );
        }

        $this->tipoDocumento = $tipoDocumento;
        $this->numeroDocumento = $numeroDocumento;
        $this->razonSocial = $razonLimpia;
        $this->direccion = trim($direccion);
    }

    public static function conRuc(string $numeroRuc, string $razonSocial, string $direccion = ''): self
    {
        $ruc = new Ruc($numeroRuc);
        return new self(self::TIPO_DOC_RUC, $ruc->valor(), $razonSocial, $direccion);
    }

    public static function conDni(string $numeroDni, string $nombreCompleto, string $direccion = ''): self
    {
        $dni = new Dni($numeroDni);
        return new self(self::TIPO_DOC_DNI, $dni->valor(), $nombreCompleto, $direccion);
    }

    public static function clienteVarios(): self
    {
        return new self(self::TIPO_DOC_SIN_DOCUMENTO, '00000000', 'CLIENTES VARIOS', '');
    }

    public function tieneRuc(): bool
    {
        return $this->tipoDocumento === self::TIPO_DOC_RUC;
    }

    public function tieneDni(): bool
    {
        return $this->tipoDocumento === self::TIPO_DOC_DNI;
    }

    public function esClienteVarios(): bool
    {
        return $this->tipoDocumento === self::TIPO_DOC_SIN_DOCUMENTO;
    }

    public function tipoDocumento(): string
    {
        return $this->tipoDocumento;
    }

    public function numeroDocumento(): string
    {
        return $this->numeroDocumento;
    }

    public function razonSocial(): string
    {
        return $this->razonSocial;
    }

    public function direccion(): string
    {
        return $this->direccion;
    }
}
