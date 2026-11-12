<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Entities\Comprobante;

interface XmlGeneratorInterface
{
    public function generarXmlUbl21(Comprobante $comprobante): string;
}
