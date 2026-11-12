import React, { useState, useEffect } from 'react';

export default function App() {
  const [tab, setTab] = useState('facturador');
  const [productos, setProductos] = useState([]);
  const [comprobantes, setComprobantes] = useState([]);
  const [alerta, setAlerta] = useState(null);
  const [xmlModal, setXmlModal] = useState(null);

  // Formulario de Emisión
  const [tipoComprobante, setTipoComprobante] = useState('01');
  const [serie, setSerie] = useState('F001');
  const [clienteTipoDoc, setClienteTipoDoc] = useState('6');
  const [clienteNumDoc, setClienteNumDoc] = useState('20100047218');
  const [clienteRazonSocial, setClienteRazonSocial] = useState('BANCO DE CREDITO DEL PERU');
  const [moneda, setMoneda] = useState('PEN');
  const [tipoCambio, setTipoCambio] = useState(3.78);
  const [descuentoGlobal, setDescuentoGlobal] = useState(0);
  const [porcentajeDetraccion, setPorcentajeDetraccion] = useState(0);
  const [modoSimulacionSunat, setModoSimulacionSunat] = useState('NORMAL');
  const [carrito, setCarrito] = useState([]);

  // Consola PHPUnit
  const [phpunitOutput, setPhpunitOutput] = useState('Haz clic en cualquiera de las Suites de arriba para ejecutar PHPUnit en tiempo real...');
  const [ejecutandoTests, setEjecutandoTests] = useState(false);

  const cargarEstado = async () => {
    try {
      const res = await fetch('/api/estado');
      const data = await res.json();
      if (data.ok) {
        setProductos(data.productos);
        setComprobantes(data.comprobantes);
        if (carrito.length === 0 && data.productos.length > 0) {
          const prodInicial = data.productos[0];
          setCarrito([
            {
              codigo: prodInicial.codigo,
              descripcion: prodInicial.descripcion,
              cantidad: 1,
              valor_unitario: prodInicial.valor_unitario_sin_igv,
              tipo_afectacion: prodInicial.tipo_afectacion,
              descuento_porcentaje: 0,
              aplica_icbper: prodInicial.afecto_icbper,
              unidad_medida: prodInicial.unidad_medida,
            },
          ]);
        }
      }
    } catch (err) {
      setAlerta({ tipo: 'error', texto: 'No se pudo conectar con el Backend PHP en el puerto 8000.' });
    }
  };

  useEffect(() => {
    cargarEstado();
  }, []);

  const handleCambiarTipoComprobante = (nuevoTipo) => {
    setTipoComprobante(nuevoTipo);
    if (nuevoTipo === '01') {
      setSerie('F001');
      setClienteTipoDoc('6');
      setClienteNumDoc('20100047218');
      setClienteRazonSocial('BANCO DE CREDITO DEL PERU');
    } else {
      setSerie('B001');
      setClienteTipoDoc('1');
      setClienteNumDoc('72345678');
      setClienteRazonSocial('JUAN PEREZ GOMEZ');
    }
  };

  const agregarAlCarrito = (prod) => {
    setCarrito((prev) => {
      const existe = prev.find((i) => i.codigo === prod.codigo);
      if (existe) {
        return prev.map((i) => (i.codigo === prod.codigo ? { ...i, cantidad: i.cantidad + 1 } : i));
      }
      return [
        ...prev,
        {
          codigo: prod.codigo,
          descripcion: prod.descripcion,
          cantidad: 1,
          valor_unitario: prod.valor_unitario_sin_igv,
          tipo_afectacion: prod.tipo_afectacion,
          descuento_porcentaje: 0,
          aplica_icbper: prod.afecto_icbper,
          unidad_medida: prod.unidad_medida,
        },
      ];
    });
  };

  const emitirComprobante = async (e) => {
    e.preventDefault();
    setAlerta(null);
    try {
      const res = await fetch('/api/emitir', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          tipo_comprobante: tipoComprobante,
          serie,
          cliente_tipo_doc: clienteTipoDoc,
          cliente_num_doc: clienteNumDoc,
          cliente_razon_social: clienteRazonSocial,
          moneda,
          tipo_cambio: moneda === 'PEN' ? 1.0 : Number(tipoCambio),
          descuento_global: Number(descuentoGlobal),
          porcentaje_detraccion: Number(porcentajeDetraccion),
          modo_simulacion_sunat: modoSimulacionSunat,
          items: carrito,
        }),
      });
      const data = await res.json();
      if (!data.ok) {
        setAlerta({ tipo: 'error', texto: `[${data.tipo_error}] ${data.error}` });
      } else {
        const comp = data.comprobante;
        setAlerta({
          tipo: comp.estado === 'RECHAZADO' ? 'error' : 'success',
          texto: `Comprobante ${comp.numero_completo} procesado -> Estado: ${comp.estado} (${comp.mensaje_sunat})`,
        });
        cargarEstado();
      }
    } catch (err) {
      setAlerta({ tipo: 'error', texto: err.message });
    }
  };

  const emitirNotaCredito = async (numeroOrigen) => {
    const sustento = window.prompt(
      `Emitir Nota de Crédito (Devolución Total) para ${numeroOrigen}.\nIngrese el sustento legal (mín. 5 caracteres):`,
      'Devolución de mercadería por falla técnica'
    );
    if (!sustento) return;

    try {
      const res = await fetch('/api/nota-credito', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          comprobante_origen: numeroOrigen,
          motivo: '07',
          sustento,
        }),
      });
      const data = await res.json();
      if (!data.ok) {
        setAlerta({ tipo: 'error', texto: `[${data.tipo_error}] ${data.error}` });
      } else {
        setAlerta({
          tipo: 'success',
          texto: `Nota de Crédito ${data.comprobante.numero_completo} emitida. ¡El stock ha sido restaurado al Kardex!`,
        });
        cargarEstado();
      }
    } catch (err) {
      setAlerta({ tipo: 'error', texto: err.message });
    }
  };

  const darDeBaja = async (numero) => {
    const motivo = window.prompt(
      `Comunicación de Baja (RA) ante SUNAT para ${numero}.\nIngrese el motivo de anulación:`,
      'Error en digitación de orden de compra'
    );
    if (!motivo) return;

    try {
      const res = await fetch('/api/baja', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ numero_comprobante: numero, motivo_baja: motivo }),
      });
      const data = await res.json();
      if (!data.ok) {
        setAlerta({ tipo: 'error', texto: `[${data.tipo_error}] ${data.error}` });
      } else {
        setAlerta({
          tipo: 'success',
          texto: `Comprobante ${numero} anulado por Comunicación de Baja. ¡Stock restaurado en el Kardex!`,
        });
        cargarEstado();
      }
    } catch (err) {
      setAlerta({ tipo: 'error', texto: err.message });
    }
  };

  const ejecutarSuitePhpunit = async (suite = '') => {
    setEjecutandoTests(true);
    try {
      const res = await fetch('/api/ejecutar-phpunit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ suite }),
      });
      const data = await res.json();
      setPhpunitOutput(data.output || 'Sin salida');
    } catch (err) {
      setPhpunitOutput('Error al ejecutar PHPUnit: ' + err.message);
    } finally {
      setEjecutandoTests(false);
    }
  };

  return (
    <div className="container">
      <header className="header">
        <div>
          <h1>S-Facturación Electrónica UBL 2.1 (SUNAT)</h1>
          <p>Arquitectura Hexagonal en PHP 8.2 + Motor Tributario + Firma XMLDSig SHA-256 + Suite PHPUnit 10</p>
        </div>
        <div className="tabs">
          <button
            className={`tab-btn ${tab === 'facturador' ? 'active' : ''}`}
            onClick={() => setTab('facturador')}
          >
            Emitir CPE & Kardex
          </button>
          <button
            className={`tab-btn ${tab === 'phpunit' ? 'active' : ''}`}
            onClick={() => {
              setTab('phpunit');
              ejecutarSuitePhpunit('');
            }}
          >
            Consola Auditoría PHPUnit (36 Tests)
          </button>
        </div>
      </header>

      {alerta && (
        <div className={`alert ${alerta.tipo === 'error' ? 'alert-error' : 'alert-success'}`}>
          <strong>{alerta.tipo === 'error' ? 'Regla Tributaria / Error: ' : 'SUNAT OSE: '}</strong>
          {alerta.texto}
        </div>
      )}

      {tab === 'facturador' ? (
        <>
          <div className="grid-2">
            {/* Formulario de Emisión */}
            <div className="card">
              <h2>Emisión de Comprobante Electrónico (Factura / Boleta)</h2>
              <form onSubmit={emitirComprobante}>
                <div className="form-row">
                  <div>
                    <label>Tipo de Comprobante</label>
                    <select
                      value={tipoComprobante}
                      onChange={(e) => handleCambiarTipoComprobante(e.target.value)}
                    >
                      <option value="01">01 - Factura Electrónica</option>
                      <option value="03">03 - Boleta de Venta</option>
                    </select>
                  </div>
                  <div>
                    <label>Serie SUNAT</label>
                    <input value={serie} onChange={(e) => setSerie(e.target.value)} />
                  </div>
                  <div>
                    <label>Moneda</label>
                    <select value={moneda} onChange={(e) => setMoneda(e.target.value)}>
                      <option value="PEN">PEN - Soles (S/)</option>
                      <option value="USD">USD - Dólares ($)</option>
                    </select>
                  </div>
                  {moneda === 'USD' && (
                    <div>
                      <label>Tipo de Cambio</label>
                      <input
                        type="number"
                        step="0.001"
                        value={tipoCambio}
                        onChange={(e) => setTipoCambio(e.target.value)}
                      />
                    </div>
                  )}
                </div>

                <div className="form-row">
                  <div>
                    <label>Tipo Doc. Cliente</label>
                    <select value={clienteTipoDoc} onChange={(e) => setClienteTipoDoc(e.target.value)}>
                      <option value="6">6 - RUC (Módulo 11)</option>
                      <option value="1">1 - DNI (8 dígitos)</option>
                      <option value="0">0 - Clientes Varios</option>
                    </select>
                  </div>
                  <div>
                    <label>N° Documento</label>
                    <input
                      value={clienteNumDoc}
                      onChange={(e) => setClienteNumDoc(e.target.value)}
                      placeholder="Ej. 20100047218"
                    />
                  </div>
                  <div>
                    <label>Razón Social / Nombre</label>
                    <input
                      value={clienteRazonSocial}
                      onChange={(e) => setClienteRazonSocial(e.target.value)}
                    />
                  </div>
                </div>

                <div className="form-row">
                  <div>
                    <label>Descuento Global (%)</label>
                    <input
                      type="number"
                      min="0"
                      max="99"
                      value={descuentoGlobal}
                      onChange={(e) => setDescuentoGlobal(e.target.value)}
                    />
                  </div>
                  <div>
                    <label>Detracción SPOT (%)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={porcentajeDetraccion}
                      onChange={(e) => setPorcentajeDetraccion(e.target.value)}
                    />
                  </div>
                  <div>
                    <label>Simular Escenario OSE/SUNAT</label>
                    <select
                      value={modoSimulacionSunat}
                      onChange={(e) => setModoSimulacionSunat(e.target.value)}
                    >
                      <option value="NORMAL">CDR 0: Aceptado Normal</option>
                      <option value="OBSERVADO">CDR 0: Aceptado con Observación</option>
                      <option value="RECHAZO_FISCAL">CDR 2800: Rechazo Fiscal (Rollback Stock)</option>
                      <option value="CAIDA_RED">HTTP 504: Caída de Red (Pasa a Contingencia)</option>
                    </select>
                  </div>
                </div>

                <h2 style={{ marginTop: 18 }}>Detalle de Ítems del Comprobante</h2>
                <table>
                  <thead>
                    <tr>
                      <th>Código</th>
                      <th>Descripción</th>
                      <th>Cant.</th>
                      <th>V. Unit (Sin IGV)</th>
                      <th>Afectación IGV</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {carrito.map((item, idx) => (
                      <tr key={item.codigo}>
                        <td>{item.codigo}</td>
                        <td>{item.descripcion}</td>
                        <td>
                          <input
                            type="number"
                            min="1"
                            style={{ width: 70 }}
                            value={item.cantidad}
                            onChange={(e) => {
                              const val = Number(e.target.value);
                              setCarrito((prev) =>
                                prev.map((it, i) => (i === idx ? { ...it, cantidad: val } : it))
                              );
                            }}
                          />
                        </td>
                        <td>{item.valor_unitario.toFixed(2)}</td>
                        <td>
                          <select
                            value={item.tipo_afectacion}
                            onChange={(e) => {
                              const val = e.target.value;
                              setCarrito((prev) =>
                                prev.map((it, i) => (i === idx ? { ...it, tipo_afectacion: val } : it))
                              );
                            }}
                          >
                            <option value="10">10 - Gravado (18%)</option>
                            <option value="20">20 - Exonerado (0%)</option>
                            <option value="30">30 - Inafecto (0%)</option>
                            <option value="11">11 - Gratuito (Premio)</option>
                          </select>
                        </td>
                        <td>
                          <button
                            type="button"
                            className="btn btn-danger"
                            style={{ padding: '4px 10px' }}
                            onClick={() => setCarrito((prev) => prev.filter((_, i) => i !== idx))}
                          >
                            X
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                <div style={{ marginTop: 18, display: 'flex', justifyContent: 'flex-end' }}>
                  <button type="submit" className="btn btn-primary">
                    Firmar XML UBL 2.1 y Emitir a SUNAT
                  </button>
                </div>
              </form>
            </div>

            {/* Catálogo y Kardex en Tiempo Real */}
            <div className="card">
              <h2>Catálogo e Inventario Kardex en Tiempo Real</h2>
              <p style={{ fontSize: '0.83rem', color: '#94a3b8', marginBottom: 12 }}>
                Haz clic en <strong>+ Agregar</strong> para añadir al comprobante. Observa cómo el stock se descuenta al emitir, se revierte si SUNAT rechaza y se restaura con Notas de Crédito o Bajas.
              </p>
              <table>
                <thead>
                  <tr>
                    <th>Código</th>
                    <th>Producto / Servicio</th>
                    <th>V. Unit</th>
                    <th>Stock</th>
                    <th>Acción</th>
                  </tr>
                </thead>
                <tbody>
                  {productos.map((p) => (
                    <tr key={p.codigo}>
                      <td><strong>{p.codigo}</strong></td>
                      <td>
                        {p.descripcion}
                        {p.afecto_icbper && (
                          <span className="badge badge-OBSERVADO" style={{ marginLeft: 6 }}>
                            +ICBPER
                          </span>
                        )}
                      </td>
                      <td>S/ {p.valor_unitario_sin_igv.toFixed(2)}</td>
                      <td>
                        <span className="badge badge-ACEPTADO">
                          {p.unidad_medida === 'ZZ' ? 'SERVICIO' : `${p.stock_actual} und.`}
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          className="btn btn-success"
                          style={{ padding: '5px 10px', fontSize: '0.78rem' }}
                          onClick={() => agregarAlCarrito(p)}
                        >
                          + Agregar
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Listado de Comprobantes Emitidos */}
          <div className="card">
            <h2>Comprobantes Electrónicos Emitidos (Ciclo de Vida CPE, XML UBL 2.1 y CDR)</h2>
            <table>
              <thead>
                <tr>
                  <th>Numeración</th>
                  <th>Tipo</th>
                  <th>Cliente</th>
                  <th>Gravado</th>
                  <th>IGV (18%)</th>
                  <th>Total</th>
                  <th>Saldo NC</th>
                  <th>Estado SUNAT</th>
                  <th>Acciones Tributarias</th>
                </tr>
              </thead>
              <tbody>
                {comprobantes.length === 0 ? (
                  <tr>
                    <td colSpan="9" style={{ textAlign: 'center', color: '#94a3b8', padding: 20 }}>
                      Aún no hay comprobantes emitidos. Emite una Factura o Boleta arriba para ver su XML UBL 2.1 y su CDR.
                    </td>
                  </tr>
                ) : (
                  comprobantes.map((c) => (
                    <tr key={c.numero_completo}>
                      <td>
                        <strong>{c.numero_completo}</strong>
                        {c.comprobante_referencia && (
                          <div style={{ fontSize: '0.75rem', color: '#94a3b8' }}>
                            Ref: {c.comprobante_referencia}
                          </div>
                        )}
                      </td>
                      <td>{c.tipo_nombre}</td>
                      <td>
                        {c.cliente.razon_social} ({c.cliente.num_doc})
                      </td>
                      <td>{c.moneda} {c.resumen.total_gravadas.toFixed(2)}</td>
                      <td>{c.moneda} {c.resumen.total_igv.toFixed(2)}</td>
                      <td><strong>{c.moneda} {c.resumen.importe_total.toFixed(2)}</strong></td>
                      <td>{c.moneda} {c.saldo_disponible_nc.toFixed(2)}</td>
                      <td>
                        <span className={`badge badge-${c.estado}`}>{c.estado}</span>
                        <div style={{ fontSize: '0.74rem', color: '#94a3b8', marginTop: 3 }}>
                          {c.mensaje_sunat}
                        </div>
                      </td>
                      <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <button
                          className="btn btn-primary"
                          style={{ padding: '5px 9px', fontSize: '0.75rem' }}
                          onClick={() => setXmlModal(c)}
                        >
                          Ver XML & Hash
                        </button>
                        {(c.estado === 'ACEPTADO' || c.estado === 'OBSERVADO') && c.tipo !== '07' && (
                          <>
                            <button
                              className="btn btn-warning"
                              style={{ padding: '5px 9px', fontSize: '0.75rem' }}
                              onClick={() => emitirNotaCredito(c.numero_completo)}
                            >
                              Emitir NC (07)
                            </button>
                            <button
                              className="btn btn-danger"
                              style={{ padding: '5px 9px', fontSize: '0.75rem' }}
                              onClick={() => darDeBaja(c.numero_completo)}
                            >
                              Dar de Baja
                            </button>
                          </>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </>
      ) : (
        <div className="card">
          <h2>Consola de Ejecución en Vivo de PHPUnit 10.5 (Pirámide de Testing)</h2>
          <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
            <button
              className="btn btn-primary"
              disabled={ejecutandoTests}
              onClick={() => ejecutarSuitePhpunit('')}
            >
              Ejecutar TODAS las Suites (36 Tests)
            </button>
            <button
              className="btn btn-success"
              disabled={ejecutandoTests}
              onClick={() => ejecutarSuitePhpunit('Unit')}
            >
              Suite Unitaria (Dominio, RUC Módulo 11, Cálculos)
            </button>
            <button
              className="btn btn-warning"
              disabled={ejecutandoTests}
              onClick={() => ejecutarSuitePhpunit('Integration')}
            >
              Suite Integración (XML UBL 2.1, Firma SHA-256, SQLite)
            </button>
            <button
              className="btn btn-danger"
              disabled={ejecutandoTests}
              onClick={() => ejecutarSuitePhpunit('Feature')}
            >
              Suite Feature (Mocks SUNAT, Rollback Stock, NC y Bajas)
            </button>
          </div>
          <pre className="console">{ejecutandoTests ? 'Ejecutando PHPUnit...' : phpunitOutput}</pre>
        </div>
      )}

      {/* Modal Inspector XML UBL 2.1 */}
      {xmlModal && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(2, 6, 23, 0.82)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: 24,
            zIndex: 50,
          }}
          onClick={() => setXmlModal(null)}
        >
          <div
            className="card"
            style={{ maxWidth: 920, width: '100%', margin: 0 }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
              <h2>
                XML UBL 2.1 Firmado: {xmlModal.numero_completo} | Hash CPE: {xmlModal.hash_cpe}
              </h2>
              <button className="btn btn-danger" onClick={() => setXmlModal(null)}>
                Cerrar
              </button>
            </div>
            <pre className="console">{xmlModal.xml_firmado}</pre>
          </div>
        </div>
      )}
    </div>
  );
}
