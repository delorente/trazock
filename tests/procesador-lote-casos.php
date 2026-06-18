<?php
declare(strict_types=1);

// =============================================================================
// tests/procesador-lote-casos.php — auditoría manual del ProcesadorLote.
//
// Script standalone (sin PHPUnit). Ejecuta casos contra la BD configurada,
// truncando SOLO las tablas transaccionales (productos, lotes, transiciones,
// lote_items, conflictos_producto) entre casos. NO toca usuarios ni catálogos.
//
// Uso:  php tests/procesador-lote-casos.php
//
// *** NO ejecutar en producción: trunca datos transaccionales. ***
// =============================================================================

if (PHP_SAPI !== 'cli') {
    exit("Solo CLI.\n");
}

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;
use Trazock\LoteException;
use Trazock\Models\Categoria;
use Trazock\Models\Motivo;
use Trazock\Models\Producto;
use Trazock\ProcesadorLote;

$db = DB::getInstance();

// --- Fixtures: usuarios y catálogos (crear si faltan) ------------------------

function fxUsuario(string $usuario, string $nombre, string $rol): int
{
    $u = \Trazock\Models\Usuario::findByUsuarioActivo($usuario);
    if ($u !== null) {
        return (int)$u['id'];
    }
    return \Trazock\Models\Usuario::crear($usuario, $nombre, 'test1234', $rol);
}

$adminId = fxUsuario('admin', 'Administrador', 'admin');
$operId  = fxUsuario('test_oper', 'Operador Test', 'operador');
$transId = fxUsuario('test_trans', 'Transportista Test', 'transportista');

// Categoría activa
$cats = Categoria::activas();
if ($cats === []) {
    Categoria::crear('TestCat', null);
    $cats = Categoria::activas();
}
$catId = (int)$cats[0]['id'];

// Motivos por tipo
function fxMotivo(string $tipo): int
{
    $ms = Motivo::activosPorTipo($tipo);
    if ($ms !== []) {
        return (int)$ms[0]['id'];
    }
    return Motivo::crear(ucfirst($tipo) . ' test', [$tipo], false);
}
$motReingreso  = fxMotivo('reingreso');
$motDevolucion = fxMotivo('devolucion');
$motBaja       = fxMotivo('baja');

// Proveedor activo
$provs = \Trazock\Models\Proveedor::activos();
if ($provs === []) {
    \Trazock\Models\Proveedor::crear('ProvTest', null, null);
    $provs = \Trazock\Models\Proveedor::activos();
}
$provId = (int)$provs[0]['id'];

$admin = ['id' => $adminId, 'rol' => 'admin'];
$oper  = ['id' => $operId,  'rol' => 'operador'];
$trans = ['id' => $transId, 'rol' => 'transportista'];

// --- Utilidades --------------------------------------------------------------

