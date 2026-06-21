/* tracking-screens.js — builder de la pantalla pública de seguimiento Trazock.
   Una sola fuente de verdad: todas las variantes salen de buildScreen(cfg). */
(function () {
  'use strict';

  var HELP = '¿Algún problema con tu pedido? <a href="#">Escribinos</a>';
  var FOOTBRAND = 'Seguimiento provisto por Trazock';

  function brand() {
    return '<div class="tz-brand">' +
      '<div class="tz-logo"><i class="bi bi-upc-scan"></i></div>' +
      '<span class="tz-name">Trazock</span>' +
      '</div>';
  }

  function footer(showBrand) {
    return '<div class="tz-foot">' + HELP +
      (showBrand ? '<div class="tz-foot-brand">' + FOOTBRAND + '</div>' : '') +
      '</div>';
  }

  function update(txt) {
    if (!txt) return '';
    return '<div class="tz-update"><i class="bi bi-clock-history"></i>Última actualización: ' + txt + '</div>';
  }

  // confeti sutil y estático para el cierre feliz
  function confetti() {
    var dots = [
      ['18%', '8%', 'var(--tz-blue)', '-12deg'],
      ['80%', '14%', 'var(--tz-green)', '20deg'],
      ['10%', '46%', 'var(--tz-amber)', '14deg'],
      ['88%', '52%', 'var(--tz-blue)', '-18deg'],
      ['30%', '70%', 'var(--tz-green)', '8deg'],
      ['72%', '78%', 'var(--tz-amber)', '-8deg']
    ];
    var s = '<div class="tz-confetti">';
    dots.forEach(function (d) {
      s += '<span style="left:' + d[0] + ';top:' + d[1] + ';background:' + d[2] + ';transform:rotate(' + d[3] + ')"></span>';
    });
    return s + '</div>';
  }

  function hero(cfg) {
    return '<div class="tz-hero tz-st-' + cfg.stage + '">' +
      (cfg.celebrate ? confetti() : '') +
      '<div class="tz-hero-ic"><i class="bi bi-' + cfg.icon + '"></i></div>' +
      '<h1 class="tz-hero-title">' + cfg.title + '</h1>' +
      '<p class="tz-hero-desc">' + cfg.desc + '</p>' +
      update(cfg.update) +
      '</div>';
  }

  // stage por paso (índice fijo del camino feliz)
  var STEP_STAGE = ['blue', 'amber', 'green'];

  function step(s, i, isLast) {
    var nodeCls = 'tz-node ' + s.state;
    var icon = s.state === 'done'
      ? '<i class="bi bi-check-lg"></i>'
      : '<i class="bi bi-' + s.icon + '"></i>';
    var connCls = 'tz-conn' + (s.state === 'done' ? ' filled' : '');
    var chip = s.state === 'current'
      ? '<span class="tz-chip">En curso</span>' : '';
    var date = s.date
      ? '<div class="tz-step-date"><i class="bi bi-calendar3"></i>' + s.date + '</div>' : '';
    return '<div class="tz-step ' + s.state + ' tz-st-' + STEP_STAGE[i] + '">' +
      '<div class="tz-rail">' +
        '<div class="' + nodeCls + '">' + icon + '</div>' +
        (isLast ? '' : '<div class="' + connCls + '"></div>') +
      '</div>' +
      '<div class="tz-body">' +
        '<div class="tz-step-titlerow"><span class="tz-step-title">' + s.title + '</span>' + chip + '</div>' +
        '<div class="tz-step-desc">' + s.desc + '</div>' +
        date +
      '</div>' +
    '</div>';
  }

  function timeline(steps) {
    var inner = steps.map(function (s, i) {
      return step(s, i, i === steps.length - 1);
    }).join('');
    return '<div class="tz-div"></div><div class="tz-tl">' + inner + '</div>';
  }

  // cfg.kind: 'timeline' | 'message' | 'empty'
  function buildScreen(cfg) {
    var card;
    if (cfg.kind === 'timeline') {
      card = '<div class="tz-card' + (cfg.celebrate ? ' tz-card--celebrate' : '') + '">' +
        hero(cfg) + timeline(cfg.steps) + '</div>';
    } else {
      // message / empty: solo hero centrado, sin camino feliz
      card = '<div class="tz-card tz-card--center tz-st-' + cfg.stage + '">' +
        '<div class="tz-hero-ic"><i class="bi bi-' + cfg.icon + '"></i></div>' +
        '<h1 class="tz-hero-title">' + cfg.title + '</h1>' +
        '<p class="tz-hero-desc">' + cfg.desc + '</p>' +
        update(cfg.update) +
        '</div>';
    }
    return '<div class="tz-screen">' +
      '<div class="tz-col">' +
        brand() +
        card +
        footer(cfg.kind !== 'empty') +
      '</div>' +
    '</div>';
  }

  // ── CONFIGS DE LAS 5 VARIANTES ─────────────────────────────
  var STEPS_BASE = [
    { icon: 'box-seam',    title: 'Recibimos tu producto',      descDone: 'Tu producto llegó y quedó registrado en nuestro depósito.' },
    { icon: 'truck',       title: 'En camino a tu domicilio',   descDone: 'Salió del depósito rumbo a tu domicilio.' },
    { icon: 'house-check', title: '¡Entregado!',                descDone: 'Llegó a destino. ¡Gracias por tu compra!' }
  ];

  function mkSteps(states, dates, currentDescs) {
    return STEPS_BASE.map(function (b, i) {
      return {
        icon: b.icon,
        title: b.title,
        desc: (states[i] === 'current' && currentDescs && currentDescs[i]) ? currentDescs[i] : b.descDone,
        state: states[i],
        date: dates[i] || ''
      };
    });
  }

  var CONFIGS = {
    reparto: {
      kind: 'timeline', stage: 'amber', icon: 'truck',
      title: 'En camino a tu domicilio',
      desc: 'Tu producto salió de nuestro depósito y está en viaje hacia tu domicilio.',
      update: '16/06/26 09:30',
      steps: mkSteps(
        ['done', 'current', 'pending'],
        ['14/06/26', '16/06/26 · 09:30', ''],
        [null, 'Un transportista lo tiene en ruta hacia tu domicilio.', null]
      )
    },
    ingresado: {
      kind: 'timeline', stage: 'blue', icon: 'box-seam',
      title: 'Recibimos tu producto',
      desc: 'Tu producto ya está en nuestro depósito y pronto va a salir hacia tu domicilio.',
      update: '16/06/26 08:10',
      steps: mkSteps(
        ['current', 'pending', 'pending'],
        ['16/06/26 · 08:10', '', ''],
        ['Quedó registrado y en preparación para el envío.', null, null]
      )
    },
    entregado: {
      kind: 'timeline', stage: 'green', icon: 'house-check', celebrate: true,
      title: '¡Entregado!',
      desc: 'Tu producto fue entregado en tu domicilio. ¡Que lo disfrutes!',
      update: '16/06/26 15:45',
      steps: mkSteps(
        ['done', 'done', 'done'],
        ['14/06/26', '16/06/26 · 11:20', '16/06/26 · 15:45'],
        null
      )
    },
    revision: {
      kind: 'message', stage: 'slate', icon: 'arrow-repeat',
      title: 'Estamos revisando tu pedido',
      desc: 'Tu pedido está en revisión. En breve nos vamos a comunicar con vos para coordinar los próximos pasos.',
      update: '16/06/26 11:00'
    },
    invalido: {
      kind: 'empty', stage: 'slate', icon: 'link-45deg',
      title: 'Enlace no disponible',
      desc: 'Este enlace de seguimiento no está disponible o ya venció. Si llegaste hasta acá desde un mensaje nuestro, fijate de abrir el más reciente.',
      update: null
    }
  };

  window.TZ = { buildScreen: buildScreen, CONFIGS: CONFIGS };
})();
