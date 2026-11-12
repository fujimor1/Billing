<?php

declare(strict_types=1);

namespace App\Infrastructure\Xml;

use App\Domain\Entities\Comprobante;
use App\Domain\Enums\TipoComprobante;
use App\Domain\Ports\XmlGeneratorInterface;
use DOMDocument;

/**
 * Generador de XML bajo el estándar OASIS UBL 2.1 exigido por SUNAT (Perú)
 */
final class Ubl21XmlGenerator implements XmlGeneratorInterface
{
    private const RUC_EMISOR = '20131312955';
    private const RAZON_SOCIAL_EMISOR = 'TECH SOLUTIONS PERU S.A.C.';

    public function generarXmlUbl21(Comprobante $comprobante): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $esNotaCredito = $comprobante->tipo() === TipoComprobante::NOTA_CREDITO;
        $rootTag = $esNotaCredito ? 'CreditNote' : 'Invoice';
        $rootNs = $esNotaCredito
            ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'
            : 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

        $root = $dom->createElementNS($rootNs, $rootTag);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $dom->appendChild($root);

        // 1. Contenedor UBLExtensions para alojar la Firma Digital XMLDSig
        $ublExtensions = $dom->createElement('ext:UBLExtensions');
        $ublExtension = $dom->createElement('ext:UBLExtension');
        $extensionContent = $dom->createElement('ext:ExtensionContent');
        $ublExtension->appendChild($extensionContent);
        $ublExtensions->appendChild($ublExtension);
        $root->appendChild($ublExtensions);

        // 2. Cabecera Estándar UBL 2.1
        $root->appendChild($dom->createElement('cbc:UBLVersionID', '2.1'));
        $root->appendChild($dom->createElement('cbc:CustomizationID', '2.0'));
        $root->appendChild($dom->createElement('cbc:ID', $comprobante->numeroCompleto()));
        $root->appendChild($dom->createElement('cbc:IssueDate', $comprobante->fechaEmision()->format('Y-m-d')));
        $root->appendChild($dom->createElement('cbc:IssueTime', $comprobante->fechaEmision()->format('H:i:s')));

        if (!$esNotaCredito) {
            $typeCode = $dom->createElement('cbc:InvoiceTypeCode', $comprobante->tipo()->value);
            $typeCode->setAttribute('listID', '0101');
            $root->appendChild($typeCode);
        }

        $root->appendChild($dom->createElement('cbc:DocumentCurrencyCode', $comprobante->moneda()->codigo()));

        // Si es Nota de Crédito, agregar DiscrepancyResponse y BillingReference
        if ($esNotaCredito) {
            $discrepancy = $dom->createElement('cac:DiscrepancyResponse');
            $discrepancy->appendChild($dom->createElement('cbc:ReferenceID', (string) $comprobante->comprobanteReferencia()));
            $discrepancy->appendChild($dom->createElement('cbc:ResponseCode', (string) $comprobante->motivoNotaCredito()?->value));
            $discrepancy->appendChild($dom->createElement('cbc:Description', htmlspecialchars((string) $comprobante->descripcionMotivoNota())));
            $root->appendChild($discrepancy);

            $billingRef = $dom->createElement('cac:BillingReference');
            $invoiceDocRef = $dom->createElement('cac:InvoiceDocumentReference');
            $invoiceDocRef->appendChild($dom->createElement('cbc:ID', (string) $comprobante->comprobanteReferencia()));
            $billingRef->appendChild($invoiceDocRef);
            $root->appendChild($billingRef);
        }

        // 3. Datos del Emisor (AccountingSupplierParty)
        $supplierParty = $dom->createElement('cac:AccountingSupplierParty');
        $partyEmisor = $dom->createElement('cac:Party');
        $partyIdEmisor = $dom->createElement('cac:PartyIdentification');
        $idEmisor = $dom->createElement('cbc:ID', self::RUC_EMISOR);
        $idEmisor->setAttribute('schemeID', '6');
        $partyIdEmisor->appendChild($idEmisor);
        $partyEmisor->appendChild($partyIdEmisor);

        $legalEmisor = $dom->createElement('cac:PartyLegalEntity');
        $legalEmisor->appendChild($dom->createElement('cbc:RegistrationName', self::RAZON_SOCIAL_EMISOR));
        $partyEmisor->appendChild($legalEmisor);
        $supplierParty->appendChild($partyEmisor);
        $root->appendChild($supplierParty);

        // 4. Datos del Cliente Receptor (AccountingCustomerParty)
        $customerParty = $dom->createElement('cac:AccountingCustomerParty');
        $partyCliente = $dom->createElement('cac:Party');
        $partyIdCliente = $dom->createElement('cac:PartyIdentification');
        $idCliente = $dom->createElement('cbc:ID', $comprobante->cliente()->numeroDocumento());
        $idCliente->setAttribute('schemeID', $comprobante->cliente()->tipoDocumento());
        $partyIdCliente->appendChild($idCliente);
        $partyCliente->appendChild($partyIdCliente);

        $legalCliente = $dom->createElement('cac:PartyLegalEntity');
        $legalCliente->appendChild($dom->createElement('cbc:RegistrationName', htmlspecialchars($comprobante->cliente()->razonSocial())));
        $partyCliente->appendChild($legalCliente);
        $customerParty->appendChild($partyCliente);
        $root->appendChild($customerParty);

