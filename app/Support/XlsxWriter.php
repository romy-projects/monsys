<?php

namespace App\Support;

use ZipArchive;

/**
 * Minimal zero-dependency XLSX writer using PHP's built-in ZipArchive.
 * Supports string + numeric cells, bold header rows, and basic styles.
 */
class XlsxWriter
{
    private array $rows      = [];
    private int   $boldUntil = 1; // rows 1..boldUntil are rendered bold

    public function addRow(array $cells): static
    {
        $this->rows[] = $cells;

        return $this;
    }

    /** How many leading rows to render bold (default 1 = header only). */
    public function boldRows(int $count): static
    {
        $this->boldUntil = $count;

        return $this;
    }

    public function download(string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = $this->build();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -------------------------------------------------------------------------

    private function build(): string
    {
        // tempnam() creates an empty placeholder file; we delete it and use the
        // same path so ZipArchive writes fresh without leaving an orphaned file.
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        @unlink($tmp);
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',       $this->xmlContentTypes());
        $zip->addFromString('_rels/.rels',               $this->xmlRels());
        $zip->addFromString('xl/workbook.xml',           $this->xmlWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels',$this->xmlWorkbookRels());
        $zip->addFromString('xl/styles.xml',             $this->xmlStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml',  $this->xmlSheet());

        $zip->close();

        return $tmp;
    }

    private function xmlSheet(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        foreach ($this->rows as $ri => $row) {
            $rowNum  = $ri + 1;
            $isBold  = $rowNum <= $this->boldUntil;
            $xml    .= '<row r="' . $rowNum . '">';

            foreach ($row as $ci => $cell) {
                $ref   = $this->col($ci + 1) . $rowNum;
                $style = $isBold ? '1' : '0';

                if (is_numeric($cell) && $cell !== '') {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $cell . '</v></c>';
                } else {
                    $val  = htmlspecialchars((string) $cell, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . $val . '</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s  = chr(65 + ($n % 26)) . $s;
            $n  = intdiv($n, 26);
        }

        return $s;
    }

    private function xmlStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            .   '<font><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function xmlContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xmlRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xmlWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xmlWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }
}
