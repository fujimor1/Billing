<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Producto;
use App\Domain\Enums\TipoAfectacionIgv;
use App\Domain\Ports\ProductoRepositoryInterface;
use PDO;

final class SqliteProductoRepository implements ProductoRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function guardar(Producto $producto): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO productos (codigo, descripcion, valor_unitario_sin_igv, stock_actual, tipo_afectacion, afecto_icbper, unidad_medida)
            VALUES (:codigo, :descripcion, :valor, :stock, :afectacion, :icbper, :unidad)
            ON CONFLICT(codigo) DO UPDATE SET
                descripcion = excluded.descripcion,
                valor_unitario_sin_igv = excluded.valor_unitario_sin_igv,
                stock_actual = excluded.stock_actual,
                tipo_afectacion = excluded.tipo_afectacion,
                afecto_icbper = excluded.afecto_icbper,
                unidad_medida = excluded.unidad_medida
        ");

        $stmt->execute([
            ':codigo' => $producto->codigo(),
            ':descripcion' => $producto->descripcion(),
            ':valor' => $producto->valorUnitarioSinIgv(),
            ':stock' => $producto->stockActual(),
            ':afectacion' => $producto->tipoAfectacion()->value,
            ':icbper' => $producto->esAfectoIcbper() ? 1 : 0,
            ':unidad' => $producto->unidadMedida(),
        ]);
    }

    public function buscarPorCodigo(string $codigo): ?Producto
    {
        $stmt = $this->pdo->prepare("SELECT * FROM productos WHERE codigo = :codigo LIMIT 1");
        $stmt->execute([':codigo' => trim($codigo)]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hidratar($row);
    }

    public function listarTodos(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM productos ORDER BY codigo ASC");
        $rows = $stmt->fetchAll();

        return array_map(fn (array $row) => $this->hidratar($row), $rows);
    }

    private function hidratar(array $row): Producto
    {
        return new Producto(
            codigo: (string) $row['codigo'],
            descripcion: (string) $row['descripcion'],
            valorUnitarioSinIgv: (float) $row['valor_unitario_sin_igv'],
            stockActual: (int) $row['stock_actual'],
            tipoAfectacion: TipoAfectacionIgv::from((string) $row['tipo_afectacion']),
            afectoIcbper: ((int) $row['afecto_icbper']) === 1,
            unidadMedida: (string) $row['unidad_medida']
        );
    }
}
