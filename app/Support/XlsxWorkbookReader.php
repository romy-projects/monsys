<?php

namespace App\Support;

use ZipArchive;
use SimpleXMLElement;

/**
 * Minimal zero-dependency XLSX reader that reads ALL worksheets (not just sheet1).
 * Created for Phase 10 InitialFlowSeeder (Tabung Pangkalan 2026 workbook).
 */
class XlsxWorkbookReader
{
    private string $path;

    /**
     * @param string $path Absolute path to the .xlsx file.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Read every worksheet in workbook order.
     *
     * @return array<int, array<int, array<int, string>>>
     *         sheet index -> rows -> 0-based column index -> raw cell value.
     */
    public function read(): array
    {
        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw new \RuntimeException('Cannot open XLSX archive.');
        }

        $strings = $this->sharedStrings($zip);
        $sheets  = [];

        for ($n = 1; $n <= 64; $n++) {
            $content = $zip->getFromName("xl/worksheets/sheet{$n}.xml");
            if ($content === false) {
                break;
            }
            $sheets[] = $this->parseSheet($content, $strings);
        }

        $zip->close();

        return $sheets;
    }

    /** @return array<int, string> Map of shared string index -> text. */
    private function sharedStrings(ZipArchive $zip): array
    {
        $strings = [];
        $content = $zip->getFromName('xl/sharedStrings.xml');

        if ($content === false) {
            return $strings;
        }

        $xml = new SimpleXMLElement($content);

        foreach ($xml->si as $si) {
            $strings[] = (string) $si->t;
        }

        return $strings;
    }

    /** Parse one worksheet body into rows of cells (0-based column index -> value). */
    private function parseSheet(string $content, array $strings): array
    {
        $rows = [];
        $xml  = new SimpleXMLElement($content);

        foreach ($xml->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $ref   = (string) $cell['r'];
                $type  = (string) $cell['t'];
                $value = (string) $cell->v;

                if ($type === 's' && $value !== '') {
                    $index = (int) $value;
                    $value = $strings[$index] ?? '';
                }

                $cells[$this->colIndex(preg_replace('/[0-9]+$/', '', $ref))] = $value;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** Convert a column letter (A, B, ..., AA) to a zero-based index. */
    private function colIndex(string $letters): int
    {
        $upper = strtoupper($letters);
        $index = 0;

        for ($i = 0; $i < strlen($upper); $i++) {
            $index = $index * 26 + (ord($upper[$i]) - 64);
        }

        return $index - 1;
    }
}