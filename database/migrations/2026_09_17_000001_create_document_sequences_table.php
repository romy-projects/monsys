<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document types that get a server-assigned, gap-free sequence.
     * Prefixes: SO/DO/LO/PO for delivery_orders.do_number, INV for invoices.invoice_number.
     */
    private const TYPES = ['so', 'do', 'lo', 'po', 'inv'];

    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 10)->comment("'so' | 'do' | 'lo' | 'po' | 'inv'");
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0)->comment('Last allocated number for this type/year');
            $table->timestamps();

            $table->unique(['document_type', 'year'], 'document_sequences_type_year_unique');
        });

        $this->backfill((int) date('Y'));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }

    /**
     * Seed each counter from the highest sequence already in the database — without this the
     * first allocated number would immediately collide with an existing document (the database
     * already holds ~600 documents numbered like PO2026-001).
     *
     * The scan is done in PHP rather than with SQL REGEXP/SUBSTRING_INDEX so this migration also
     * runs on the SQLite in-memory database used by the test suite (see "Cross-DB Fixes").
     */
    private function backfill(int $year): void
    {
        foreach (self::TYPES as $type) {
            DB::table('document_sequences')->updateOrInsert(
                ['document_type' => $type, 'year' => $year],
                [
                    'last_number' => $this->maxExistingNumber($type, $year),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
            );
        }
    }

    /**
     * Highest `NNN` among numbers matching "XX<year>-NNN" for the given document type.
     *
     * Hand-entered or legacy numbers that do not match the format are ignored; the allocator
     * additionally re-checks existence before returning a number, so an imperfect backfill
     * cannot cause a duplicate.
     */
    private function maxExistingNumber(string $type, int $year): int
    {
        $prefix = strtoupper($type) . $year . '-';

        $numbers = $type === 'inv'
            ? DB::table('invoices')->whereYear('created_at', $year)->pluck('invoice_number')
            : DB::table('delivery_orders')
                ->where('document_type', $type)
                ->whereYear('created_at', $year)
                ->pluck('do_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $number, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max;
    }
};
