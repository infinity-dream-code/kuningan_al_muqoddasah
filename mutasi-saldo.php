<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

foreach ([
    __DIR__ . '/config/database.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/models/../config/database.php',
] as $cfg) {
    if (is_file($cfg)) {
        require_once $cfg;
        break;
    }
}

if (!class_exists('Database')) {
    echo json_encode(['status' => false, 'message' => 'Konfigurasi database WS tidak ditemukan']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$rawVa = preg_replace('/\D+/', '', (string) ($input['va'] ?? $input['nocust'] ?? ''));
$custidIn = $input['custid'] ?? $input['CUSTID'] ?? null;

try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'Gagal koneksi database']);
    exit;
}

$prefixes = ['777723', '797777', '96240', '797783', '751000', '797766', '757777'];
usort($prefixes, function ($a, $b) {
    return strlen($b) <=> strlen($a);
});
$va = $rawVa;
for ($i = 0; $i < 4; $i++) {
    $hit = false;
    foreach ($prefixes as $prefix) {
        if (strpos($va, $prefix) === 0 && strlen($va) > strlen($prefix)) {
            $va = substr($va, strlen($prefix));
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        break;
    }
}
$trimmed = ltrim($va, '0');
if ($trimmed === '') {
    $trimmed = $va;
}
$padded = str_pad($trimmed, 10, '0', STR_PAD_LEFT);

$custid = $custidIn;
$nocust = $trimmed;
if (!$custid) {
    $stmt = $db->prepare("
        SELECT c.CUSTID AS id, c.NOCUST AS no_cust
        FROM scctcust c
        WHERE TRIM(c.NOCUST) = ?
           OR TRIM(c.NUM2ND) = ?
           OR TRIM(LEADING '0' FROM TRIM(c.NOCUST)) = ?
           OR CAST(TRIM(c.NOCUST) AS UNSIGNED) = ?
        LIMIT 1
    ");
    $stmt->execute([$trimmed, $trimmed, $trimmed, (int) $trimmed]);
    $siswa = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($siswa) {
        $custid = $siswa['id'];
        $nocust = $siswa['no_cust'];
    }
}

function mutasi_fetch($db, $sql, $params)
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

$order = ' ORDER BY t.TRXDATE DESC LIMIT 200';
$selectMin = 'SELECT t.TRXDATE, t.METODE, t.DEBET, t.KREDIT, t.NOREFF, t.TRANSNO FROM sccttran t';
$selectFull = 'SELECT t.TRXDATE, t.METODE, t.DEBET, t.KREDIT, t.NOREFF, t.TRANSNO, t.BILLTARGET FROM sccttran t';

$attempts = [];
if ($custid !== null && $custid !== '') {
    $attempts[] = [$selectFull.' WHERE t.CUSTID = ?'.$order, [$custid]];
    $attempts[] = [$selectMin.' WHERE t.CUSTID = ?'.$order, [$custid]];
    $attempts[] = [$selectMin.' WHERE CAST(t.CUSTID AS UNSIGNED) = ?'.$order, [(int) $custid]];
}
foreach (array_unique(array_filter([$nocust, $trimmed, $padded, $rawVa])) as $n) {
    $attempts[] = [$selectFull.' WHERE TRIM(t.NOCUST) = ?'.$order, [$n]];
    $attempts[] = [$selectMin.' WHERE TRIM(t.NOCUST) = ?'.$order, [$n]];
}

$rows = [];
foreach ($attempts as $attempt) {
    $rows = mutasi_fetch($db, $attempt[0], $attempt[1]);
    if ($rows) {
        break;
    }
}

$mutasi = [];
$totalDebet = 0;
$totalKredit = 0;
foreach ($rows as $row) {
    $row = array_change_key_case($row, CASE_UPPER);
    $debet = (int) ($row['DEBET'] ?? 0);
    $kredit = (int) ($row['KREDIT'] ?? 0);
    $totalDebet += $debet;
    $totalKredit += $kredit;
    $mutasi[] = [
        'trxdate' => $row['TRXDATE'] ?? null,
        'metode' => $row['METODE'] ?? '',
        'keterangan' => $row['BILLTARGET'] ?? ($row['METODE'] ?? '-'),
        'noref' => $row['NOREFF'] ?? '',
        'transno' => $row['TRANSNO'] ?? '',
        'debet' => $debet,
        'kredit' => $kredit,
    ];
}

echo json_encode([
    'status' => true,
    'message' => $mutasi ? 'OK' : 'Tidak ada mutasi',
    'data' => [
        'mutasi' => $mutasi,
        'rows' => $mutasi,
        'mutasi_total_debet' => $totalDebet,
        'mutasi_total_kredit' => $totalKredit,
        'total_debet' => $totalDebet,
        'total_kredit' => $totalKredit,
    ],
], JSON_UNESCAPED_UNICODE);
