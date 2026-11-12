<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Entities\Cliente;
use App\Domain\Entities\Comprobante;
use App\Domain\Entities\ItemComprobante;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Services\CalculadoraTributaria;
use App\Domain\ValueObjects\SerieCorrelativo;
use App\Infrastructure\Crypto\XmlDigitalSigner;
use App\Infrastructure\Gateways\SunatSoapSimulatorGateway;
use App\Infrastructure\Xml\Ubl21XmlGenerator;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('Suite de Integración 1: Estándar XML OASIS UBL 2.1, Firma Criptográfica SHA-256 y Detección de Adulteración')]
final class XmlAndCryptoIntegrationTest extends TestCase
{
    private Ubl21XmlGenerator $xmlGenerator;
    private XmlDigitalSigner $digitalSigner;
    private CalculadoraTributaria $calculadora;

    protected function setUp(): void
    {
        $this->xmlGenerator = new Ubl21XmlGenerator();
        $this->digitalSigner = new XmlDigitalSigner('LLAVE_PRIVADA_TEST_SUNAT_2026');
        $this->calculadora = new CalculadoraTributaria();
    }

    #[TestDox('Genera un documento XML UBL 2.1 válido y verifica sus nodos obligatorios mediante consultas XPath')]
    public function testGeneraEstructuraXmlUbl21ValidaConXPath(): void
    {
        $items = [
            new ItemComprobante('SRV-CLOUD', 'Servidor Cloud Dedicado', 2, 1000.00),
        ];
        $resumen = $this->calculadora->calcular($items); // Base: 2000.00, IGV: 360.00, Total: 2360.00

        $factura = new Comprobante(
            new SerieCorrelativo('F001', 25, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20100047218', 'BANCO DE CREDITO DEL PERU'),
            $items,
            $resumen
        );

        $xmlString = $this->xmlGenerator->generarXmlUbl21($factura);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xmlString), 'El XML generado debe ser sintácticamente válido');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $this->assertSame('2.1', $xpath->evaluate('string(//cbc:UBLVersionID)'));
        $this->assertSame('F001-00000025', $xpath->evaluate('string(/child::*/cbc:ID)'));
        $this->assertSame('01', $xpath->evaluate('string(//cbc:InvoiceTypeCode)'));
        $this->assertSame('20100047218', $xpath->evaluate('string(//cac:AccountingCustomerParty//cbc:ID)'));
        $this->assertSame('360.00', $xpath->evaluate('string(//cac:TaxTotal/cbc:TaxAmount)'));
        $this->assertSame('2360.00', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
    }

    #[TestDox('Firma digitalmente el XML UBL 2.1 con SHA-256 e inyecta DigestValue y SignatureValue verificables')]
    public function testFirmaCriptograficamenteElXmlYVerificaSuIntegridad(): void
    {
        $items = [new ItemComprobante('LAP-01', 'Laptop Gamer', 1, 3000.00)];
        $factura = new Comprobante(
            new SerieCorrelativo('F001', 7, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20100055237', 'ALICORP SAA'),
            $items,
            $this->calculadora->calcular($items)
        );

        $xmlSinFirmar = $this->xmlGenerator->generarXmlUbl21($factura);
        $resultadoFirma = $this->digitalSigner->firmarXml($xmlSinFirmar);

        $this->assertNotEmpty($resultadoFirma->hashCpe);
        $this->assertNotEmpty($resultadoFirma->signatureValue);
        $this->assertStringContainsString('<ds:DigestValue>', $resultadoFirma->xmlFirmado);
        $this->assertStringContainsString('<ds:SignatureValue>', $resultadoFirma->xmlFirmado);

        // La verificación criptográfica debe ser verdadera para el documento intacto
        $this->assertTrue($this->digitalSigner->verificarIntegridadXml($resultadoFirma->xmlFirmado));
    }

    #[TestDox('Detecta adulteración (ataque de modificación de importe en el XML firmado) y SUNAT lo rechaza con código 2335')]
    public function testDetectaAdulteracionDeImporteEnXmlFirmadoYSunatLoRechaza(): void
    {
        $items = [new ItemComprobante('LAP-01', 'Laptop Corporativa', 1, 1000.00)];
        $factura = new Comprobante(
            new SerieCorrelativo('F001', 99, TipoComprobante::FACTURA),
            TipoComprobante::FACTURA,
            Cliente::conRuc('20100055237', 'ALICORP SAA'),
            $items,
            $this->calculadora->calcular($items) // Total: 1180.00
        );

        $xmlSinFirmar = $this->xmlGenerator->generarXmlUbl21($factura);
        $resultadoFirma = $this->digitalSigner->firmarXml($xmlSinFirmar);

        // Simulamos un ataque donde alguien altera el monto a pagar de 1180.00 a 10.00 después de firmado el XML
        $xmlAdulterado = str_replace('1180.00', '10.00', $resultadoFirma->xmlFirmado);

        // 1. El verificador criptográfico detecta que el hash canónico ya no coincide con la firma
        $this->assertFalse($this->digitalSigner->verificarIntegridadXml($xmlAdulterado));

        // 2. Al enviarlo al Gateway de SUNAT, devuelve rechazo fiscal 2335
        $gateway = new SunatSoapSimulatorGateway($this->digitalSigner);
        $cdr = $gateway->enviarComprobante($factura, $xmlAdulterado);

        $this->assertSame('2335', $cdr->codigo);
        $this->assertStringContainsString('alterado o la firma digital es inválida', $cdr->mensaje);
    }
}
