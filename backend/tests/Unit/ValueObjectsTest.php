<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\TipoComprobante;
use App\Domain\Exceptions\DocumentoIdentidadInvalidoException;
use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\ValueObjects\Dni;
use App\Domain\ValueObjects\Moneda;
use App\Domain\ValueObjects\Ruc;
use App\Domain\ValueObjects\SerieCorrelativo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('Suite Unitaria 1: Validación de Value Objects Tributarios (RUC Módulo 11, DNI, Series y Monedas)')]
final class ValueObjectsTest extends TestCase
{
    #[DataProvider('proveedorRucsValidos')]
    #[TestDox('Acepta números de RUC válidos verificando el algoritmo oficial Módulo 11 de SUNAT')]
    public function testAceptaRucsValidosConAlgoritmoModulo11(string $numeroRuc, bool $esJuridica): void
    {
        $ruc = new Ruc($numeroRuc);

        $this->assertSame(trim($numeroRuc), $ruc->valor());
        $this->assertSame($esJuridica, $ruc->esPersonaJuridica());
        $this->assertSame(!$esJuridica, $ruc->esPersonaNatural());
    }

    public static function proveedorRucsValidos(): array
    {
        return [
            'SUNAT (Persona Jurídica)' => ['20131312955', true],
            'Banco de Crédito BCP (Persona Jurídica)' => ['20100047218', true],
            'Alicorp SAA (Persona Jurídica)' => ['20100055237', true],
            'Contribuyente Persona Natural con Negocio' => ['10456789124', false],
        ];
    }

    #[DataProvider('proveedorRucsInvalidos')]
    #[TestDox('Rechaza números de RUC con longitud incorrecta, prefijo no autorizado o dígito verificador alterado')]
    public function testRechazaRucsInvalidos(string $rucInvalido, string $mensajeEsperado): void
    {
        $this->expectException(DocumentoIdentidadInvalidoException::class);
        $this->expectExceptionMessage($mensajeEsperado);

        new Ruc($rucInvalido);
    }

    public static function proveedorRucsInvalidos(): array
    {
        return [
            'Menos de 11 dígitos' => ['2013131295', '11 dígitos'],
            'Más de 11 dígitos' => ['201313129559', '11 dígitos'],
            'Contiene letras' => ['2013131295A', '11 dígitos'],
            'Prefijo 99 no existente en SUNAT' => ['99131312955', 'prefijo inválido'],
            'Dígito verificador Módulo 11 adulterado (termina en 4 en vez de 5)' => ['20131312954', 'dígito verificador'],
            'Dígito verificador Módulo 11 adulterado en RUC 10' => ['10456789120', 'dígito verificador'],
        ];
    }

    #[TestDox('Valida DNI de 8 dígitos y rechaza DNI de puros ceros o alfanumérico')]
    public function testValidacionDeDni(): void
    {
        $dni = new Dni('72345678');
        $this->assertSame('72345678', $dni->valor());

        $this->expectException(DocumentoIdentidadInvalidoException::class);
        new Dni('00000000');
    }

    #[TestDox('Formatea correctamente Serie y Correlativo a 8 dígitos (F001-00000045)')]
    public function testFormateoDeSerieYCorrelativo(): void
    {
        $serie = new SerieCorrelativo('f001', 45, TipoComprobante::FACTURA);

        $this->assertSame('F001', $serie->serie());
        $this->assertSame(45, $serie->correlativo());
        $this->assertSame('00000045', $serie->correlativoFormateado());
        $this->assertSame('F001-00000045', $serie->formatoCompleto());
    }

    #[TestDox('Impide usar una serie de Boleta (B001) en una Factura Electrónica (01)')]
    public function testRechazaIncoherenciaEntreSerieYTipoComprobante(): void
    {
        $this->expectException(ReglaTributariaException::class);
        $this->expectExceptionMessage("Una Factura Electrónica (01) debe tener una serie que inicie con 'F'");

        new SerieCorrelativo('B001', 1, TipoComprobante::FACTURA);
    }

    #[TestDox('Exige un tipo de cambio válido mayor a 1.00 cuando la moneda es Dólares (USD)')]
    public function testMonedaExtranjeraExigeTipoDeCambioValido(): void
    {
        $usd = new Moneda(Moneda::USD, 3.754);
        $this->assertTrue($usd->esExtranjera());
        $this->assertSame(375.40, $usd->convertirASoles(100.00));

        $this->expectException(ReglaTributariaException::class);
        new Moneda(Moneda::USD, 1.0);
    }
}
