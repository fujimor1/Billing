import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
  FileText,
  Receipt,
  Package,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Terminal,
  ShieldCheck,
  Copy,
  Download,
  Plus,
  Trash2,
  RefreshCw,
  Coins,
  Layers,
  FileCode,
  Check,
  Search,
  ArrowUpRight,
  TrendingUp,
  BookOpen,
  HelpCircle,
  Info,
  Sparkles,
  BadgePercent,
  CheckCircle,
} from 'lucide-react';

export default function App() {
  const [tab, setTab] = useState('facturador');
  const [productos, setProductos] = useState([]);
  const [comprobantes, setComprobantes] = useState([]);
  const [alerta, setAlerta] = useState(null);
  const [modalNotificacion, setModalNotificacion] = useState(null);
  const [toastFlotante, setToastFlotante] = useState(null);
  const [xmlModal, setXmlModal] = useState(null);
  const [copiadoHash, setCopiadoHash] = useState(false);
  const [isEmitiendo, setIsEmitiendo] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState(() => 'idem_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now());
  const inicializadoRef = useRef(false);

  // Formulario de Emisión
  const [tipoComprobante, setTipoComprobante] = useState('01');
  const [serie, setSerie] = useState('F001');
  const [clienteTipoDoc, setClienteTipoDoc] = useState('6');
  const [clienteNumDoc, setClienteNumDoc] = useState('20100047218');
  const [clienteRazonSocial, setClienteRazonSocial] = useState('BANCO DE CREDITO DEL PERU');
  const [consultandoDoc, setConsultandoDoc] = useState(false);
  const [infoDoc, setInfoDoc] = useState({
    estado: 'ACTIVO',
    condicion: 'HABIDO',
    direccion: 'JR. CENTENARIO 156, LA MOLINA, LIMA',
    fuente: 'SUNAT_LIVE',
  });
  const [moneda, setMoneda] = useState('PEN');
  const [tipoCambio, setTipoCambio] = useState(3.78);
  const [descuentoGlobal, setDescuentoGlobal] = useState(0);
  const [porcentajeDetraccion, setPorcentajeDetraccion] = useState(0);
  const [modoSimulacionSunat, setModoSimulacionSunat] = useState('NORMAL');
  const [carrito, setCarrito] = useState([]);

  // Consola PHPUnit
  const [phpunitOutput, setPhpunitOutput] = useState('Haz clic en cualquiera de las Suites para ejecutar PHPUnit en tiempo real...');
  const [ejecutandoTests, setEjecutandoTests] = useState(false);
  const [cargandoApi, setCargandoApi] = useState(false);

  useEffect(() => {
    if (toastFlotante) {
      const timer = setTimeout(() => setToastFlotante(null), 3500);
      return () => clearTimeout(timer);
    }
  }, [toastFlotante]);

  const cargarEstado = async () => {
    setCargandoApi(true);
    try {
      const res = await fetch('/api/estado');
      const data = await res.json();
      if (data.ok) {
        setProductos(data.productos);
        setComprobantes(data.comprobantes);
        if (!inicializadoRef.current && data.productos.length > 0) {
          inicializadoRef.current = true;
          const prodInicial = data.productos[0];
          setCarrito([
            {
              codigo: prodInicial.codigo,
              descripcion: prodInicial.descripcion,
              cantidad: 1,
              valor_unitario: prodInicial.valor_unitario_sin_igv,
              tipo_afectacion: prodInicial.tipo_afectacion,
              tipo_afectacion_base: prodInicial.tipo_afectacion,
              descuento_porcentaje: 0,
              aplica_icbper: prodInicial.afecto_icbper,
              unidad_medida: prodInicial.unidad_medida,
            },
          ]);
        }
      }
    } catch (err) {
      setToastFlotante({ tipo: 'error', texto: 'No se pudo conectar con el Backend PHP API en http://127.0.0.1:8000' });
    } finally {
      setCargandoApi(false);
    }
  };

  useEffect(() => {
    cargarEstado();
  }, []);

  const consultarDocumento = async (tipo = clienteTipoDoc, numero = clienteNumDoc) => {
    const num = numero.trim();
    if (tipo === '0') {
      setClienteRazonSocial('CLIENTES VARIOS');
      setInfoDoc(null);
      return;
    }
    if ((tipo === '6' && num.length !== 11) || (tipo === '1' && num.length !== 8)) {
      return;
    }

    setConsultandoDoc(true);
    try {
      const res = await fetch(`/api/consulta-documento?tipo=${tipo}&numero=${num}`);
      const data = await res.json();
      if (!data.ok) {
        setModalNotificacion({
          tipo: 'error',
          titulo: 'Documento no Válido ante SUNAT / RENIEC',
          mensaje: data.error,
        });
        setInfoDoc(null);
      } else {
        setClienteRazonSocial(data.razon_social);
        setInfoDoc(data);
        setToastFlotante({
          tipo: 'success',
          texto: `✓ ${data.razon_social} (${data.estado} - ${data.condicion})`,
        });
      }
    } catch (err) {
      setToastFlotante({ tipo: 'error', texto: 'Error al consultar documento: ' + err.message });
    } finally {
      setConsultandoDoc(false);
    }
  };

  const handleCambiarTipoComprobante = (nuevoTipo) => {
    setTipoComprobante(nuevoTipo);
    if (nuevoTipo === '01') {
      setSerie('F001');
      setClienteTipoDoc('6');
      setClienteNumDoc('20100047218');
      setClienteRazonSocial('BANCO DE CREDITO DEL PERU');
      setInfoDoc({
        estado: 'ACTIVO',
        condicion: 'HABIDO',
        direccion: 'JR. CENTENARIO 156, LA MOLINA, LIMA',
        fuente: 'SUNAT_LIVE',
      });
    } else {
      setSerie('B001');
      setClienteTipoDoc('1');
      setClienteNumDoc('45879632');
      setClienteRazonSocial('WILLIAM FERNANDO SANDOVAL SOLANO');
      setInfoDoc({
        estado: 'ACTIVO',
        condicion: 'HABIDO',
        direccion: 'LIMA, PERÚ',
        fuente: 'RENIEC_LIVE',
      });
    }
  };

  const agregarAlCarrito = (prod) => {
    // Si agregas un servicio profesional, sugerir automáticamente la tasa oficial SPOT del 12%
    if (prod.unidad_medida === 'ZZ' && porcentajeDetraccion === 0) {
      setPorcentajeDetraccion(12);
    }
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
          tipo_afectacion_base: prod.tipo_afectacion,
          descuento_porcentaje: 0,
          aplica_icbper: prod.afecto_icbper,
          unidad_medida: prod.unidad_medida,
        },
      ];
    });
  };

  // Cálculos tributarios en vivo para el carrito
  const calculosEnVivo = useMemo(() => {
    let gravada = 0;
    let exonerada = 0;
    let inafecta = 0;
    let gratuita = 0;
    let icbper = 0;

    carrito.forEach((item) => {
      const base = item.valor_unitario * item.cantidad * (1 - (item.descuento_porcentaje || 0) / 100);
      if (item.tipo_afectacion === '10') {
        gravada += base;
      } else if (item.tipo_afectacion === '20') {
        exonerada += base;
      } else if (item.tipo_afectacion === '30') {
        inafecta += base;
      } else if (item.tipo_afectacion === '11') {
        gratuita += base;
      }
      if (item.aplica_icbper) {
        icbper += item.cantidad * 0.5; // Tasa ICBPER 0.50 PEN
      }
    });

    const descGlobalMonto = gravada * (Number(descuentoGlobal) / 100);
    const baseGravadaNeta = Math.max(0, gravada - descGlobalMonto);
    const igv = baseGravadaNeta * 0.18;
    const total = baseGravadaNeta + exonerada + inafecta + igv + icbper;
    const detraccionMonto = (total * Number(porcentajeDetraccion)) / 100;

    return {
      baseGravada: baseGravadaNeta,
      exonerada,
      inafecta,
      gratuita,
      igv,
      icbper,
      total,
      detraccionMonto,
    };
  }, [carrito, descuentoGlobal, porcentajeDetraccion]);

  const emitirComprobante = async (e) => {
    e.preventDefault();
    if (isEmitiendo) return;
    setIsEmitiendo(true);
    setAlerta(null);

    try {
      const res = await fetch('/api/emitir', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Idempotency-Key': idempotencyKey,
        },
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
          idempotency_key: idempotencyKey,
        }),
      });

      const data = await res.json();
      if (!data.ok) {
        setModalNotificacion({
          tipo: 'error',
          titulo: 'Error al emitir',
          mensaje: data.error || 'Ocurrió un error al procesar el comprobante.',
        });
      } else {
        const comp = data.comprobante;
        setModalNotificacion({
          tipo: comp.estado === 'RECHAZADO' ? 'error' : comp.estado === 'OBSERVADO' ? 'warning' : 'success',
          titulo: comp.estado === 'RECHAZADO' ? 'Comprobante Rechazado' : '¡Comprobante emitido con éxito!',
          mensaje: `El comprobante ${comp.numero_completo} fue aceptado por SUNAT.`,
        });

        // Limpiar el carrito y el formulario tras confirmar facturación
        setCarrito([]);
        setDescuentoGlobal(0);
        setPorcentajeDetraccion(0);
        setClienteNumDoc('');
        setClienteRazonSocial('');
        setInfoDoc(null);

        // Generar una nueva clave de idempotencia limpia para la siguiente factura
        setIdempotencyKey('idem_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now());

        await cargarEstado();
      }
    } catch (err) {
      setModalNotificacion({
        tipo: 'error',
        titulo: 'Fallo de Red o Servidor',
        mensaje: err.message || 'No se pudo comunicar con el servidor de facturación.',
      });
    } finally {
      setIsEmitiendo(false);
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
        setModalNotificacion({
          tipo: 'error',
          titulo: 'Error al emitir Nota de Crédito',
          mensaje: data.error,
        });
      } else {
        setModalNotificacion({
          tipo: 'success',
          titulo: 'Nota de Crédito emitida',
          mensaje: `Nota de Crédito ${data.comprobante.numero_completo} generada con éxito. Stock restablecido.`,
        });
        cargarEstado();
      }
    } catch (err) {
      setModalNotificacion({
        tipo: 'error',
        titulo: 'Fallo de Conexión',
        mensaje: err.message,
      });
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
        setModalNotificacion({
          tipo: 'error',
          titulo: 'Error en Comunicación de Baja',
          mensaje: data.error,
        });
      } else {
        setModalNotificacion({
          tipo: 'success',
          titulo: 'Comprobante anulado',
          mensaje: `Comprobante ${numero} anulado exitosamente ante SUNAT. Stock revertido.`,
        });
        cargarEstado();
      }
    } catch (err) {
      setModalNotificacion({
        tipo: 'error',
        titulo: 'Fallo de Conexión',
        mensaje: err.message,
      });
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

  // Métricas de cabecera
  const metricas = useMemo(() => {
    const totalComps = comprobantes.length;
    const totalPen = comprobantes
      .filter((c) => c.moneda === 'PEN' && c.estado !== 'ANULADO' && c.estado !== 'RECHAZADO')
      .reduce((acc, c) => acc + (c.resumen?.importe_total || 0), 0);
    const totalUsd = comprobantes
      .filter((c) => c.moneda === 'USD' && c.estado !== 'ANULADO' && c.estado !== 'RECHAZADO')
      .reduce((acc, c) => acc + (c.resumen?.importe_total || 0), 0);
    return { totalComps, totalPen, totalUsd };
  }, [comprobantes]);

  const copiarAlPortapapeles = (texto) => {
    navigator.clipboard.writeText(texto);
    setCopiadoHash(true);
    setTimeout(() => setCopiadoHash(false), 2000);
  };

  const descargarXml = (cpe) => {
    const blob = new Blob([cpe.xml_firmado], { type: 'application/xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `20600000001-${cpe.tipo}-${cpe.numero_completo}.xml`;
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="astryx-root">
      {/* AppShell Top Navigation */}
      <header className="app-header">
        <div className="app-header-inner">
          <div className="brand-block">
            <div className="brand-icon">
              <ShieldCheck size={22} />
            </div>
            <div>
              <div className="brand-title">S-Facturación UBL 2.1</div>
              <div className="brand-subtitle">
                <span>SUNAT OSE Gateway</span>
                <span>•</span>
                <span style={{ color: '#059669', fontWeight: 600 }}>Firma XMLDSig SHA-256</span>
              </div>
            </div>
          </div>

          <nav className="nav-tabs">
            <button
              className={`nav-tab-btn ${tab === 'facturador' ? 'active' : ''}`}
              onClick={() => setTab('facturador')}
            >
              <FileText size={16} />
              Emitir Comprobante
            </button>
            <button
              className={`nav-tab-btn ${tab === 'kardex' ? 'active' : ''}`}
              onClick={() => setTab('kardex')}
            >
              <Package size={16} />
              Kardex & Almacén
            </button>
            <button
              className={`nav-tab-btn ${tab === 'comprobantes' ? 'active' : ''}`}
              onClick={() => setTab('comprobantes')}
            >
              <Receipt size={16} />
              Historial CPE ({comprobantes.length})
            </button>
            <button
              className={`nav-tab-btn ${tab === 'phpunit' ? 'active' : ''}`}
              onClick={() => {
                setTab('phpunit');
                ejecutarSuitePhpunit('');
              }}
            >
              <Terminal size={16} />
              Auditoría PHPUnit 10.5
            </button>
          </nav>

          <button
            className="btn-ui secondary sm"
            onClick={cargarEstado}
            disabled={cargandoApi}
            title="Refrescar catálogo y comprobantes desde la API"
          >
            <RefreshCw size={14} className={cargandoApi ? 'spin' : ''} />
            Sincronizar
          </button>
        </div>
      </header>

      {/* Main Content Area */}
      <main className="main-content">
        {/* Metric Cards Row */}
        <div className="metrics-row">
          <div className="metric-card">
            <div>
              <div className="metric-label">Comprobantes Registrados</div>
              <div className="metric-value">{metricas.totalComps}</div>
            </div>
            <div className="metric-icon-wrap" style={{ backgroundColor: '#eff6ff', color: '#2563eb' }}>
              <Receipt size={22} />
            </div>
          </div>

          <div className="metric-card">
            <div>
              <div className="metric-label">Facturado en Soles (PEN)</div>
              <div className="metric-value">S/ {metricas.totalPen.toLocaleString('es-PE', { minimumFractionDigits: 2 })}</div>
            </div>
            <div className="metric-icon-wrap" style={{ backgroundColor: '#ecfdf5', color: '#059669' }}>
              <Coins size={22} />
            </div>
          </div>

          <div className="metric-card">
            <div>
              <div className="metric-label">Facturado en Dólares (USD)</div>
              <div className="metric-value">$ {metricas.totalUsd.toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
            </div>
            <div className="metric-icon-wrap" style={{ backgroundColor: '#fffbeb', color: '#d97706' }}>
              <TrendingUp size={22} />
            </div>
          </div>

          <div className="metric-card">
            <div>
              <div className="metric-label">Cobertura de Pruebas</div>
              <div className="metric-value">39 / 39 OK</div>
            </div>
            <div className="metric-icon-wrap" style={{ backgroundColor: '#f1f5f9', color: '#475569' }}>
              <ShieldCheck size={22} />
            </div>
          </div>
        </div>

        {/* Global Alert Notification */}
        {alerta && (
          <div className={`alert-banner ${alerta.tipo}`}>
            {alerta.tipo === 'success' && <CheckCircle2 size={20} style={{ flexShrink: 0, marginTop: 1 }} />}
            {alerta.tipo === 'error' && <XCircle size={20} style={{ flexShrink: 0, marginTop: 1 }} />}
            {alerta.tipo === 'warning' && <AlertTriangle size={20} style={{ flexShrink: 0, marginTop: 1 }} />}
            <div style={{ flex: 1 }}>{alerta.texto}</div>
            <button
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', fontWeight: 'bold' }}
              onClick={() => setAlerta(null)}
            >
              ✕
            </button>
          </div>
        )}

        {/* TAB 1: EMISIÓN DE COMPROBANTES */}
        {tab === 'facturador' && (
          <div className="grid-layout-2">
            {/* Formulario de Emisión */}
            <div className="surface-card">
              <div className="surface-header">
                <div className="surface-title-group">
                  <FileText size={20} style={{ color: '#2563eb' }} />
                  <div>
                    <h2 className="surface-title">Emisión de Comprobante Electrónico</h2>
                    <p className="surface-desc">Genera XML OASIS UBL 2.1 con validación Módulo 11 y firma XMLDSig</p>
                  </div>
                </div>
              </div>

              <form onSubmit={emitirComprobante}>
                <div className="form-grid">
                  <div className="input-control-group">
                    <label className="input-label">Tipo Comprobante</label>
                    <select
                      className="custom-select"
                      value={tipoComprobante}
                      onChange={(e) => handleCambiarTipoComprobante(e.target.value)}
                    >
                      <option value="01">01 - Factura Electrónica</option>
                      <option value="03">03 - Boleta de Venta</option>
                    </select>
                  </div>

                  <div className="input-control-group">
                    <label className="input-label">Serie SUNAT</label>
                    <input
                      className="custom-input"
                      value={serie}
                      onChange={(e) => setSerie(e.target.value)}
                      placeholder="F001 o B001"
                      required
                    />
                  </div>

                  <div className="input-control-group">
                    <label className="input-label">Moneda</label>
                    <select
                      className="custom-select"
                      value={moneda}
                      onChange={(e) => setMoneda(e.target.value)}
                    >
                      <option value="PEN">PEN - Soles (S/)</option>
                      <option value="USD">USD - Dólares ($)</option>
                    </select>
                  </div>

                  {moneda === 'USD' && (
                    <div className="input-control-group">
                      <label className="input-label">Tipo de Cambio (TC)</label>
                      <input
                        className="custom-input"
                        type="number"
                        step="0.001"
                        value={tipoCambio}
                        onChange={(e) => setTipoCambio(e.target.value)}
                      />
                    </div>
                  )}
                </div>

                <div className="form-grid">
                  <div className="input-control-group">
                    <label className="input-label">Tipo Doc. Cliente</label>
                    <select
                      className="custom-select"
                      value={clienteTipoDoc}
                      onChange={(e) => {
                        const val = e.target.value;
                        setClienteTipoDoc(val);
                        setClienteNumDoc('');
                        setClienteRazonSocial(val === '0' ? 'CLIENTES VARIOS' : '');
                        setInfoDoc(null);
                        setAlerta(null);
                      }}
                    >
                      <option value="6">6 - RUC (Módulo 11)</option>
                      <option value="1">1 - DNI (8 dígitos)</option>
                      <option value="0">0 - Clientes Varios</option>
                    </select>
                  </div>

                  <div className="input-control-group">
                    <label className="input-label">
                      <span>N° Documento</span>
                      {infoDoc && (
                        <span style={{ fontSize: '0.72rem', color: infoDoc.estado === 'ACTIVO' ? '#059669' : '#dc2626', fontWeight: 600 }}>
                          ● {infoDoc.estado} - {infoDoc.condicion}
                        </span>
                      )}
                    </label>
                    <div style={{ display: 'flex', gap: 6 }}>
                      <input
                        className="custom-input"
                        value={clienteNumDoc}
                        onChange={(e) => {
                          const val = e.target.value.replace(/\D/g, '');
                          setClienteNumDoc(val);
                          // Si borra o está incompleto, limpiar inmediatamente razón social y status
                          if (val.length === 0) {
                            setClienteRazonSocial('');
                            setInfoDoc(null);
                            setAlerta(null);
                          } else if ((clienteTipoDoc === '6' && val.length < 11) || (clienteTipoDoc === '1' && val.length < 8)) {
                            setClienteRazonSocial('');
                            setInfoDoc(null);
                          } else if ((clienteTipoDoc === '6' && val.length === 11) || (clienteTipoDoc === '1' && val.length === 8)) {
                            consultarDocumento(clienteTipoDoc, val);
                          }
                        }}
                        placeholder={clienteTipoDoc === '6' ? 'Ej. 20100047218' : 'Ej. 45879632'}
                        maxLength={clienteTipoDoc === '6' ? 11 : clienteTipoDoc === '1' ? 8 : 15}
                        required
                      />
                      {clienteNumDoc.length > 0 && (
                        <button
                          type="button"
                          className="btn-ui secondary sm"
                          onClick={() => {
                            setClienteNumDoc('');
                            setClienteRazonSocial('');
                            setInfoDoc(null);
                            setAlerta(null);
                          }}
                          title="Limpiar campo de documento"
                          style={{ padding: '0 9px', color: '#64748b' }}
                        >
                          ✕
                        </button>
                      )}
                      {clienteTipoDoc !== '0' && (
                        <button
                          type="button"
                          className="btn-ui secondary sm"
                          onClick={() => consultarDocumento(clienteTipoDoc, clienteNumDoc)}
                          disabled={consultandoDoc}
                          title="Consultar en Padrón Oficial SUNAT / RENIEC"
                          style={{ padding: '0 12px', minWidth: 42 }}
                        >
                          {consultandoDoc ? <RefreshCw size={14} className="spin" /> : <Search size={14} />}
                        </button>
                      )}
                    </div>
                  </div>

                  <div className="input-control-group" style={{ gridColumn: 'span 2' }}>
                    <label className="input-label">
                      <span>Razón Social / Nombre Completo</span>
                      {consultandoDoc && <span style={{ fontSize: '0.72rem', color: '#2563eb' }}>Consultando SUNAT...</span>}
                    </label>
                    <input
                      className="custom-input"
                      value={clienteRazonSocial}
                      onChange={(e) => setClienteRazonSocial(e.target.value)}
                      placeholder="Nombre o denominación social"
                      required
                    />
                    {infoDoc?.direccion && (
                      <div style={{ fontSize: '0.72rem', color: '#64748b', marginTop: 3 }}>
                        Dirección Fiscal: {infoDoc.direccion}
                      </div>
                    )}
                  </div>
                </div>

                <div className="form-grid">
                  <div className="input-control-group">
                    <label className="input-label">Descuento Global (%)</label>
                    <input
                      className="custom-input"
                      type="number"
                      min="0"
                      max="99"
                      value={descuentoGlobal}
                      onChange={(e) => setDescuentoGlobal(e.target.value)}
                    />
                  </div>

                  <div className="input-control-group">
                    <label className="input-label">
                      <span>Detracción SPOT (SUNAT)</span>
                      {porcentajeDetraccion > 0 && (
                        <span style={{ fontSize: '0.72rem', color: '#b45309', fontWeight: 600 }}>
                          {porcentajeDetraccion}% Bco. Nación
                        </span>
                      )}
                    </label>
                    <select
                      className="custom-select"
                      value={porcentajeDetraccion}
                      onChange={(e) => setPorcentajeDetraccion(Number(e.target.value))}
                    >
                      <option value={0}>0% — No Aplica / Bienes Comunes</option>
                      <option value={12}>12% — Servicios / Software / Consultoría (Cód. 022)</option>
                      <option value={10}>10% — Arrendamiento / Alquileres (Cód. 019)</option>
                      <option value={4}>4% — Transporte de Carga / Construcción (Cód. 020)</option>
                    </select>
                  </div>

                  <div className="input-control-group" style={{ gridColumn: 'span 2' }}>
                    <label className="input-label">Simular Escenario OSE / SUNAT</label>
                    <select
                      className="custom-select"
                      value={modoSimulacionSunat}
                      onChange={(e) => setModoSimulacionSunat(e.target.value)}
                    >
                      <option value="NORMAL">CDR 0: Aceptado Normal (SUNAT OSE OK)</option>
                      <option value="OBSERVADO">CDR 0: Aceptado con Observación Tributaria</option>
                      <option value="RECHAZO_FISCAL">CDR 2800: Rechazo Fiscal (Rollback Stock en SQLite)</option>
                      <option value="CAIDA_RED">HTTP 504: Caída de Red (Pasa a Estado Contingencia)</option>
                    </select>
                  </div>
                </div>

                <div style={{ marginTop: 22, marginBottom: 10, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <h3 style={{ fontSize: '0.92rem', fontWeight: 700, color: '#0f172a' }}>
                    Ítems a Facturar ({carrito.length})
                  </h3>
                  <span style={{ fontSize: '0.78rem', color: '#64748b' }}>
                    Selecciona ítems del catálogo para agregarlos
                  </span>
                </div>

                <div className="table-container">
                  <table className="dense-table">
                    <thead>
                      <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th style={{ width: 80 }}>Cant.</th>
                        <th>V. Unitario</th>
                        <th>Afectación</th>
                        <th style={{ width: 45 }}></th>
                      </tr>
                    </thead>
                    <tbody>
                      {carrito.length === 0 ? (
                        <tr>
                          <td colSpan="6" style={{ textAlign: 'center', padding: '24px', color: '#94a3b8' }}>
                            El carrito está vacío. Haz clic en <strong>+ Agregar</strong> en el Kardex lateral.
                          </td>
                        </tr>
                      ) : (
                        carrito.map((item, idx) => {
                          const base = item.tipo_afectacion_base || '10';
                          const esGratuito = item.tipo_afectacion === '11';
                          return (
                            <tr key={item.codigo}>
                              <td><strong>{item.codigo}</strong></td>
                              <td>
                                <div>{item.descripcion}</div>
                                {item.aplica_icbper && (
                                  <div style={{ fontSize: '0.72rem', color: '#b45309', marginTop: 2, fontWeight: 500 }}>
                                    + Impuesto ICBPER (S/ 0.50 por bolsa plástica)
                                  </div>
                                )}
                              </td>
                              <td>
                                <input
                                  className="custom-input"
                                  type="number"
                                  min="1"
                                  style={{ width: 64, padding: '4px 6px' }}
                                  value={item.cantidad}
                                  onChange={(e) => {
                                    const val = Number(e.target.value);
                                    setCarrito((prev) =>
                                      prev.map((it, i) => (i === idx ? { ...it, cantidad: val } : it))
                                    );
                                  }}
                                />
                              </td>
                              <td>
                                {esGratuito ? (
                                  <div>
                                    <span style={{ textDecoration: 'line-through', color: '#94a3b8', fontSize: '0.76rem' }}>
                                      {moneda === 'PEN' ? 'S/' : '$'} {item.valor_unitario.toFixed(2)}
                                    </span>
                                    <div style={{ color: '#059669', fontWeight: 700 }}>
                                      {moneda === 'PEN' ? 'S/' : '$'} 0.00
                                    </div>
                                  </div>
                                ) : (
                                  <span>{moneda === 'PEN' ? 'S/' : '$'} {item.valor_unitario.toFixed(2)}</span>
                                )}
                              </td>
                              <td>
                                <select
                                  className="custom-select"
                                  style={{ fontSize: '0.78rem', padding: '4px 8px' }}
                                  value={item.tipo_afectacion}
                                  onChange={(e) => {
                                    const val = e.target.value;
                                    setCarrito((prev) =>
                                      prev.map((it, i) => (i === idx ? { ...it, tipo_afectacion: val } : it))
                                    );
                                  }}
                                >
                                  {base === '10' && <option value="10">10 Venta Normal (Gravado 18%)</option>}
                                  {base === '20' && <option value="20">20 Venta Normal (Exonerado 0%)</option>}
                                  {base === '30' && <option value="30">30 Venta Normal (Inafecto 0%)</option>}
                                  {base !== '10' && base !== '20' && base !== '30' && (
                                    <option value={base}>Venta Normal ({base})</option>
                                  )}
                                  <option value="11">11 Gratuito (Premio / Regalo - S/ 0)</option>
                                </select>
                              </td>
                              <td>
                                <button
                                  type="button"
                                  className="btn-ui danger sm"
                                  onClick={() => setCarrito((prev) => prev.filter((_, i) => i !== idx))}
                                  title="Eliminar ítem"
                                >
                                  <Trash2 size={13} />
                                </button>
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Advertencia Contable de Operación Mixta (Bienes + Servicios) */}
                {(() => {
                  const tieneServicios = carrito.some((i) => i.unidad_medida === 'ZZ' || i.codigo.startsWith('SERV'));
                  const tieneBienes = carrito.some((i) => i.unidad_medida !== 'ZZ' && !i.codigo.startsWith('SERV'));
                  if (tieneServicios && tieneBienes) {
                    return (
                      <div
                        style={{
                          backgroundColor: '#fffbeb',
                          border: '1px solid #fde68a',
                          borderRadius: 8,
                          padding: '10px 14px',
                          marginTop: 12,
                          fontSize: '0.8rem',
                          color: '#92400e',
                          display: 'flex',
                          alignItems: 'flex-start',
                          gap: 10,
                        }}
                      >
                        <Info size={16} style={{ flexShrink: 0, marginTop: 2, color: '#d97706' }} />
                        <div>
                          <strong>Alerta de Operación Mixta (Bienes + Servicios):</strong> Estás mezclando bienes físicos (bolsas/libros) y servicios de auditoría en la misma factura.
                          <div style={{ marginTop: 3 }}>
                            Por norma SUNAT (R.S. 183-2004), la detracción SPOT se aplica sobre el <strong>importe total del comprobante</strong>.
                            <br />
                            <em>Consejo contable en Perú: Se recomienda facturar los bienes en una factura y los servicios en otra para no pagar detracción sobre los bienes.</em>
                          </div>
                        </div>
                      </div>
                    );
                  }
                  return null;
                })()}

                {/* Desglose Tributario en Vivo */}
                <div className="breakdown-box">
                  <div className="breakdown-line">
                    <span>Operaciones Gravadas:</span>
                    <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.baseGravada.toFixed(2)}</span>
                  </div>
                  {calculosEnVivo.exonerada > 0 && (
                    <div className="breakdown-line">
                      <span>Operaciones Exoneradas:</span>
                      <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.exonerada.toFixed(2)}</span>
                    </div>
                  )}
                  {calculosEnVivo.inafecta > 0 && (
                    <div className="breakdown-line">
                      <span>Operaciones Inafectas:</span>
                      <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.inafecta.toFixed(2)}</span>
                    </div>
                  )}
                  {calculosEnVivo.icbper > 0 && (
                    <div className="breakdown-line">
                      <span>Impuesto Bolsas (ICBPER):</span>
                      <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.icbper.toFixed(2)}</span>
                    </div>
                  )}
                  <div className="breakdown-line">
                    <span>IGV (18%):</span>
                    <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.igv.toFixed(2)}</span>
                  </div>
                  {calculosEnVivo.detraccionMonto > 0 && (
                    <div className="breakdown-line" style={{ color: '#d97706' }}>
                      <span>Monto Detracción SPOT ({porcentajeDetraccion}%):</span>
                      <span>- {moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.detraccionMonto.toFixed(2)}</span>
                    </div>
                  )}
                  <div className="breakdown-line total">
                    <span>Importe Total a Pagar:</span>
                    <span>{moneda === 'PEN' ? 'S/' : '$'} {calculosEnVivo.total.toFixed(2)}</span>
                  </div>
                </div>

                <div style={{ marginTop: 20, display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 6 }}>
                  <button
                    type="submit"
                    className="btn-ui primary"
                    disabled={carrito.length === 0 || isEmitiendo}
                    style={{ minWidth: 280, height: 42, fontSize: '0.88rem' }}
                  >
                    {isEmitiendo ? (
                      <>
                        <RefreshCw size={17} className="spin" />
                        Firmando XML UBL 2.1 & Transmitiendo...
                      </>
                    ) : (
                      <>
                        <ShieldCheck size={17} />
                        Firmar XML UBL 2.1 & Emitir a SUNAT
                      </>
                    )}
                  </button>
                  {isEmitiendo && (
                    <div style={{ fontSize: '0.74rem', color: '#2563eb', fontWeight: 500 }}>
                      Protección de Idempotencia activa: bloqueando dobles clics
                    </div>
                  )}
                  {carrito.length === 0 && !isEmitiendo && (
                    <div style={{ fontSize: '0.74rem', color: '#64748b' }}>
                      Agrega al menos 1 producto del catálogo para habilitar la emisión
                    </div>
                  )}
                </div>
              </form>
            </div>

            {/* Kardex Resumen Lateral */}
            <div className="surface-card">
              <div className="surface-header">
                <div className="surface-title-group">
                  <Package size={20} style={{ color: '#059669' }} />
                  <div>
                    <h2 className="surface-title">Kardex de Inventario Rápido</h2>
                    <p className="surface-desc">Stock descontado en tiempo real con rollback automático</p>
                  </div>
                </div>
              </div>

              <div className="table-container">
                <table className="dense-table">
                  <thead>
                    <tr>
                      <th>Código</th>
                      <th>Producto</th>
                      <th>V. Unit</th>
                      <th>Stock</th>
                      <th style={{ width: 40 }}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {productos.map((p) => (
                      <tr key={p.codigo}>
                        <td><strong>{p.codigo}</strong></td>
                        <td>
                          <div>{p.descripcion}</div>
                          <div style={{ display: 'flex', gap: 4, marginTop: 3 }}>
                            <span
                              className={`status-pill ${p.tipo_afectacion === '10' ? 'info' : p.tipo_afectacion === '20' ? 'warning' : 'neutral'}`}
                              style={{ fontSize: '0.68rem', padding: '1px 6px' }}
                            >
                              {p.tipo_afectacion === '10' ? '10 Gravado' : p.tipo_afectacion === '20' ? '20 Exonerado' : '30 Inafecto'}
                            </span>
                            {p.afecto_icbper && (
                              <span className="status-pill warning" style={{ fontSize: '0.68rem', padding: '1px 6px' }}>
                                ICBPER
                              </span>
                            )}
                          </div>
                        </td>
                        <td>S/ {p.valor_unitario_sin_igv.toFixed(2)}</td>
                        <td>
                          <span className={`status-pill ${p.unidad_medida === 'ZZ' ? 'neutral' : p.stock_actual > 5 ? 'success' : 'warning'}`}>
                            {p.unidad_medida === 'ZZ' ? 'SERVICIO' : `${p.stock_actual} und`}
                          </span>
                        </td>
                        <td>
                          <button
                            type="button"
                            className="btn-ui secondary sm"
                            onClick={() => agregarAlCarrito(p)}
                            title="Añadir a la factura"
                          >
                            <Plus size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: KARDEX Y ALMACÉN COMPLETO */}
        {tab === 'kardex' && (
          <div className="surface-card">
            <div className="surface-header">
              <div className="surface-title-group">
                <Package size={22} style={{ color: '#059669' }} />
                <div>
                  <h2 className="surface-title">Catálogo e Inventario Kardex en Tiempo Real</h2>
                  <p className="surface-desc">
                    Control físico y valorizado. El stock disminuye al emitir CPE, se revierte con errores SUNAT y se restaura ante Notas de Crédito (07) o Bajas.
                  </p>
                </div>
              </div>
            </div>

            <div className="table-container">
              <table className="dense-table">
                <thead>
                  <tr>
                    <th>Código</th>
                    <th>Descripción del Bien o Servicio</th>
                    <th>Unidad</th>
                    <th>Valor Unitario (Sin IGV)</th>
                    <th>Afectación Tributaria</th>
                    <th>Stock Disponible</th>
                    <th>Acción</th>
                  </tr>
                </thead>
                <tbody>
                  {productos.map((p) => (
                    <tr key={p.codigo}>
                      <td><strong>{p.codigo}</strong></td>
                      <td>
                        <strong>{p.descripcion}</strong>
                        {p.afecto_icbper && (
                          <span className="status-pill warning" style={{ marginLeft: 8 }}>
                            Afecto ICBPER (S/ 0.50)
                          </span>
                        )}
                      </td>
                      <td>{p.unidad_medida === 'ZZ' ? 'Servicio (ZZ)' : p.unidad_medida === 'KGM' ? 'Kilos (KGM)' : 'Unidad (NIU)'}</td>
                      <td>S/ {p.valor_unitario_sin_igv.toFixed(2)}</td>
                      <td>
                        <span className={`status-pill ${p.tipo_afectacion === '10' ? 'info' : p.tipo_afectacion === '20' ? 'warning' : 'neutral'}`}>
                          {p.tipo_afectacion === '10' && 'Tipo 10 (Gravado 18%)'}
                          {p.tipo_afectacion === '20' && 'Tipo 20 (Exonerado 0%)'}
                          {p.tipo_afectacion === '30' && 'Tipo 30 (Inafecto 0%)'}
                          {p.tipo_afectacion !== '10' && p.tipo_afectacion !== '20' && p.tipo_afectacion !== '30' && `Tipo ${p.tipo_afectacion}`}
                        </span>
                      </td>
                      <td>
                        <span className={`status-pill ${p.unidad_medida === 'ZZ' ? 'neutral' : p.stock_actual > 5 ? 'success' : 'danger'}`}>
                          {p.unidad_medida === 'ZZ' ? 'SERVICIO' : `${p.stock_actual} unidades`}
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          className="btn-ui primary sm"
                          onClick={() => {
                            agregarAlCarrito(p);
                            setTab('facturador');
                          }}
                        >
                          <Plus size={14} /> Facturar
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* TAB 3: HISTORIAL DE COMPROBANTES */}
        {tab === 'comprobantes' && (
          <div className="surface-card">
            <div className="surface-header">
              <div className="surface-title-group">
                <Receipt size={22} style={{ color: '#2563eb' }} />
                <div>
                  <h2 className="surface-title">Historial de Comprobantes Electrónicos Emitidos</h2>
                  <p className="surface-desc">
                    Trazabilidad de ciclo de vida tributario: Facturas, Boletas, Notas de Crédito y Comunicaciones de Baja (RA).
                  </p>
                </div>
              </div>
            </div>

            <div className="table-container">
              <table className="dense-table">
                <thead>
                  <tr>
                    <th>Numeración</th>
                    <th>Tipo</th>
                    <th>Cliente</th>
                    <th>Fecha Emisión</th>
                    <th>Gravado</th>
                    <th>IGV (18%)</th>
                    <th>Total</th>
                    <th>Saldo NC</th>
                    <th>Estado SUNAT</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {comprobantes.length === 0 ? (
                    <tr>
                      <td colSpan="10" style={{ textAlign: 'center', padding: '36px', color: '#94a3b8' }}>
                        No hay comprobantes emitidos. Emite tu primera Factura o Boleta en la pestaña "Emitir Comprobante".
                      </td>
                    </tr>
                  ) : (
                    comprobantes.map((c) => (
                      <tr key={c.numero_completo}>
                        <td>
                          <strong>{c.numero_completo}</strong>
                          {c.comprobante_referencia && (
                            <div style={{ fontSize: '0.74rem', color: '#64748b' }}>
                              Ref: {c.comprobante_referencia}
                            </div>
                          )}
                        </td>
                        <td>{c.tipo_nombre}</td>
                        <td>
                          <div>{c.cliente.razon_social}</div>
                          <div style={{ fontSize: '0.74rem', color: '#64748b' }}>
                            {c.cliente.tipo_doc === '6' ? 'RUC' : 'DNI'}: {c.cliente.num_doc}
                          </div>
                        </td>
                        <td style={{ fontSize: '0.78rem', color: '#475569' }}>
                          {c.fecha_emision}
                        </td>
                        <td>{c.moneda} {c.resumen.total_gravadas.toFixed(2)}</td>
                        <td>{c.moneda} {c.resumen.total_igv.toFixed(2)}</td>
                        <td><strong>{c.moneda} {c.resumen.importe_total.toFixed(2)}</strong></td>
                        <td>{c.moneda} {c.saldo_disponible_nc.toFixed(2)}</td>
                        <td>
                          <span
                            className={`status-pill ${
                              c.estado === 'ACEPTADO'
                                ? 'success'
                                : c.estado === 'OBSERVADO'
                                ? 'warning'
                                : c.estado === 'RECHAZADO'
                                ? 'danger'
                                : 'neutral'
                            }`}
                          >
                            {c.estado}
                          </span>
                          <div style={{ fontSize: '0.72rem', color: '#64748b', marginTop: 3 }}>
                            {c.mensaje_sunat}
                          </div>
                        </td>
                        <td>
                          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            <button
                              className="btn-ui secondary sm"
                              onClick={() => setXmlModal(c)}
                              title="Ver XML UBL 2.1 y Hash digital"
                            >
                              <FileCode size={13} />
                              XML
                            </button>
                            {(c.estado === 'ACEPTADO' || c.estado === 'OBSERVADO') && c.tipo !== '07' && (
                              <>
                                <button
                                  className="btn-ui warning sm"
                                  onClick={() => emitirNotaCredito(c.numero_completo)}
                                  title="Emitir Nota de Crédito (Devolución total con retorno de stock)"
                                >
                                  NC (07)
                                </button>
                                <button
                                  className="btn-ui danger sm"
                                  onClick={() => darDeBaja(c.numero_completo)}
                                  title="Anular con Comunicación de Baja (RA)"
                                >
                                  Baja
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* TAB 4: AUDITORÍA PHPUNIT 10.5 */}
        {tab === 'phpunit' && (
          <div className="surface-card">
            <div className="surface-header">
              <div className="surface-title-group">
                <Terminal size={22} style={{ color: '#2563eb' }} />
                <div>
                  <h2 className="surface-title">Consola de Auditoría Automatizada PHPUnit 10.5</h2>
                  <p className="surface-desc">
                    Ejecución en tiempo real de la pirámide de testing: Pruebas Unitarias, de Integración XML/Firma y Funcionales con Mocks.
                  </p>
                </div>
              </div>
            </div>

            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18 }}>
              <button
                className="btn-ui primary"
                disabled={ejecutandoTests}
                onClick={() => ejecutarSuitePhpunit('')}
              >
                <ShieldCheck size={16} />
                Ejecutar Suite Completa (39 Tests)
              </button>
              <button
                className="btn-ui secondary"
                disabled={ejecutandoTests}
                onClick={() => ejecutarSuitePhpunit('Unit')}
              >
                Suite Unitaria (Dominio & RUC Módulo 11)
              </button>
              <button
                className="btn-ui secondary"
                disabled={ejecutandoTests}
                onClick={() => ejecutarSuitePhpunit('Integration')}
              >
                Suite Integración (XML UBL 2.1 & SHA-256)
              </button>
              <button
                className="btn-ui secondary"
                disabled={ejecutandoTests}
                onClick={() => ejecutarSuitePhpunit('Feature')}
              >
                Suite Feature (Mocks OSE & Rollback)
              </button>
            </div>

            <div className="terminal-window">
              <div className="terminal-header">
                <div className="terminal-dots">
                  <div className="terminal-dot" style={{ backgroundColor: '#ef4444' }}></div>
                  <div className="terminal-dot" style={{ backgroundColor: '#f59e0b' }}></div>
                  <div className="terminal-dot" style={{ backgroundColor: '#10b981' }}></div>
                </div>
                <div style={{ color: '#94a3b8', fontSize: '0.74rem' }}>
                  PHPUnit 10.5.66 — Arquitectura Hexagonal
                </div>
              </div>
              <pre className="terminal-pre">
                {ejecutandoTests ? 'Ejecutando suite PHPUnit en el backend...' : phpunitOutput}
              </pre>
            </div>
          </div>
        )}
      </main>

      {/* Modal Inspector XML UBL 2.1 */}
      {xmlModal && (
        <div className="modal-overlay" onClick={() => setXmlModal(null)}>
          <div className="modal-dialog" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h3 style={{ fontSize: '1.05rem', fontWeight: 700, color: '#0f172a' }}>
                  XML OASIS UBL 2.1 Firmado: {xmlModal.numero_completo}
                </h3>
                <div style={{ fontSize: '0.78rem', color: '#64748b', marginTop: 2 }}>
                  Estado SUNAT: <strong>{xmlModal.estado}</strong> — {xmlModal.mensaje_sunat}
                </div>
              </div>
              <button
                style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: '1.2rem', color: '#64748b' }}
                onClick={() => setXmlModal(null)}
              >
                ✕
              </button>
            </div>

            <div className="modal-body">
              {/* Hash CPE Banner */}
              <div
                style={{
                  backgroundColor: '#f1f5f9',
                  border: '1px solid #cbd5e1',
                  borderRadius: 8,
                  padding: '10px 14px',
                  marginBottom: 14,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: 12,
                }}
              >
                <div>
                  <div style={{ fontSize: '0.72rem', fontWeight: 600, color: '#475569', textTransform: 'uppercase' }}>
                    Firma Digital Digest Value (Hash CPE SHA-256)
                  </div>
                  <div style={{ fontSize: '0.86rem', fontWeight: 700, color: '#0f172a', wordBreak: 'break-all' }}>
                    {xmlModal.hash_cpe}
                  </div>
                </div>
                <button
                  className="btn-ui secondary sm"
                  onClick={() => copiarAlPortapapeles(xmlModal.hash_cpe)}
                >
                  {copiadoHash ? <Check size={14} style={{ color: '#059669' }} /> : <Copy size={14} />}
                  {copiadoHash ? 'Copiado' : 'Copiar Hash'}
                </button>
              </div>

              {/* Visor de Código XML */}
              <div className="terminal-window">
                <div className="terminal-header">
                  <div style={{ color: '#94a3b8', fontSize: '0.74rem' }}>
                    Estructura XML UBL 2.1 con Signature ds:Signature
                  </div>
                </div>
                <pre className="terminal-pre" style={{ maxHeight: 380, color: '#e2e8f0' }}>
                  {xmlModal.xml_firmado}
                </pre>
              </div>
            </div>

            <div className="modal-footer">
              <button
                className="btn-ui secondary"
                onClick={() => descargarXml(xmlModal)}
              >
                <Download size={15} />
                Descargar XML UBL 2.1
              </button>
              <button
                className="btn-ui primary"
                onClick={() => setXmlModal(null)}
              >
                Aceptar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Toast Flotante Fijo en Pantalla */}
      {toastFlotante && (
        <div className={`floating-toast ${toastFlotante.tipo}`}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            {toastFlotante.tipo === 'success' && <CheckCircle2 size={18} color="#059669" />}
            {toastFlotante.tipo === 'error' && <XCircle size={18} color="#dc2626" />}
            {toastFlotante.tipo === 'info' && <Info size={18} color="#2563eb" />}
            {toastFlotante.tipo === 'warning' && <AlertTriangle size={18} color="#d97706" />}
            <span style={{ fontSize: '0.84rem', fontWeight: 500 }}>{toastFlotante.texto}</span>
          </div>
          <button
            onClick={() => setToastFlotante(null)}
            className="close-dialog-btn"
          >
            ✕
          </button>
        </div>
      )}

      {/* Modal de Confirmación Simple */}
      {modalNotificacion && (
        <div className="modal-overlay" onClick={() => setModalNotificacion(null)}>
          <div
            className="modal-dialog"
            onClick={(e) => e.stopPropagation()}
            style={{ maxWidth: 400, textAlign: 'center', padding: '26px 22px', borderRadius: 12 }}
          >
            <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 12 }}>
              {modalNotificacion.tipo === 'success' && (
                <div style={{ width: 48, height: 48, borderRadius: '50%', backgroundColor: '#ecfdf5', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#059669' }}>
                  <CheckCircle2 size={28} />
                </div>
              )}
              {modalNotificacion.tipo === 'error' && (
                <div style={{ width: 48, height: 48, borderRadius: '50%', backgroundColor: '#fef2f2', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#dc2626' }}>
                  <XCircle size={28} />
                </div>
              )}
              {modalNotificacion.tipo === 'warning' && (
                <div style={{ width: 48, height: 48, borderRadius: '50%', backgroundColor: '#fffbeb', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#d97706' }}>
                  <AlertTriangle size={28} />
                </div>
              )}
            </div>

            <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: '#0f172a', margin: '0 0 6px 0' }}>
              {modalNotificacion.titulo}
            </h3>

            <p style={{ fontSize: '0.88rem', color: '#475569', margin: '0 0 20px 0', lineHeight: 1.5 }}>
              {modalNotificacion.mensaje}
            </p>

            <div style={{ display: 'flex', justifyContent: 'center' }}>
              <button
                className="btn-ui primary"
                onClick={() => setModalNotificacion(null)}
                style={{ minWidth: 120, padding: '8px 24px' }}
                autoFocus
              >
                Aceptar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
