# Guía Completa de Testing con PHPUnit en el Sistema de Facturación Electrónica (UBL 2.1 - SUNAT)

Esta guía está diseñada para explicar en clase **cómo funciona PHPUnit internamente**, **qué técnicas de ingeniería de pruebas aplicamos** y **qué audita exactamente cada test en nuestro código**.

---

## 1. ¿Cómo funciona PHPUnit paso a paso?

Cuando ejecutas `.\test.bat` (o `vendor/bin/phpunit`), ocurre el siguiente ciclo automatizado en menos de **60 milisegundos**:

```text
[1. Bootstrap] -> [2. Descubrimiento] -> [3. Ciclo setUp()] -> [4. Ejecución (AAA)] -> [5. Verificación]
```

1. **Bootstrap (`vendor/autoload.php`)**: PHPUnit lee el archivo `backend/phpunit.xml` y activa el autocargador PSR-4 de Composer para encontrar todas las clases de `src/` (`App\`) y `tests/` (`Tests\`).
2. **Separación por Suites (`TestSuites`)**: En `phpunit.xml` dividimos las pruebas siguiendo la **Pirámide de Testing**:
   - **Unit (`tests/Unit`)**: Pruebas aisladas en memoria de reglas matemáticas y tributarias puras (0 ms de red o disco).
   - **Integration (`tests/Integration`)**: Pruebas que combinan múltiples componentes técnicos reales (Generador DOM XML UBL 2.1 + Criptografía SHA-256 + Base de datos SQLite en memoria `:memory:`).
   - **Feature (`tests/Feature`)**: Pruebas de Casos de Uso completos de principio a fin usando **Mocks (Objetos Simulados)** para suplantar los servidores de SUNAT.
3. **Aislamiento con `setUp()`**: Antes de **cada** método `test...()`, PHPUnit ejecuta automáticamente `protected function setUp(): void`. Esto garantiza que cada prueba empiece con objetos limpios y una base de datos en memoria recién creada, evitando que un test contamine a otro.
4. **Patrón AAA (Arrange - Act - Assert)**: Todos nuestros tests están escritos bajo el estándar de la industria:
   - **Arrange (Preparar)**: Instanciamos los productos, clientes, monedas o configuramos el Mock de SUNAT.
   - **Act (Actuar)**: Ejecutamos el método o caso de uso (`$calculadora->calcular(...)` o `$useCase->ejecutar(...)`).
   - **Assert (Afirmar/Verificar)**: Comprobamos con métodos como `assertSame()`, `assertTrue()`, `expectException()` que el resultado real sea idéntico al esperado por ley tributaria.

---

## 2. Técnicas Avanzadas de PHPUnit Aplicadas en Nuestro Código

### A. Data Providers (`#[DataProvider('...')]`) — *Partición de Equivalencia*
En lugar de escribir 10 funciones repetidas para probar distintos RUCs, usamos el atributo `#[DataProvider]` en `tests/Unit/ValueObjectsTest.php`.
- **¿Cómo funciona?**: PHPUnit llama a una función estática que devuelve una matriz de casos de prueba y ejecuta el mismo test una vez por cada fila.
- **¿Qué hace en nuestro código?**: Alimenta a la clase `Ruc` con RUCs reales de personas jurídicas (`20131312955` - SUNAT, `20100047218` - BCP), personas naturales (`10456789124`) y con RUCs fraudulentos (con letras, prefijo `99`, o con el último dígito verificador alterado) para comprobar que el **algoritmo Módulo 11 de SUNAT** detecte cualquier RUC falso.

### B. Pruebas de Excepciones (`expectException` y `expectExceptionMessage`)
En facturación electrónica, tan importante como calcular bien es **impedir operaciones ilegales**.
- **¿Cómo funciona?**: Le decimos a PHPUnit antes de ejecutar la acción: *"Espero que la siguiente línea lance la excepción `ReglaTributariaException` con el código de error de SUNAT"*. Si el código **no** lanza la excepción, el test **falla**.
- **¿Qué hace en nuestro código?**:
  - Verifica que si alguien intenta emitir una **Factura (`01`) con un DNI**, el sistema la bloquee inmediatamente (`Error SUNAT 2017`).
  - Verifica que si una **Boleta (`03`) supera los S/ 700.00** y el cliente es *"Clientes Varios (Sin DNI)"*, se bloquee la emisión (`Error SUNAT 2511`).
  - Verifica que no se pueda dar de baja una factura pasados los **7 días calendario** (`Anulación extemporánea`).

### C. Mocks y Spies (`$this->createMock(SunatGatewayInterface::class)`) — *Doble de Prueba*
Esta es la parte más importante para exponer ante el profesor en `tests/Feature/FacturacionElectronicaMocksFeatureTest.php`.
- **El Problema Real**: Al ejecutar pruebas automatizadas no podemos enviar facturas falsas al servidor real de SUNAT ni desconectar el cable de internet para probar qué pasa cuando SUNAT se cae.
- **Cómo lo resuelve PHPUnit**: Con `$this->createMock(SunatGatewayInterface::class)`, PHPUnit crea en memoria un **objeto clon suplantador** de la interfaz de SUNAT y nos permite programarle respuestas y espiar cuántas veces fue llamado:
  1. **Simulación de Aceptación (`willReturn`)**: Configuramos el Mock para que responda `Código 0 (Aceptado)`. Comprobamos que la factura pase a `ACEPTADO` y que el stock en el Kardex baje de `15` a `10`.
  2. **Simulación de Caída de Red (`willThrowException`)**: Le ordenamos al Mock que lance un `RuntimeException('SOAP Fault: Connection timed out')`. Comprobamos que nuestro sistema capture el fallo sin romperse y guarde la factura en estado `CONTINGENCIA` (`NETWORK_TIMEOUT`) para reenviarla luego.
  3. **Simulación de Rechazo Fiscal y Rollback (`Código 2800`)**: Le ordenamos al Mock que devuelva rechazo por *"Contribuyente en Baja de Oficio"*. Comprobamos que el caso de uso marque la factura como `RECHAZADO` y **ejecute un Rollback automático devolviendo las 10 unidades al Kardex**.
  4. **Espía de No-Invocación (`$this->never()`)**: Cuando intentamos vender 50 laptops pero solo hay 15 en stock, usamos `$sunatMock->expects($this->never())->method('enviarComprobante')` para demostrar que el sistema aborta antes y **jamás** gasta recursos llamando a SUNAT.

---

## 3. Mapa Detallado de las 3 Suites y sus 36 Tests

### Suite 1: Pruebas Unitarias (`backend/tests/Unit/`)

| Archivo de Test | Clase que Audita | Qué comprueba exactamente |
| :--- | :--- | :--- |
| `ValueObjectsTest.php` | `Ruc`, `Dni`, `SerieCorrelativo`, `Moneda` | • Algoritmo Módulo 11 de RUC (10 tests con DataProvider).<br>• Rechazo de DNI `00000000`.<br>• Relleno a 8 ceros de correlativos (`F001-00000045`).<br>• Coherencia serie/tipo (`F` para Factura, `B` para Boleta).<br>• Conversión de moneda `USD` a `PEN` con tipo de cambio. |
| `CalculadoraTributariaTest.php` | `CalculadoraTributaria`, `ItemComprobante` | • Cálculo exacto de IGV (18%) y base imponible.<br>• **Factura Mixta Compleja**: combina ítems Gravados con descuento + Exonerados (libros/Amazonía) + Gratuitos (bonificaciones que no se cobran al cliente pero declaran IGV referencial) + Bolsas plásticas afectas a **ICBPER (S/ 0.50 c/u)**.<br>• Cálculo automático de **Detracción SPOT (12%)** cuando el servicio supera S/ 700.00 (incluso facturado en Dólares). |
| `ComprobanteDomainRulesTest.php` | `Comprobante` | • Reglas SUNAT 2017 y 2511.<br>• Control de saldo disponible cuando se aplican múltiples **Notas de Crédito parciales** (impide devolver más dinero del que queda en la factura original).<br>• Plazo legal de 7 días para **Comunicaciones de Baja**. |

---

### Suite 2: Pruebas de Integración (`backend/tests/Integration/`)

| Archivo de Test | Componentes que Integra | Qué comprueba exactamente |
| :--- | :--- | :--- |
| `XmlAndCryptoIntegrationTest.php` | `Ubl21XmlGenerator` + `XmlDigitalSigner` + `SunatSoapSimulatorGateway` | • Genera el XML estándar **OASIS UBL 2.1** real y lo inspecciona con consultas **XPath** (`cbc:UBLVersionID`, `cac:TaxTotal`, `cac:LegalMonetaryTotal`).<br>• Firma el XML con **Canonicalización C14N + SHA-256** (`ds:DigestValue` y `ds:SignatureValue`).<br>• **Test de Ciberseguridad / Adulteración**: Modifica maliciosamente el total de `1180.00` a `10.00` dentro del XML ya firmado y demuestra que `verificarIntegridadXml()` devuelve `false` y SUNAT lo bloquea con **Error 2335**. |
| `SqlitePersistenceIntegrationTest.php` | `SqliteConnection (:memory:)` + Repositorios PDO | • Crea las tablas SQL en memoria RAM, siembra el catálogo inicial, descuenta stock y persiste comprobantes verificando que la numeración correlativa (`F001-00000001`, `F001-00000002`) sea independiente por cada serie. |

---

### Suite 3: Pruebas Funcionales / Casos de Uso (`backend/tests/Feature/`)

| Archivo de Test | Casos de Uso que Orquesta | Qué comprueba exactamente |
| :--- | :--- | :--- |
| `FacturacionElectronicaMocksFeatureTest.php` | `EmitirComprobanteUseCase`, `EmitirNotaCreditoUseCase`, `AnularComprobanteBajaUseCase` | • **Caso 1**: Emisión exitosa con Mock de SUNAT y descuento de Kardex.<br>• **Caso 2**: Caída de red de SUNAT -> paso automático a estado `CONTINGENCIA`.<br>• **Caso 3**: Rechazo fiscal de SUNAT -> **Rollback automático del stock** en BD.<br>• **Caso 4**: Ciclo completo *Factura -> Nota de Crédito por devolución* -> restaura el stock devuelto al Kardex y reduce el saldo de la factura original.<br>• **Caso 5**: *Comunicación de Baja* dentro del plazo -> genera Ticket SUNAT, cambia estado a `ANULADO_BAJA` y repone el stock.<br>• **Caso 6**: Bloqueo por falta de stock antes de contactar a SUNAT. |

---

## 4. Comandos Rápidos para tu Exposición en Clase

Desde la carpeta `backend/`:

```powershell
# 1. Ejecutar las 36 pruebas con reporte legible (TestDox)
.\test.bat

# 2. Ejecutar solo la Suite Unitaria
.\test.bat --testsuite Unit

# 3. Ejecutar solo la Suite de Integración (XML UBL 2.1, Firma SHA-256 y SQLite)
.\test.bat --testsuite Integration

# 4. Ejecutar solo la Suite Feature (Mocks de SUNAT, Notas de Crédito, Bajas y Kardex)
.\test.bat --testsuite Feature
```
