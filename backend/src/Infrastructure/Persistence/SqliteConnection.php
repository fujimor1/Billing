<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/**
 * Gestor de conexión PDO SQLite (Soporta ':memory:' para PHPUnit y archivo físico para Producción/React)
 */
final class SqliteConnection
{
    private PDO $pdo;

    public function __construct(string $databasePath = ':memory:')
    {
        $dsn = $databasePath === ':memory:' ? 'sqlite::memory:' : "sqlite:{$databasePath}";
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->inicializarEsquema();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function inicializarEsquema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS productos (
                codigo TEXT PRIMARY KEY,
                descripcion TEXT NOT NULL,
                valor_unitario_sin_igv REAL NOT NULL,
                stock_actual INTEGER NOT NULL,
                tipo_afectacion TEXT NOT NULL,
                afecto_icbper INTEGER NOT NULL DEFAULT 0,
                unidad_medida TEXT NOT NULL DEFAULT 'NIU'
            );

            CREATE TABLE IF NOT EXISTS comprobantes (
                numero_completo TEXT PRIMARY KEY,
                serie TEXT NOT NULL,
                correlativo INTEGER NOT NULL,
                tipo_comprobante TEXT NOT NULL,
                cliente_tipo_doc TEXT NOT NULL,
                cliente_num_doc TEXT NOT NULL,
                cliente_razon_social TEXT NOT NULL,
                cliente_direccion TEXT NOT NULL,
                moneda_codigo TEXT NOT NULL,
                moneda_tipo_cambio REAL NOT NULL,
                fecha_emision TEXT NOT NULL,
                estado TEXT NOT NULL,
                hash_cpe TEXT,
                xml_firmado TEXT,
                codigo_sunat TEXT,
                mensaje_sunat TEXT,
                comprobante_referencia TEXT,
                motivo_nc TEXT,
                descripcion_motivo_nc TEXT,
                saldo_disponible_nc REAL NOT NULL,
                resumen_json TEXT NOT NULL,
                items_json TEXT NOT NULL
            );
        ");
    }

    public function sembrarCatalogoInicialSiVacio(): void
    {
        $productosDemo = [
            ['LAP-PRO-16', 'Laptop Workstation Pro 16" 32GB RAM', 3500.00, 15, '10', 0, 'NIU'],
            ['MON-4K-27', 'Monitor IPS 27" 4K UHD Profesional', 1100.00, 25, '10', 0, 'NIU'],
            ['TEC-MEC-RGB', 'Teclado Mecánico Inalámbrico', 250.00, 40, '10', 0, 'NIU'],
            ['LIB-CLEAN-CODE', 'Libro: Ingeniería de Pruebas con PHPUnit (Exonerado IGV)', 120.00, 30, '20', 0, 'NIU'],
            ['SERV-QA-AUD', 'Servicio de Auditoría QA y Testing Automatizado', 2000.00, 999, '10', 0, 'ZZ'],
            ['BOLSA-ECO', 'Bolsa Plástica Biodegradable (Afecta ICBPER)', 0.20, 500, '10', 1, 'NIU'],
            ['CURS-EDU-INAF', 'Diplomado Superior en Ingeniería de Software (Inafecto IGV - Servicio Educativo)', 1500.00, 30, '30', 0, 'ZZ'],
            ['SEGU-VIDA-EMP', 'Póliza Colectiva de Seguro Médico y Vida Ley (Inafecto IGV - Art. 2 LIGV)', 380.00, 50, '30', 0, 'ZZ'],
            ['MANZ-ORG-KG', 'Manzana Orgánica Fresca de Valle (Bien Natural Inafecto al IGV)', 8.50, 200, '30', 0, 'KGM'],
        ];

        $insert = $this->pdo->prepare("
            INSERT OR IGNORE INTO productos (codigo, descripcion, valor_unitario_sin_igv, stock_actual, tipo_afectacion, afecto_icbper, unidad_medida)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($productosDemo as $p) {
            $insert->execute($p);
        }
    }
}
