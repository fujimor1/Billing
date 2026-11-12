<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Exceptions\StockInsuficienteException;

/**
 * Entidad Producto con control de stock (Kardex) y configuración tributaria
 */
final class Producto
{
    public function __construct(
        private string $codigo,
        private string $descripcion,
        private float $valorUnitarioSinIgv,
        private int $stockActual,
        private TipoAfectacionIgv $tipoAfectacion = TipoAfectacionIgv::GRAVADO_OPERACION_ONEROSA,
        private bool $afectoIcbper = false,
        private string $unidadMedida = 'NIU' // NIU = Unidad (bienes), ZZ = Servicio
    ) {
        if (trim($codigo) === '') {
            throw new ReglaTributariaException("El código del producto no puede estar vacío.");
        }
        if ($valorUnitarioSinIgv < 0) {
            throw new ReglaTributariaException("El valor unitario del producto no puede ser negativo.");
        }
        if ($stockActual < 0) {
            throw new StockInsuficienteException("El stock inicial no puede ser negativo.");
        }
    }

    public function descontarStock(int $cantidad): void
    {
        if ($cantidad <= 0) {
            throw new ReglaTributariaException("La cantidad a descontar de stock debe ser mayor a cero.");
        }

        if ($this->unidadMedida !== 'ZZ' && $this->stockActual < $cantidad) {
            throw new StockInsuficienteException(
                "Stock insuficiente para el producto '{$this->codigo} - {$this->descripcion}'. " .
                "Disponible: {$this->stockActual}, Solicitado: {$cantidad}."
            );
        }

        if ($this->unidadMedida !== 'ZZ') {
            $this->stockActual -= $cantidad;
        }
    }

    public function reponerStock(int $cantidad): void
    {
        if ($cantidad <= 0) {
            throw new ReglaTributariaException("La cantidad a reponer en stock debe ser mayor a cero.");
        }

        if ($this->unidadMedida !== 'ZZ') {
            $this->stockActual += $cantidad;
        }
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function descripcion(): string
    {
        return $this->descripcion;
    }

    public function valorUnitarioSinIgv(): float
    {
        return $this->valorUnitarioSinIgv;
    }

    public function stockActual(): int
    {
        return $this->stockActual;
    }

    public function tipoAfectacion(): TipoAfectacionIgv
    {
        return $this->tipoAfectacion;
    }

    public function esAfectoIcbper(): bool
    {
        return $this->afectoIcbper;
    }

    public function unidadMedida(): string
    {
        return $this->unidadMedida;
    }
}
