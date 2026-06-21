/* canvas-app.jsx — layout de las variantes en el design canvas */
const { buildScreen, CONFIGS } = window.TZ;

function Screen({ id }) {
  return (
    <div style={{ width: '100%', height: '100%' }}
      dangerouslySetInnerHTML={{ __html: buildScreen(CONFIGS[id]) }} />
  );
}

const MW = 384;   // mobile
const DW = 840;   // desktop (ticket centrado en página ancha)

// alto por variante (mobile / desktop)
const H = {
  ingresado: [776, 736],
  reparto:   [812, 760],
  entregado: [840, 780],
  revision:  [544, 520],
  invalido:  [500, 484],
};

function Variant({ sid, title, subtitle, id }) {
  const [hm, hd] = H[id];
  return (
    <DCSection id={sid} title={title} subtitle={subtitle}>
      <DCArtboard id={id + '-m'} label="Mobile · 384" width={MW} height={hm}><Screen id={id} /></DCArtboard>
      <DCArtboard id={id + '-d'} label="Desktop · ticket 520" width={DW} height={hd}><Screen id={id} /></DCArtboard>
    </DCSection>
  );
}

// ── demo: estados del paso ──────────────────────────────
function StepDemo({ state }) {
  const cfg = {
    done:    { cls: 'tz-st-green', icon: 'check-lg',  title: 'Recibimos tu producto', desc: 'Tu producto llegó y quedó registrado.', date: '14/06/26', chip: '', nodeCls: 'done' },
    current: { cls: 'tz-st-amber', icon: 'truck',     title: 'En camino a tu domicilio', desc: 'Un transportista lo tiene en ruta.', date: '16/06/26 · 09:30', chip: 'En curso', nodeCls: 'current' },
    pending: { cls: 'tz-st-green', icon: 'house-check', title: '¡Entregado!', desc: '', date: '', chip: '', nodeCls: 'pending' },
  }[state];
  const html =
    '<div style="font-family:Inter,sans-serif;background:#fff;width:100%;height:100%;display:flex;align-items:center;padding:30px 28px;box-sizing:border-box">' +
      '<div class="tz-step ' + cfg.nodeCls + ' ' + cfg.cls + '" style="width:100%">' +
        '<div class="tz-rail"><div class="tz-node ' + cfg.nodeCls + '"><i class="bi bi-' + cfg.icon + '"></i></div></div>' +
        '<div class="tz-body" style="padding-bottom:0">' +
          '<div class="tz-step-titlerow"><span class="tz-step-title">' + cfg.title + '</span>' +
            (cfg.chip ? '<span class="tz-chip">' + cfg.chip + '</span>' : '') + '</div>' +
            (cfg.desc ? '<div class="tz-step-desc">' + cfg.desc + '</div>' : '') +
            (cfg.date ? '<div class="tz-step-date"><i class="bi bi-calendar3"></i>' + cfg.date + '</div>' : '') +
        '</div>' +
      '</div>' +
    '</div>';
  return <div style={{ width: '100%', height: '100%' }} dangerouslySetInnerHTML={{ __html: html }} />;
}

// ── specs panel ─────────────────────────────────────────
function sw(name, hex, varName) {
  return (
    <div className="tz-sw" key={name}>
      <div className="chip" style={{ background: `var(${varName})` }}></div>
      <b>{name}</b><span className="hex">{hex}</span>
    </div>
  );
}
function ic(name, icon) {
  return (
    <div className="tz-ic" key={icon}>
      <i className={'bi bi-' + icon}></i>{name}<code>bi-{icon}</code>
    </div>
  );
}
function Specs() {
  return (
    <div className="tz-spec">
      <h3>Color · estados</h3>
      <div className="tz-swatches">
        {sw('Ingresado', '#3b82f6', '--tz-blue')}
        {sw('En reparto', '#f59e0b', '--tz-amber')}
        {sw('Entregado', '#22c55e', '--tz-green')}
        {sw('Excepción / vacío', '#64748b', '--tz-slate')}
      </div>
      <h3>Color · base (light brand)</h3>
      <div className="tz-swatches">
        {sw('Página', '#f4f2ee', '--tz-paper')}
        {sw('Card', '#ffffff', '--tz-card')}
        {sw('Tinta', '#1d2127', '--tz-ink')}
        {sw('Tinta suave', '#5a626d', '--tz-ink-soft')}
        {sw('Borde', '#e8e4dd', '--tz-line')}
      </div>
      <h3>Tipografía · Inter</h3>
      <div className="tz-type-row"><span className="lab">Título 25/700</span><span style={{ fontSize: 25, fontWeight: 700, letterSpacing: '-.025em' }}>En camino a tu domicilio</span></div>
      <div className="tz-type-row"><span className="lab">Cuerpo 15/400</span><span style={{ fontSize: 15, color: '#5a626d' }}>Tu producto salió de nuestro depósito.</span></div>
      <div className="tz-type-row"><span className="lab">Paso 15.5/600</span><span style={{ fontSize: 15.5, fontWeight: 600 }}>Recibimos tu producto</span></div>
      <div className="tz-type-row"><span className="lab">Meta 12/400</span><span style={{ fontSize: 12, color: '#949aa4' }}>Última actualización: 16/06/26 09:30</span></div>
      <h3>Íconos · Bootstrap Icons</h3>
      <div className="tz-iconrow">
        {ic('Marca', 'upc-scan')}
        {ic('Ingresado', 'box-seam')}
        {ic('En reparto', 'truck')}
        {ic('Entregado', 'house-check')}
        {ic('Completado', 'check-lg')}
        {ic('Revisión', 'arrow-repeat')}
        {ic('404', 'link-45deg')}
      </div>
    </div>
  );
}

function App() {
  return (
    <DesignCanvas>
      <Variant sid="v-reparto"  id="reparto"  title="1 · En reparto"        subtitle="Caso típico — paso 1 completado, 2 actual, 3 pendiente" />
      <Variant sid="v-ingresado" id="ingresado" title="2 · Recién ingresado" subtitle="Paso 1 actual, 2 y 3 pendientes" />
      <Variant sid="v-entregado" id="entregado" title="3 · Entregado"        subtitle="Los 3 completados — cierre feliz (celebración sutil)" />
      <Variant sid="v-revision"  id="revision"  title="4 · En revisión"      subtitle="Estado excepcional — solo hero, sin camino feliz" />
      <Variant sid="v-invalido"  id="invalido"  title="5 · Enlace vencido"   subtitle="404 — estado vacío amable, sin alarmar" />

      <DCSection id="estados" title="Estados del paso" subtitle="completado · actual · pendiente">
        <DCArtboard id="st-done"    label="Completado" width={400} height={150}><StepDemo state="done" /></DCArtboard>
        <DCArtboard id="st-current" label="Actual"     width={400} height={150}><StepDemo state="current" /></DCArtboard>
        <DCArtboard id="st-pending" label="Pendiente"  width={400} height={150}><StepDemo state="pending" /></DCArtboard>
      </DCSection>

      <DCSection id="specs" title="Specs" subtitle="Color · tipografía · íconos — alineados al design system de Trazock">
        <DCArtboard id="spec-1" label="Design tokens" width={620} height={620}><Specs /></DCArtboard>
      </DCSection>
    </DesignCanvas>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