function reset_tx(): void
{
    $db = DB::getInstance();
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['conflictos_producto', 'lote_items', 'transiciones', 'lotes', 'productos'] as $t) {
        $db->exec("TRUNCATE TABLE {$t}");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$uuidSeq = 0;
function mkUuid(): string
{
    global $uuidSeq;
    $uuidSeq++;
    return sprintf('%08x-0000-4000-8000-%012x', $uuidSeq, $uuidSeq);
}

/**
 * Construye payload de lote. $items: lista de [codigo, tsISO].
 */
function mkLote(string $tipo, array $items, array $extra = [], ?string $uuid = null): array
{
    $base = [
        'uuid'               => $uuid ?? mkUuid(),
        'tipo'               => $tipo,
        'categoria_id'       => null,
        'proveedor_id'       => null,
        'transportista_id'   => null,
        'motivo_id'          => null,
        'motivo_libre'       => null,
        'observaciones'      => null,
        'numero_remito'      => null,
        'timestamp_apertura' => '2026-01-01T08:00:00Z',
        'timestamp_cierre'   => '2026-01-01T09:00:00Z',
        'dispositivo_info'   => 'test-harness',
        'items'              => array_map(
            static fn(array $it): array => ['codigo' => $it[0], 'timestamp_cliente' => $it[1]],
            $items
        ),
    ];
    return array_merge($base, $extra);
}

function estadoDe(string $codigo): ?string
{
    $p = Producto::findByCodigo($codigo);
    return $p['estado_actual'] ?? null;
}

function conflictoDe(string $codigo): int
{
    $p = Producto::findByCodigo($codigo);
    return $p ? (int)$p['tiene_conflicto'] : -1;
}

function transicionesDe(string $codigo): int
{
    $p = Producto::findByCodigo($codigo);
    if ($p === null) {
        return -1;
    }
    $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM transiciones WHERE producto_id = :id');
    $stmt->execute([':id' => $p['id']]);
    return (int)$stmt->fetchColumn();
}

$fallos = 0;
function check(int $caso, string $desc, bool $cond, string $esperado = '', string $obtuvo = ''): void
{
    global $fallos;
    if ($cond) {
        echo "[OK]   caso {$caso}: {$desc}\n";
    } else {
        $fallos++;
        echo "[FAIL] caso {$caso}: {$desc} — esperado {$esperado}, obtuvo {$obtuvo}\n";
    }
}

echo "=== Auditoría ProcesadorLote — 15 casos ===\n\n";

// --- Caso 1: INGRESO con 3 códigos nuevos ------------------------------------
reset_tx();
$r = ProcesadorLote::procesarLote(
    mkLote('INGRESO', [['A1', '2026-01-01T09:00:00Z'], ['A2', '2026-01-01T09:00:01Z'], ['A3', '2026-01-01T09:00:02Z']],
        ['categoria_id' => $catId]),
    $oper
);
check(1, '3 códigos nuevos en INGRESO',
    $r['transiciones_aplicadas'] === 3 && $r['conflictos_generados'] === 0
    && estadoDe('A1') === 'INGRESADO' && estadoDe('A3') === 'INGRESADO',
    '3 aplicadas / 0 conflictos / INGRESADO',
    "{$r['transiciones_aplicadas']} aplicadas / {$r['conflictos_generados']} conflictos / " . estadoDe('A1'));

// --- Caso 2: mismo lote (uuid) dos veces → idempotente (R1) -------------------
reset_tx();
$loteDup = mkLote('INGRESO', [['B1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId], 'dd000000-0000-4000-8000-000000000001');
$r1 = ProcesadorLote::procesarLote($loteDup, $oper);
$r2 = ProcesadorLote::procesarLote($loteDup, $oper);
$nLotes = (int)$db->query("SELECT COUNT(*) FROM lotes")->fetchColumn();
check(2, 'mismo uuid dos veces es idempotente (R1)',
    ($r1['idempotente'] === false) && ($r2['idempotente'] === true) && $nLotes === 1,
    '2da idempotente y 1 solo lote',
    'idem1=' . var_export($r1['idempotente'], true) . ' idem2=' . var_export($r2['idempotente'], true) . " lotes={$nLotes}");

// --- Caso 3: código duplicado dentro del lote (R2) ---------------------------
reset_tx();
$r = ProcesadorLote::procesarLote(
    mkLote('INGRESO', [['C1', '2026-01-01T09:00:00Z'], ['C1', '2026-01-01T09:00:05Z']], ['categoria_id' => $catId]),
    $oper
);
check(3, 'código duplicado en el lote: 1 transición + 1 ignorado (R2)',
    $r['transiciones_aplicadas'] === 1 && $r['items_ignorados'] === 1 && transicionesDe('C1') === 1,
    '1 aplicada / 1 ignorada',
    "{$r['transiciones_aplicadas']} aplicadas / {$r['items_ignorados']} ignoradas / trans=" . transicionesDe('C1'));

// --- Caso 4: INGRESO sobre código ya INGRESADO → R3 --------------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['D1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
$r = ProcesadorLote::procesarLote(mkLote('INGRESO', [['D1', '2026-01-01T10:00:00Z']], ['categoria_id' => $catId]), $oper);
check(4, 'INGRESO sobre INGRESADO se ignora (R3)',
    $r['items_ignorados'] === 1 && $r['transiciones_aplicadas'] === 0 && transicionesDe('D1') === 1,
    '1 ignorada / 0 aplicadas',
    "{$r['items_ignorados']} ignoradas / {$r['transiciones_aplicadas']} aplicadas");

// --- Caso 5: SALIDA_REPARTO sobre INGRESADO → legal --------------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['E1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
$r = ProcesadorLote::procesarLote(
    mkLote('SALIDA_REPARTO', [['E1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
check(5, 'SALIDA_REPARTO sobre INGRESADO es legal',
    $r['transiciones_aplicadas'] === 1 && $r['conflictos_generados'] === 0 && estadoDe('E1') === 'EN_REPARTO',
    'aplicada sin conflicto / EN_REPARTO',
    "conf={$r['conflictos_generados']} / " . estadoDe('E1'));

// --- Caso 6: ENTREGA sobre INGRESADO (sin reparto) → conflicto (R6) ----------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['F1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
$r = ProcesadorLote::procesarLote(mkLote('ENTREGA', [['F1', '2026-01-01T10:00:00Z']]), $trans);
check(6, 'ENTREGA sobre INGRESADO se aplica con conflicto (R6)',
    $r['conflictos_generados'] === 1 && estadoDe('F1') === 'ENTREGADO' && conflictoDe('F1') === 1,
    '1 conflicto / ENTREGADO / tiene_conflicto=1',
    "conf={$r['conflictos_generados']} / " . estadoDe('F1') . ' / tc=' . conflictoDe('F1'));

// --- Caso 7: SALIDA_REPARTO sobre código nunca visto → R7 --------------------
reset_tx();
$r = ProcesadorLote::procesarLote(
    mkLote('SALIDA_REPARTO', [['G1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
check(7, 'código inexistente en SALIDA_REPARTO: alta + conflicto (R7)',
    $r['conflictos_generados'] === 1 && estadoDe('G1') === 'EN_REPARTO' && conflictoDe('G1') === 1,
    'creado EN_REPARTO con conflicto',
    'estado=' . estadoDe('G1') . " conf={$r['conflictos_generados']}");

// --- Caso 8: lote retroactivo no cambia estado_actual (R4) -------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['H1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['H1', '2026-01-02T09:00:00Z']], ['transportista_id' => $transId]), $oper);
// Llega un INGRESO con timestamp MÁS VIEJO que todo lo anterior.
$r = ProcesadorLote::procesarLote(mkLote('INGRESO', [['H1', '2025-12-30T09:00:00Z']], ['categoria_id' => $catId]), $oper);
check(8, 'INGRESO retroactivo (ts viejo): se inserta pero estado_actual no cambia (R4)',
    estadoDe('H1') === 'EN_REPARTO' && transicionesDe('H1') === 3,
    'EN_REPARTO con 3 transiciones',
    'estado=' . estadoDe('H1') . ' trans=' . transicionesDe('H1'));

// --- Caso 9: dos lotes en orden inverso → estado final EN_REPARTO ------------
reset_tx();
// SALIDA_REPARTO (T2) llega primero (R7 lo crea EN_REPARTO)...
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['I1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
// ...luego INGRESO (T1, más viejo).
ProcesadorLote::procesarLote(mkLote('INGRESO', [['I1', '2026-01-01T08:00:00Z']], ['categoria_id' => $catId]), $oper);
check(9, 'lotes en orden de llegada inverso: estado final EN_REPARTO (no INGRESADO)',
    estadoDe('I1') === 'EN_REPARTO',
    'EN_REPARTO', 'estado=' . estadoDe('I1'));

// --- Caso 10: BAJA desde INGRESADO → legal -----------------------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['J1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
$r = ProcesadorLote::procesarLote(mkLote('BAJA', [['J1', '2026-01-01T10:00:00Z']], ['motivo_id' => $motBaja]), $oper);
check(10, 'BAJA desde INGRESADO es legal',
    $r['conflictos_generados'] === 0 && estadoDe('J1') === 'BAJA',
    'BAJA sin conflicto', 'estado=' . estadoDe('J1') . " conf={$r['conflictos_generados']}");

// --- Caso 11: BAJA desde EN_REPARTO → legal ----------------------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['K1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['K1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
$r = ProcesadorLote::procesarLote(mkLote('BAJA', [['K1', '2026-01-01T11:00:00Z']], ['motivo_id' => $motBaja]), $oper);
check(11, 'BAJA desde EN_REPARTO es legal',
    $r['conflictos_generados'] === 0 && estadoDe('K1') === 'BAJA',
    'BAJA sin conflicto', 'estado=' . estadoDe('K1') . " conf={$r['conflictos_generados']}");

// --- Caso 12: BAJA desde REINGRESADO → legal ---------------------------------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['L1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['L1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
ProcesadorLote::procesarLote(mkLote('REINGRESO', [['L1', '2026-01-01T11:00:00Z']], ['motivo_id' => $motReingreso]), $oper);
$r = ProcesadorLote::procesarLote(mkLote('BAJA', [['L1', '2026-01-01T12:00:00Z']], ['motivo_id' => $motBaja]), $oper);
check(12, 'BAJA desde REINGRESADO es legal',
    $r['conflictos_generados'] === 0 && estadoDe('L1') === 'BAJA',
    'BAJA sin conflicto', 'estado=' . estadoDe('L1') . " conf={$r['conflictos_generados']}");

// --- Caso 13: REINGRESO desde ENTREGADO (devolución cliente) → legal ---------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['M1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['M1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
ProcesadorLote::procesarLote(mkLote('ENTREGA', [['M1', '2026-01-01T11:00:00Z']]), $trans);
$r = ProcesadorLote::procesarLote(mkLote('REINGRESO', [['M1', '2026-01-01T12:00:00Z']], ['motivo_id' => $motReingreso]), $oper);
check(13, 'REINGRESO desde ENTREGADO es legal',
    $r['conflictos_generados'] === 0 && estadoDe('M1') === 'REINGRESADO',
    'REINGRESADO sin conflicto', 'estado=' . estadoDe('M1') . " conf={$r['conflictos_generados']}");

// --- Caso 14: SALIDA_DEVOLUCION desde REINGRESADO → legal, terminal ----------
reset_tx();
ProcesadorLote::procesarLote(mkLote('INGRESO', [['N1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $oper);
ProcesadorLote::procesarLote(mkLote('SALIDA_REPARTO', [['N1', '2026-01-01T10:00:00Z']], ['transportista_id' => $transId]), $oper);
ProcesadorLote::procesarLote(mkLote('REINGRESO', [['N1', '2026-01-01T11:00:00Z']], ['motivo_id' => $motReingreso]), $oper);
$r = ProcesadorLote::procesarLote(
    mkLote('SALIDA_DEVOLUCION', [['N1', '2026-01-01T12:00:00Z']],
        ['proveedor_id' => $provId, 'motivo_id' => $motDevolucion]), $oper);
check(14, 'SALIDA_DEVOLUCION desde REINGRESADO es legal (terminal DEVUELTO)',
    $r['conflictos_generados'] === 0 && estadoDe('N1') === 'DEVUELTO',
    'DEVUELTO sin conflicto', 'estado=' . estadoDe('N1') . " conf={$r['conflictos_generados']}");

// --- Caso 15: transportista envía INGRESO → 403 ------------------------------
reset_tx();
$rechazado = false; $status = 0;
try {
    ProcesadorLote::procesarLote(mkLote('INGRESO', [['O1', '2026-01-01T09:00:00Z']], ['categoria_id' => $catId]), $trans);
} catch (LoteException $e) {
    $rechazado = true; $status = $e->httpStatus;
}
check(15, 'transportista enviando INGRESO es rechazado con 403',
    $rechazado && $status === 403,
    '403', $rechazado ? (string)$status : 'no lanzó excepción');

// --- Resumen -----------------------------------------------------------------
echo "\n";
if ($fallos === 0) {
    echo "=== TODOS LOS CASOS OK (15/15) ===\n";
    exit(0);
}
echo "=== {$fallos} CASO(S) FALLARON ===\n";
exit(1);