        // 5. Desglose de Impuestos (TaxTotal)
        $resumen = $comprobante->resumenImpuestos();
        $monedaCodigo = $comprobante->moneda()->codigo();

        $taxTotal = $dom->createElement('cac:TaxTotal');
        $taxAmount = $dom->createElement('cbc:TaxAmount', number_format($resumen->totalImpuestos, 2, '.', ''));
        $taxAmount->setAttribute('currencyID', $monedaCodigo);
        $taxTotal->appendChild($taxAmount);

        if ($resumen->totalGravadas > 0) {
            $taxTotal->appendChild($this->crearTaxSubtotal($dom, $monedaCodigo, $resumen->totalGravadas, $resumen->totalIgv, '1000', 'IGV', 'VAT'));
        }
        if ($resumen->totalExoneradas > 0) {
            $taxTotal->appendChild($this->crearTaxSubtotal($dom, $monedaCodigo, $resumen->totalExoneradas, 0.00, '9997', 'EXO', 'VAT'));
        }
        if ($resumen->totalInafectas > 0) {
            $taxTotal->appendChild($this->crearTaxSubtotal($dom, $monedaCodigo, $resumen->totalInafectas, 0.00, '9998', 'INA', 'FRE'));
        }
        if ($resumen->totalIcbper > 0) {
            $taxTotal->appendChild($this->crearTaxSubtotal($dom, $monedaCodigo, 0.00, $resumen->totalIcbper, '7152', 'ICBPER', 'OTH'));
        }
        $root->appendChild($taxTotal);

        // 6. Totales Monetarios (LegalMonetaryTotal)
        $monetaryTag = $esNotaCredito ? 'cac:LegalMonetaryTotal' : 'cac:LegalMonetaryTotal';
        $monetaryTotal = $dom->createElement($monetaryTag);

        $lineExt = $dom->createElement('cbc:LineExtensionAmount', number_format($resumen->valorVentaNeto, 2, '.', ''));
        $lineExt->setAttribute('currencyID', $monedaCodigo);
        $monetaryTotal->appendChild($lineExt);

        $payable = $dom->createElement('cbc:PayableAmount', number_format($resumen->importeTotal, 2, '.', ''));
        $payable->setAttribute('currencyID', $monedaCodigo);
        $monetaryTotal->appendChild($payable);
        $root->appendChild($monetaryTotal);

        // 7. Líneas de Detalle (InvoiceLine / CreditNoteLine)
        $lineTag = $esNotaCredito ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $qtyTag = $esNotaCredito ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';

        foreach ($comprobante->items() as $idx => $item) {
            $line = $dom->createElement($lineTag);
            $line->appendChild($dom->createElement('cbc:ID', (string) ($idx + 1)));

            $qty = $dom->createElement($qtyTag, number_format($item->cantidad(), 2, '.', ''));
            $qty->setAttribute('unitCode', $item->unidadMedida());
            $line->appendChild($qty);

            $lineAmount = $dom->createElement('cbc:LineExtensionAmount', number_format($item->valorVentaItem(), 2, '.', ''));
            $lineAmount->setAttribute('currencyID', $monedaCodigo);
            $line->appendChild($lineAmount);

            $itemNode = $dom->createElement('cac:Item');
            $itemNode->appendChild($dom->createElement('cbc:Description', htmlspecialchars($item->descripcion())));
            $sellersId = $dom->createElement('cac:SellersItemIdentification');
            $sellersId->appendChild($dom->createElement('cbc:ID', htmlspecialchars($item->codigoProducto())));
            $itemNode->appendChild($sellersId);
            $line->appendChild($itemNode);

            $priceNode = $dom->createElement('cac:Price');
            $priceAmount = $dom->createElement('cbc:PriceAmount', number_format($item->valorUnitarioSinIgv(), 2, '.', ''));
            $priceAmount->setAttribute('currencyID', $monedaCodigo);
            $priceNode->appendChild($priceAmount);
            $line->appendChild($priceNode);

            $root->appendChild($line);
        }

        return (string) $dom->saveXML();
    }

    private function crearTaxSubtotal(
        DOMDocument $dom,
        string $moneda,
        float $baseImponible,
        float $impuesto,
        string $codigoTributo,
        string $nombreTributo,
        string $codigoInternacional
    ): \DOMElement {
        $subtotal = $dom->createElement('cac:TaxSubtotal');

        if ($baseImponible > 0) {
            $taxable = $dom->createElement('cbc:TaxableAmount', number_format($baseImponible, 2, '.', ''));
            $taxable->setAttribute('currencyID', $moneda);
            $subtotal->appendChild($taxable);
        }

        $taxAmt = $dom->createElement('cbc:TaxAmount', number_format($impuesto, 2, '.', ''));
        $taxAmt->setAttribute('currencyID', $moneda);
        $subtotal->appendChild($taxAmt);

        $category = $dom->createElement('cac:TaxCategory');
        $scheme = $dom->createElement('cac:TaxScheme');
        $scheme->appendChild($dom->createElement('cbc:ID', $codigoTributo));
        $scheme->appendChild($dom->createElement('cbc:Name', $nombreTributo));
        $scheme->appendChild($dom->createElement('cbc:TaxTypeCode', $codigoInternacional));
        $category->appendChild($scheme);
        $subtotal->appendChild($category);

        return $subtotal;
    }
}
