<?php
/**
 * ExcelIO — gerçek .xlsx yazma ve .xlsx / .csv okuma.
 *
 * - Yazma: hücreler metin (inlineStr) ya da sayı olarak yazılır; metin hücreleri asla formül
 *   olarak çalışmaz (Excel formül enjeksiyonuna karşı). ZipArchive yoksa CSV'ye düşer.
 * - Okuma: başlık satırı sütun ADIYLA eşlenir; sütun sırası önemli değildir.
 */
class ExcelIO {

    /* ================= YAZMA ================= */

    /**
     * Dosyayı tarayıcıya indirir ve çıkar.
     * $headers: ['Stock Code', ...]  $rows: iterable of arrays (aynı sırada)
     * $opts: ['sheet' => 'Products', 'widths' => [14, 40, ...], 'numeric' => [index => true]]
     */
    public static function download($filename, array $headers, iterable $rows, array $opts = []) {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        while (ob_get_level()) ob_end_clean();

        if (!class_exists('ZipArchive')) {
            self::downloadCsv(preg_replace('/\.xlsx$/i', '.csv', $filename), $headers, $rows);
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'frx');
        self::writeXlsx($tmp, $headers, $rows, $opts);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public static function writeXlsx($path, array $headers, iterable $rows, array $opts = []) {
        $sheetName = self::xml(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $opts['sheet'] ?? 'Sheet1'), 0, 31));
        $numeric = $opts['numeric'] ?? [];

        // Sayfa XML'i geçici dosyaya akıtılır (12.000+ satırda bellek dostu)
        $sheetTmp = tempnam(sys_get_temp_dir(), 'frs');
        $fh = fopen($sheetTmp, 'w');
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        if (!empty($opts['widths'])) {
            fwrite($fh, '<cols>');
            foreach (array_values($opts['widths']) as $i => $w) fwrite($fh, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>');
            fwrite($fh, '</cols>');
        }
        fwrite($fh, '<sheetData>');
        fwrite($fh, self::rowXml(1, $headers, [], 1));
        $r = 1;
        foreach ($rows as $row) {
            $r++;
            fwrite($fh, self::rowXml($r, array_values($row), $numeric, 0));
        }
        fwrite($fh, '</sheetData><autoFilter ref="A1:' . self::col(count($headers) - 1) . max(1, $r) . '"/></worksheet>');
        fclose($fh);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create xlsx file');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", html_entity_decode($sheetName)) . '\'!$A$1:$' . self::col(count($headers) - 1) . '$' . max(1, $r) . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Stil 1: kalın başlık, açık zemin
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3EDE2"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFile($sheetTmp, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheetTmp);
    }

    private static function rowXml($r, array $cells, array $numeric, $style) {
        $x = '<row r="' . $r . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i) . $r;
            $s = $style ? ' s="1"' : '';
            if ($v === null || $v === '') { continue; }
            if (!$style && !empty($numeric[$i]) && is_numeric($v)) {
                $x .= '<c r="' . $ref . '"' . $s . '><v>' . (0 + $v) . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . self::xml((string)$v) . '</t></is></c>';
            }
        }
        return $x . '</row>';
    }

    private static function xml($s) {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function col($i) {
        $s = '';
        for ($i = (int)$i; $i >= 0; $i = intdiv($i, 26) - 1) $s = chr(65 + $i % 26) . $s;
        return $s;
    }

    private static function downloadCsv($filename, array $headers, iterable $rows) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) fputcsv($out, array_map([self::class, 'csvSafe'], array_values($row)));
        fclose($out);
        exit;
    }

    /** CSV'de =, +, -, @ ile başlayan metinler formül olarak çalışmasın */
    public static function csvSafe($v) {
        if (is_string($v) && $v !== '' && !is_numeric($v) && strpbrk($v[0], "=+-@\t\r") !== false) return "'" . $v;
        return $v;
    }

    /* ================= OKUMA ================= */

    /**
     * Yüklenen dosyayı okur. Döner: ['headers' => [..], 'rows' => [[..], ..]] (satır 2'den itibaren)
     * Hata: RuntimeException (kullanıcıya gösterilebilir mesaj)
     */
    public static function read($path, $originalName) {
        $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $all = self::readCsv($path);
        } elseif ($ext === 'xlsx') {
            require_once __DIR__ . '/../SimpleXLSX.php';
            $x = \Shuchkin\SimpleXLSX::parse($path);
            if (!$x) throw new RuntimeException('The Excel file could not be read: ' . \Shuchkin\SimpleXLSX::parseError());
            $all = $x->rows();
        } elseif ($ext === 'xls') {
            throw new RuntimeException('Old .xls files are not supported. In Excel choose File → Save As → "Excel Workbook (.xlsx)" and upload again.');
        } else {
            throw new RuntimeException('Please upload an .xlsx or .csv file.');
        }
        // Tamamen boş satırları at
        $all = array_values(array_filter($all, fn($r) => implode('', array_map(fn($c) => trim((string)$c), (array)$r)) !== ''));
        if (!$all) throw new RuntimeException('The file is empty.');
        $headers = array_map(fn($h) => trim((string)$h), array_shift($all));
        return ['headers' => $headers, 'rows' => $all];
    }

    private static function readCsv($path) {
        $raw = file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1254');
        $first = strtok($raw, "\n");
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            // Dışa aktarmada formül koruması için eklenen ' işaretini geri al
            $rows[] = array_map(fn($c) => (is_string($c) && strlen($c) > 1 && $c[0] === "'" && strpbrk($c[1], "=+-@") !== false) ? substr($c, 1) : $c, $r);
        }
        fclose($fh);
        return $rows;
    }

    /** Başlık adını karşılaştırma için sadeleştirir: "Unit Cost (USD)" → "unitcostusd" */
    public static function norm($h) {
        $h = mb_strtolower(trim((string)$h));
        $h = strtr($h, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'İ' => 'i']);
        return preg_replace('/[^a-z0-9]/', '', $h);
    }

    /**
     * Başlıkları alan adlarına eşler. $fields: ['stock_code' => ['Stock Code', 'Code'], ...]
     * Döner: ['map' => [field => colIndex], 'unknown' => [başlık, ...]]
     */
    public static function mapHeaders(array $headers, array $fields) {
        $lookup = [];
        foreach ($fields as $key => $aliases) foreach ((array)$aliases as $a) $lookup[self::norm($a)] = $key;
        $map = []; $unknown = [];
        foreach ($headers as $i => $h) {
            $n = self::norm($h);
            if ($n === '') continue;
            if (str_contains(mb_strtolower($h), '(info)')) continue;   // bilgi amaçlı sütunlar okunmaz
            if (isset($lookup[$n]) && !isset($map[$lookup[$n]])) $map[$lookup[$n]] = $i;
            else $unknown[] = $h;
        }
        return ['map' => $map, 'unknown' => $unknown];
    }

    /** "1,234.50", "1234,5", " 12 " → float; geçersizse null */
    public static function num($v) {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = str_replace([' ', "\u{00A0}", '$', '₦', '%'], '', trim((string)$v));
        if ($s === '') return null;
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) $s = str_replace(',', '', $s);      // 1,234.56
        elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s); // 1.234,56
        elseif (preg_match('/^-?\d+,\d+$/', $s)) $s = str_replace(',', '.', $s);                 // 12,5
        return is_numeric($s) ? (float)$s : null;
    }
}
