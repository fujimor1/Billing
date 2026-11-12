<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateways;

use App\Domain\Entities\Comprobante;
use App\Domain\Ports\DigitalSignerInterface;
use App\Domain\Ports\RespuestaSunatCdr;
use App\Domain\Ports\SunatGatewayInterface;
use RuntimeException;

/**
 * Simulador realista del Web Service OSE / SUNAT (BillService)
 * Verifica la integridad criptográfica del XML recibido y genera la Constancia de Recepción (CDR).
 * También permite simular escenarios de pruebas desde el Frontend (Aceptado, Observado, Rechazado o Caída de Red).
 */
final class SunatSoapSimulatorGateway implements SunatGatewayInterface
{
    public function __construct(
        private DigitalSignerInterface $digitalSigner,
        private string $modoSimulacion = 'NORMAL' // NORMAL, OBSERVADO, RECHAZO_FISCAL, CAIDA_RED
    ) {
    }

    public function setModoSimulacion(string $modo): void
    {
        $this->modoSimulacion = strtoupper(trim($modo));
    }

    public function enviarComprobante(Comprobante $comprobante, string $xmlFirmado): RespuestaSunatCdr
    {
        if ($this->modoSimulacion === 'CAIDA_RED') {
            throw new RuntimeException(
                "Error SOAP Fault [HTTP 504 Gateway Timeout]: El servicio de recepción de SUNAT no responde."
            );
        }

        // 1. El OSE/SUNAT verifica que la firma digital XMLDSig sea íntegra y no haya sido alterada
        if (!$this->digitalSigner->verificarIntegridadXml($xmlFirmado)) {
            return new RespuestaSunatCdr(
                codigo: '2335',
                mensaje: 'El documento electrónico ha sido alterado o la firma digital es inválida (Error SUNAT 2335).'
            );
        }

        if ($this->modoSimulacion === 'RECHAZO_FISCAL') {
            return new RespuestaSunatCdr(
                codigo: '2800',
                mensaje: "Rechazo SUNAT (2800): El contribuyente receptor se encuentra en estado BAJA DE OFICIO."
            );
        }

        $numero = $comprobante->numeroCompleto();
        $nombreTipo = $comprobante->tipo()->nombre();
        $xmlCdr = $this->generarXmlApplicationResponseCdr($numero, '0', "La {$nombreTipo} número {$numero}, ha sido aceptada");

        if ($this->modoSimulacion === 'OBSERVADO') {
            return new RespuestaSunatCdr(
                codigo: '0',
                mensaje: "La {$nombreTipo} número {$numero}, ha sido aceptada con observaciones",
                xmlCdr: $xmlCdr,
                observaciones: ['OBS-4201: El código de ubigeo del cliente no fue especificado, se asume predeterminado.']
            );
        }

        return new RespuestaSunatCdr(
            codigo: '0',
            mensaje: "La {$nombreTipo} número {$numero}, ha sido aceptada",
            xmlCdr: $xmlCdr
        );
    }

    public function comunicarBaja(Comprobante $comprobante, string $motivoBaja): string
    {
        if ($this->modoSimulacion === 'CAIDA_RED') {
            throw new RuntimeException("Timeout al intentar generar Ticket de Baja en SUNAT.");
        }

        return '2026' . str_pad((string) abs(crc32($comprobante->numeroCompleto() . $motivoBaja)), 9, '0', STR_PAD_LEFT);
    }

    private function generarXmlApplicationResponseCdr(string $numeroComprobante, string $codigo, string $descripcion): string
    {
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ar:ApplicationResponse xmlns:ar="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"
                        xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
                        xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:UBLVersionID>2.0</cbc:UBLVersionID>
    <cbc:CustomizationID>1.0</cbc:CustomizationID>
    <cbc:ID>R-{$numeroComprobante}</cbc:ID>
    <cbc:IssueDate>{$fecha}</cbc:IssueDate>
    <cbc:IssueTime>{$hora}</cbc:IssueTime>
    <cac:DocumentResponse>
        <cac:Response>
            <cbc:ReferenceID>{$numeroComprobante}</cbc:ReferenceID>
            <cbc:ResponseCode>{$codigo}</cbc:ResponseCode>
            <cbc:Description>{$descripcion}</cbc:Description>
        </cac:Response>
    </cac:DocumentResponse>
</ar:ApplicationResponse>
XML;
    }
}
