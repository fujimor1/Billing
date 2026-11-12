<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Entities\Producto;

interface ProductoRepositoryInterface
{
    public function guardar(Producto $producto): void;

    public function buscarPorCodigo(string $codigo): ?Producto;

    /**
     * @return Producto[]
     */
    public function listarTodos(): array;
}
