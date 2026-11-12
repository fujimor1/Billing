<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Entities\Comprobante;

interface ComprobanteRepositoryInterface
{
    public function guardar(Comprobante $comprobante): void;

    public function buscarPorNumero(string $serieCorrelativo): ?Comprobante;

    /**
     * @return Comprobante[]
     */
    public function listarTodos(): array;

    public function siguienteCorrelativo(string $serie): int;
}
