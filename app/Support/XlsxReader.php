<?php

namespace App\Support;

use ZipArchive;
use SimpleXMLElement;

/**
 * Minimal zero-dependency XLSX reader using PHP's built-in ZipArchive + SimpleXMLElement.
 * Parses string/numeric cells from the first worksheet.
 * Includes security checks: file signature, size limit, max rows, cell sanitization.
 */
class XlsxReader
{
    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB
    private const MAX_ROWS      = 1000;

    private string $path;
    private array  $rows = [];

    /**
     * @throws \RuntimeException
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->validate();
        $this->parse();
    }

    /**
     * Get parsed rows (sanitized).
     * @return array<int, array<int, string|float|int|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Get header row (first row).
     */
    public function header(): array
    {
        return $this->rows[0] ?? [];
    }

    /**
     * Get data rows (all rows after header).
     */
    public function data(): array
    {
        return array_slice($this->rows, 1);
    }

    // ── Security & Validation ─────────────────────────────────

    private function validate(): void
    {
        // File existence
        if (! file_exists($this->path)) {
            throw new \RuntimeException('File not found.');
        }

        // File size
        if (filesize($this->path) > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File exceeds maximum size of 2 MB.');
        }

        // Real XLSX check: ZIP signature
        $fh = fopen($this->path, 'rb');
        $sig = fread($fh, 4);
        fclose($fh);

        if ($sig !== "PK\x03\x04") {
            throw new \RuntimeException('Invalid file format. Only .xlsx files are accepted.');
        }
    }

    private function parse(): void
    {
        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw new \RuntimeException('Cannot open XLSX archive.');
        }

        // Read shared strings (if present)
        $sharedStrings = [];
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssContent !== false) {
            $ssXml = new SimpleXMLElement($ssContent);
            foreach ($ssXml->si as $si) {
                $sharedStrings[] = (string) $si->t;
            }
        }

        // Read sheet data
        $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetContent === false) {
            throw new \RuntimeException('XLSX file has no worksheet.');
        }

        // Suppress XML errors for malformed content
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($sheetContent);
        if ($xml === false) {
            throw new \RuntimeException('Corrupted XLSX file: cannot parse worksheet XML.');
        }

        $ns = $xml->getNamespaces(true);
        $sheetXml = $xml;

        // Register the main namespace
        $sheetXml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = $sheetXml->xpath('//s:sheetData/s:row');
        if (! $rows) {
            return; // empty sheet
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new \RuntimeException('File exceeds maximum of ' . self::MAX_ROWS . ' rows.');
        }

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $ref   = (string) $cell['r'];
                $type  = (string) $cell['t'];
                $value = (string) $cell->v;

                // Resolve shared string
                if ($type === 's' && $value !== '') {
                    $idx = (int) $value;
                    $val = $sharedStrings[$idx] ?? '';
                } else {
                    $val = $value;
                }

                // Sanitize: strip HTML, prevent formula injection
                $val = $this->sanitize($val);

                // Type cast
                if (is_numeric($val)) {
                    $val = str_contains($val, '.') ? (float) $val : (int) $val;
                }

                $cells[] = $val;
            }

            // Re-index cells by column letter
            $indexed = [];
            foreach ($row->c as $cell) {
                $colLetter = preg_replace('/[0-9]/', '', (string) $cell['r']);
                $colIdx    = $this->colIndex($colLetter);
                $type      = (string) $cell['t'];
                $value     = (string) $cell->v;

                if ($type === 's' && $value !== '') {
                    $idx = (int) $value;
                    $val = $sharedStrings[$idx] ?? '';
                } else {
                    $val = $value;
                }

                $val = $this->sanitize($val);

                if (is_numeric($val)) {
                    $val = str_contains($val, '.') ? (float) $val : (int) $val;
                }

                $indexed[$colIdx] = $val;
            }

            // Fill gaps with null
            $maxCol = $indexed ? max(array_keys($indexed)) : 0;
            $filled = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $filled[] = $indexed[$i] ?? null;
            }

            $this->rows[] = $filled;
        }
    }

    /**
     * Sanitize cell value: strip HTML, prevent formula injection.
     */
    private function sanitize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Strip HTML tags
        $value = strip_tags($value);

        // Prevent Excel formula injection (=, +, -, @ at start)
        $value = ltrim($value, '=+-@');

        // Trim whitespace
        $value = trim($value);

        return $value;
    }

    /**
     * Convert column letter to zero-based index (A=0, B=1, ..., Z=25, AA=26).
     */
    private function colIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $index  = 0;
        $len    = strlen($letter);

        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }

        return $index - 1;
    }
}