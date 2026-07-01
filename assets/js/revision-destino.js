// =============================================================================
// revision-destino.js — resolvedor de localidad/provincia para la Revisión OCR.
//
// Las hojas de Simmons suelen traer el destino "cortado" (SANTIAGO por SANTIAGO
// DEL ESTERO) o "pegado" (SALTASALTA = SALTA + SALTA, PALPALAJUJUY = PALPALA +
// JUJUY). Con un diccionario armado desde nuestros propios datos (las 24
// provincias argentinas + localidades de zonas e histórico de órdenes) intentamos
// completar/separar automáticamente, marcando lo autocompletado para que el
// operador lo verifique.
//
// `crearResolvedor(dic)` devuelve una función resolver(localidad, provincia) →
//   { localidad, provincia, locChg, provChg, cambio, nota }
//
// dic = { provincias:[canon,...], localidades:[{localidad,provincia},...] }
// Framework-free; corre en el navegador y también bajo node (para los tests).
// =============================================================================

(function (root) {
    'use strict';

    // lower + sin acentos + espacios colapsados (equivalente a Destino::norm en PHP)
    function strip(s) {
        return String(s == null ? '' : s)
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }
    // igual pero sin ningún espacio (para comparar tokens "pegados")
    function stripNS(s) { return strip(s).replace(/\s+/g, ''); }
    // salida canónica de los campos de destino: mayúsculas (estilo remito)
    function up(s) { return String(s == null ? '' : s).trim().toUpperCase(); }

    function crearResolvedor(dic) {
        dic = dic || {};

        const PROV = (dic.provincias || [])
            .map(c => ({ canon: c, n: strip(c), ns: stripNS(c) }))
            .filter(p => p.n !== '');
        const PROVset = new Set(PROV.map(p => p.n));
        const PROVns = new Set(PROV.map(p => p.ns));
        const PROVbylen = PROV.slice().sort((a, b) => b.ns.length - a.ns.length);

        const LOC = (dic.localidades || [])
            .map(l => ({ loc: l.localidad, prov: l.provincia || '', n: strip(l.localidad), ns: stripNS(l.localidad) }))
            .filter(l => l.n !== '');
        const LOCbyN = new Map();
        const LOCbyNS = new Map();
        LOC.forEach(l => {
            if (!LOCbyN.has(l.n)) LOCbyN.set(l.n, l);
            if (!LOCbyNS.has(l.ns)) LOCbyNS.set(l.ns, l);
        });

        // Intenta separar un token pegado (sin espacios) en localidad + provincia.
        // Estrategia: probar cada provincia como SUFIJO (más larga primero); el
        // resto es la localidad. Si el resto es una localidad conocida → alta
        // confianza; si no, se acepta solo si es corto (≤ 8) para no romper
        // ciudades cuyo nombre contiene a la provincia (San Miguel de Tucumán).
        function trySplit(token) {
            const ns = stripNS(token);
            // No exigimos que el token NO sea localidad conocida: cargas viejas mal
            // separadas "envenenan" el diccionario con formas pegadas. Los nombres
            // reales largos quedan protegidos por el tope de longitud del resto (≤ 8).
            for (const p of PROVbylen) {
                if (ns.length > p.ns.length && ns.endsWith(p.ns)) {
                    const rest = ns.slice(0, ns.length - p.ns.length);
                    if (rest.length < 2) continue;
                    const known = LOCbyNS.get(rest);
                    if (known) return { loc: up(known.loc), prov: p.canon, known: true };
                    if (rest.length <= 8) return { loc: up(rest), prov: p.canon, known: false };
                }
            }
            return null;
        }

        return function resolver(rawLoc, rawProv) {
            let loc = String(rawLoc == null ? '' : rawLoc).trim();
            let prov = String(rawProv == null ? '' : rawProv).trim();
            let locChg = false, provChg = false;
            const notas = [];

            // (A) La provincia ya está y la localidad la trae PEGADA al final
            //     (loc "PALPALAJUJUY" + prov "JUJUY" -> loc "PALPALA"). Resistente al
            //     diccionario "envenenado" por cargas viejas mal separadas: si el token
            //     no tiene espacios lo corta aunque el pegado figure como localidad
            //     conocida. Con espacios solo corta si el resto es localidad conocida,
            //     para no romper nombres reales ("SAN SALVADOR DE JUJUY").
            if (prov && loc) {
                const provNS = stripNS(prov);
                const locNS = stripNS(loc);
                if (provNS && locNS !== provNS && locNS.endsWith(provNS)) {
                    const restNS = locNS.slice(0, locNS.length - provNS.length);
                    const sinEspacios = !/\s/.test(loc);
                    const conocida = LOCbyNS.get(restNS);
                    if (restNS.length >= 2 && (conocida || (sinEspacios && restNS.length <= 8))) {
                        loc = conocida ? up(conocida.loc) : up(restNS);
                        locChg = true;
                        notas.push('corté la provincia de la localidad');
                    }
                }
            }

            // (D) Separar un token "pegado" (revisar primero provincia, luego localidad).
            for (const cual of ['prov', 'loc']) {
                const val = cual === 'prov' ? prov : loc;
                if (!val || /\s/.test(val)) continue;         // debe ser un único token
                if (PROVns.has(stripNS(val))) continue;        // ya es una provincia limpia
                const sp = trySplit(val);
                if (sp) {
                    if (strip(loc) !== strip(sp.loc)) { loc = sp.loc; locChg = true; }
                    if (strip(prov) !== strip(sp.prov)) { prov = sp.prov; provChg = true; }
                    if (locChg || provChg) notas.push('separé localidad/provincia');
                    break;
                }
            }

            // (B) Provincia truncada: prefijo único de una provincia conocida.
            if (prov && !PROVset.has(strip(prov))) {
                const np = strip(prov);
                const uniq = [...new Set(PROV.filter(p => p.n !== np && p.n.startsWith(np)).map(p => p.canon))];
                if (uniq.length === 1) { prov = uniq[0]; provChg = true; notas.push('completé provincia'); }
            }

            // (C) Localidad truncada: prefijo único de una localidad conocida.
            if (loc && !LOCbyN.has(strip(loc))) {
                const nl = strip(loc);
                const cands = LOC.filter(l => l.n !== nl && l.n.startsWith(nl));
                const uniqLoc = [...new Set(cands.map(c => c.loc))];
                if (uniqLoc.length === 1) {
                    const hit = cands.find(c => c.loc === uniqLoc[0]);
                    loc = up(hit.loc); locChg = true; notas.push('completé localidad');
                    if (!prov && hit.prov) { prov = up(hit.prov); provChg = true; }
                }
            }

            // (E) Inferir provincia desde una localidad conocida (si quedó vacía).
            if (loc && !prov) {
                const e = LOCbyN.get(strip(loc));
                if (e && e.prov) { prov = up(e.prov); provChg = true; notas.push('inferí provincia'); }
            }

            return {
                localidad: loc,
                provincia: prov,
                locChg: locChg,
                provChg: provChg,
                cambio: locChg || provChg,
                nota: notas.join(' · '),
            };
        };
    }

    root.crearResolvedor = crearResolvedor;
})(typeof window !== 'undefined' ? window : globalThis);
