<?php
declare(strict_types=1);

$filename = 'template-data-hasil-iklan.xlsx';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'Ekstensi ZIP PHP belum aktif, template .xlsx tidak bisa dibuat.';
    exit;
}

$headers = [
    'Nama Lead',
    'No HP WA',
    'Email',
    'Kampus',
    'Jurusan',
    'Kota Asal',
    'Hasil Follow Up',
    'Status Progress',
    'Status Closing',
    'Update Closing',
    'Catatan Kendala Progress',
];

$examples = [
    ['Contoh Nama Lead', '081234567890', 'lead@example.com', 'Contoh Kampus', 'Manajemen', 'Surabaya', 'Sudah dihubungi via WA', 'Prospek', 'Closing', 'Potensi daftar minggu ini', 'Minta info biaya'],
    ['Contoh Lead 2', '082222222222', 'lead2@example.com', 'Contoh Kampus 2', 'Akuntansi', 'Malang', 'Belum respon', 'Follow up ulang', 'Belum closing', 'Belum ada closing', 'Hubungi kembali sore'],
];

$closingOptions = ['Belum closing', 'Potensi closing', 'Closing', 'Herregistrasi', 'Tidak closing'];
$tempFile = tempnam(sys_get_temp_dir(), 'rsm-template-');
if ($tempFile === false) {
    http_response_code(500);
    echo 'Template sementara tidak bisa dibuat.';
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Template .xlsx tidak bisa dibuat.';
    @unlink($tempFile);
    exit;
}

$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Data Hasil Iklan" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

$zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>');

$sheetRows = [];
$sheetRows[] = xlsx_row(1, $headers, true);
foreach ($examples as $index => $row) {
    $sheetRows[] = xlsx_row($index + 2, $row, false);
}

$validationList = htmlspecialchars('"' . implode(',', $closingOptions) . '"', ENT_QUOTES | ENT_XML1, 'UTF-8');
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="11" width="24" customWidth="1"/>
  </cols>
  <sheetData>
    ' . implode("\n    ", $sheetRows) . '
  </sheetData>
  <dataValidations count="1">
    <dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="I2:I500">
      <formula1>' . $validationList . '</formula1>
    </dataValidation>
  </dataValidations>
</worksheet>');

$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($tempFile);
@unlink($tempFile);

function xlsx_row(int $rowNumber, array $values, bool $header): string
{
    $cells = [];
    foreach ($values as $index => $value) {
        $ref = xlsx_column_name($index + 1) . $rowNumber;
        $style = $header ? ' s="1"' : '';
        $cells[] = '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t>' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
    }
    return '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
}

function xlsx_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}
