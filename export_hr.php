<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* ========= CONFIG ========= */
$dbHost = '127.0.0.1';
$dbName = 'hr';
$dbUser = 'root';
$dbPass = '';
$table  = 'employees';

/* ========= OUTPUT (SAFE PATH) ========= */
$outDir = '/tmp';
$outXml = $outDir . '/hr_export.xml';
$outDtd = $outDir . '/hr_export.dtd';
$exportedAt = date('c');

try {
    /* ========= CONNECT ========= */
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    /* ========= PK AUTO ========= */
    $pkStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = :db
          AND TABLE_NAME = :t
          AND CONSTRAINT_NAME = 'PRIMARY'
        LIMIT 1
    ");
    $pkStmt->execute([':db' => $dbName, ':t' => $table]);
    $pk = (string)$pkStmt->fetchColumn();
    if ($pk === '') {
        throw new RuntimeException("Не е намерен PRIMARY KEY за таблицата {$table}.");
    }

    /* ========= COLUMNS (ORDERED) ========= */
    $colStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :db
          AND TABLE_NAME = :t
        ORDER BY ORDINAL_POSITION
    ");
    $colStmt->execute([':db' => $dbName, ':t' => $table]);
    $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$columns) {
        throw new RuntimeException("Няма колони за {$dbName}.{$table}.");
    }

    /* ========= DATA ========= */
    $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        throw new RuntimeException("Таблицата {$table} няма данни.");
    }

    /* ========= CONTROL ========= */
    $rowCount = count($rows);
    $columnCount = count($columns);

    $pkValues = array_map(fn($r) => (string)$r[$pk], $rows);
    $minId = min($pkValues);
    $maxId = max($pkValues);

    // checksum = sum(numeric PKs) + lengths of all text fields (NULL ignored)
    $checksum = 0;

    foreach ($pkValues as $v) {
        $checksum += is_numeric($v) ? (int)$v : mb_strlen($v, 'UTF-8');
    }
    foreach ($rows as $r) {
        foreach ($columns as $c) {
            $val = $r[$c] ?? null;
            if ($val === null) continue;
            if (is_numeric($val)) continue;
            $checksum += mb_strlen((string)$val, 'UTF-8');
        }
    }

    /* ========= WRITE DTD ========= */
    $dtdLines = [];
    $dtdLines[] = "<!ELEMENT hrExport (rows, control)>";
    $dtdLines[] = "<!ATTLIST hrExport table CDATA #REQUIRED exportedAt CDATA #REQUIRED>";
    $dtdLines[] = "<!ELEMENT rows (row+)>";
    $dtdLines[] = "<!ELEMENT row (" . implode(', ', $columns) . ")>";
    foreach ($columns as $c) $dtdLines[] = "<!ELEMENT {$c} (#PCDATA)>";
    $dtdLines[] = "<!ELEMENT control (rowCount, columnCount, minId, maxId, checksum)>";
    $dtdLines[] = "<!ELEMENT rowCount (#PCDATA)>";
    $dtdLines[] = "<!ELEMENT columnCount (#PCDATA)>";
    $dtdLines[] = "<!ELEMENT minId (#PCDATA)>";
    $dtdLines[] = "<!ELEMENT maxId (#PCDATA)>";
    $dtdLines[] = "<!ELEMENT checksum (#PCDATA)>";

    if (file_put_contents($outDtd, implode(PHP_EOL, $dtdLines) . PHP_EOL) === false) {
        throw new RuntimeException("Не мога да запиша DTD файла в {$outDtd}");
    }

    /* ========= BUILD XML ========= */
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $impl = new DOMImplementation();
    $dom->appendChild($impl->createDocumentType('hrExport', '', basename($outDtd)));

    $root = $dom->createElement('hrExport');
    $root->setAttribute('table', $table);
    $root->setAttribute('exportedAt', $exportedAt);
    $dom->appendChild($root);

    $rowsEl = $dom->createElement('rows');
    $root->appendChild($rowsEl);

    foreach ($rows as $r) {
        $rowEl = $dom->createElement('row');
        foreach ($columns as $c) {
            $val = $r[$c] ?? null;
            $cell = $dom->createElement($c);
            if ($val !== null) {
                $cell->appendChild($dom->createTextNode((string)$val));
            }
            $rowEl->appendChild($cell);
        }
        $rowsEl->appendChild($rowEl);
    }

    $control = $dom->createElement('control');
    $root->appendChild($control);

    $control->appendChild($dom->createElement('rowCount', (string)$rowCount));
    $control->appendChild($dom->createElement('columnCount', (string)$columnCount));
    $control->appendChild($dom->createElement('minId', (string)$minId));
    $control->appendChild($dom->createElement('maxId', (string)$maxId));
    $control->appendChild($dom->createElement('checksum', (string)$checksum));

    if ($dom->save($outXml) === false) {
        throw new RuntimeException("Не мога да запиша XML файла в {$outXml}");
    }

    echo "OK<br>";
    echo "PK detected: <b>" . htmlspecialchars($pk, ENT_QUOTES, 'UTF-8') . "</b><br>";
    echo "Saved to: <b>/tmp</b><br>";
    echo "XML: <b>{$outXml}</b><br>";
    echo "DTD: <b>{$outDtd}</b><br>";
    echo "rowCount={$rowCount}, columnCount={$columnCount}, minId={$minId}, maxId={$maxId}, checksum={$checksum}<br>";

} catch (Throwable $e) {
    http_response_code(500);
    echo "<b>ERROR:</b> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
