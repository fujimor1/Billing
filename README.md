# Sistema de Facturación Electrónica UBL 2.1 (SUNAT) & Suite PHPUnit

Proyecto desarrollado bajo **Arquitectura Hexagonal (Ports & Adapters)** en **PHP 8.2** y **React 18**, enfocado en pruebas de software automatizadas con **PHPUnit 10.5** (Pruebas Unitarias, Pruebas de Integración XML/Criptografía/SQLite y Pruebas Funcionales con Mocks).

## Estructura del Proyecto

- `backend/src/Domain`: Entidades (`Comprobante`, `Producto`, `Cliente`, `ItemComprobante`), Value Objects (`Ruc` con algoritmo Módulo 11, `Dni`, `Moneda`, `SerieCorrelativo`) y Servicios de Dominio (`CalculadoraTributaria`).
- `backend/src/Application`: Casos de Uso (`EmitirComprobanteUseCase`, `EmitirNotaCreditoUseCase`, `AnularComprobanteBajaUseCase`).
- `backend/src/Infrastructure`: Generador XML OASIS UBL 2.1 (`Ubl21XmlGenerator`), Firmador Criptográfico SHA-256 (`XmlDigitalSigner`), Persistencia PDO SQLite y Gateway OSE/SUNAT.
- `backend/tests`: **36 pruebas automatizadas (123 aserciones)** divididas en `Unit/`, `Integration/` y `Feature/`.
- `frontend/`: Interfaz web interactiva en React + Vite con Kardex en tiempo real, visor de XML UBL 2.1 firmado y Consola de Auditoría PHPUnit integrada.
- [GUIA_PHPUNIT.md](./GUIA_PHPUNIT.md): Documentación detallada de cómo funciona PHPUnit en este proyecto.

## Requisitos y Ejecución

1. **Instalar dependencias del Backend**:
   ```bash
   cd backend
   composer install
   ```
2. **Ejecutar la Suite de Pruebas en PHPUnit**:
   ```bash
   cd backend
   ./test.bat
   ```
3. **Iniciar el Sistema Completo (Backend PHP + Frontend React)**:
   Ejecutar `iniciar-sistema.bat` en la raíz del proyecto.
