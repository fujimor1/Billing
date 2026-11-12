<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

use App\Domain\Exceptions\ReglaTributariaException;
use App\Domain\Ports\DigitalSignerInterface;
use App\Domain\Ports\ResultadoFirmaDigital;
use DOMDocument;
use DOMXPath;

/**
 * Firmador Digital XMLDSig (C14N + SHA-256)
 * Calcula el resumen criptográfico (DigestValue / Hash CPE) sobre el documento canónico UBL 2.1
 * y permite auditar si el XML fue adulterado después de su emisión.
 */
final class XmlDigitalSigner implements DigitalSignerInterface
{
    private string $claveSecretaCertificado;

    public function __construct(string $claveSecretaCertificado = 'CERTIFICADO_DIGITAL_TRIBUTARIO_SUNAT_2026_KEY')
    {
        $this->claveSecretaCertificado = $claveSecretaCertificado;
    }

    public function firmarXml(string $xmlSinFirmar): ResultadoFirmaDigital
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (!@$dom->loadXML($xmlSinFirmar)) {
            throw new ReglaTributariaException("El contenido proporcionado para firmar no es un documento XML válido.");
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $extensionContents = $xpath->query('//ext:ExtensionContent');

        if ($extensionContents === false || $extensionContents->length === 0) {
            throw new ReglaTributariaException("El XML UBL 2.1 no contiene el nodo ext:ExtensionContent requerido para alojar la firma.");
        }

        // 1. Calcular el Hash Canónico (C14N) SHA-256 del documento sin el nodo ds:Signature
        $canonicalXml = (string) $dom->C14N();
        $digestValue = base64_encode(hash('sha256', $canonicalXml, true));

        // 2. Generar SignatureValue usando HMAC-SHA256 con la llave privada del certificado del emisor
        $signatureValue = base64_encode(hash_hmac('sha256', $digestValue, $this->claveSecretaCertificado, true));

        // 3. Inyectar estructura estándar XMLDSig dentro de ext:ExtensionContent
        $extensionNode = $extensionContents->item(0);
        $signatureNode = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
        $signatureNode->setAttribute('Id', 'SignSUNAT');

        $signedInfo = $dom->createElement('ds:SignedInfo');
        $canonicalMethod = $dom->createElement('ds:CanonicalizationMethod');
        $canonicalMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalMethod);

        $sigMethod = $dom->createElement('ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($sigMethod);

        $reference = $dom->createElement('ds:Reference');
        $reference->setAttribute('URI', '');
        $digestMethod = $dom->createElement('ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);
        $reference->appendChild($dom->createElement('ds:DigestValue', $digestValue));
        $signedInfo->appendChild($reference);

        $signatureNode->appendChild($signedInfo);
        $signatureNode->appendChild($dom->createElement('ds:SignatureValue', $signatureValue));

        $extensionNode?->appendChild($signatureNode);

        return new ResultadoFirmaDigital(
            xmlFirmado: (string) $dom->saveXML(),
            hashCpe: $digestValue,
            signatureValue: $signatureValue
        );
    }

    public function verificarIntegridadXml(string $xmlFirmado): bool
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (!@$dom->loadXML($xmlFirmado)) {
            return false;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $digestNodes = $xpath->query('//ds:DigestValue');
        $signatureValueNodes = $xpath->query('//ds:SignatureValue');
        $signatureNodes = $xpath->query('//ds:Signature');

        if (
            $digestNodes === false || $digestNodes->length === 0 ||
            $signatureValueNodes === false || $signatureValueNodes->length === 0 ||
            $signatureNodes === false || $signatureNodes->length === 0
        ) {
            return false;
        }

        $digestGuardado = trim((string) $digestNodes->item(0)?->nodeValue);
        $signatureGuardada = trim((string) $signatureValueNodes->item(0)?->nodeValue);

        // Remover temporalmente el nodo ds:Signature para recalcular el C14N original del comprobante
        $sigNode = $signatureNodes->item(0);
        $sigNode?->parentNode?->removeChild($sigNode);

        $canonicalActual = (string) $dom->C14N();
        $digestCalculado = base64_encode(hash('sha256', $canonicalActual, true));
        $signatureCalculada = base64_encode(hash_hmac('sha256', $digestCalculado, $this->claveSecretaCertificado, true));

        return hash_equals($digestGuardado, $digestCalculado)
            && hash_equals($signatureGuardada, $signatureCalculada);
    }
}
