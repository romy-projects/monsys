<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19A / T19A-01: seed the merged chart of accounts.
 *
 * Merge rules (see docs/lapkeu-gap-analysis.md §2):
 *  - every account used by `COA`, `NS` or `JURNAL` exists exactly once;
 *  - duplicate labels are collapsed (5-132/5-133 PPh 22/23 kept at the tail,
 *    5-134 "Selisih Persediaan Tabung" from the NS sheet folded into 5-131);
 *  - `5-130 Aset tetap` from the COA sheet is NOT an expense — capex lives in
 *    1-143 Tabung LPG / 1-144 Akumulasi Penyusutan Tabung LPG;
 *  - 3-114 Prive and 1-143/1-144/2-113 are new codes the workbook implies but
 *    never numbered (LPM prive, budget-plan cylinder capex, PPh 22 payable).
 *
 * Seeded by migration (not by a seeder) for the same reason the access-control
 * permissions are: every environment and every test run needs the chart.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5?: bool}> */
    private const ACCOUNTS = [
        // Kode, Nama, Pos, Kelompok, Saldo Normal, Kas/Bank
        ['1-111', 'Kas', 'NR', 'asset', 'D', true],
        ['1-112', 'Bank BCA', 'NR', 'asset', 'D', true],
        ['1-113', 'Bank Mandiri', 'NR', 'asset', 'D', true],
        ['1-121', 'Piutang Dagang', 'NR', 'asset', 'D'],
        ['1-131', 'Persediaan LPG 5.5 Kg', 'NR', 'asset', 'D'],
        ['1-132', 'Persediaan LPG 12 Kg', 'NR', 'asset', 'D'],
        ['1-133', 'Persediaan LPG 50 Kg', 'NR', 'asset', 'D'],
        ['1-134', 'PPh 22 Dibayar di Muka', 'NR', 'asset', 'D'],
        ['1-135', 'PPh 23 Dibayar di Muka', 'NR', 'asset', 'D'],
        ['1-141', 'Peralatan', 'NR', 'asset', 'D'],
        ['1-142', 'Akumulasi Penyusutan Peralatan', 'NR', 'asset', 'K'],
        ['1-143', 'Tabung LPG (Aset Tetap)', 'NR', 'asset', 'D'],
        ['1-144', 'Akumulasi Penyusutan Tabung LPG', 'NR', 'asset', 'K'],
        ['1-151', 'Titipan Tabung Pertamina', 'NR', 'asset', 'D'],

        ['2-111', 'Hutang Usaha', 'NR', 'liability', 'K'],
        ['2-112', 'Hutang PPh 21', 'NR', 'liability', 'K'],
        ['2-113', 'Hutang PPh 22', 'NR', 'liability', 'K'],
        ['2-114', 'Hutang PPh 23', 'NR', 'liability', 'K'],
        ['2-115', 'Hutang PPN', 'NR', 'liability', 'K'],

        ['3-111', 'Modal Pemilik', 'NR', 'equity', 'K'],
        ['3-112', 'Laba Ditahan', 'NR', 'equity', 'K'],
        ['3-113', 'Laba/Rugi Berjalan', 'NR', 'equity', 'K'],
        ['3-114', 'Prive Pemilik', 'NR', 'equity', 'D'],

        ['4-111', 'Penjualan LPG 5.5 Kg', 'LR', 'revenue', 'K'],
        ['4-112', 'Penjualan LPG 12 Kg', 'LR', 'revenue', 'K'],
        ['4-113', 'Penjualan LPG 50 Kg', 'LR', 'revenue', 'K'],
        ['4-114', 'Pendapatan Isi Ulang', 'LR', 'revenue', 'K'],
        ['4-115', 'Penjualan Tabung', 'LR', 'revenue', 'K'],

        ['5-111', 'HPP LPG 5.5 Kg', 'LR', 'cogs', 'D'],
        ['5-112', 'HPP LPG 12 Kg', 'LR', 'cogs', 'D'],
        ['5-113', 'HPP LPG 50 Kg', 'LR', 'cogs', 'D'],
        ['5-114', 'HPP Tabung', 'LR', 'cogs', 'D'],

        ['5-121', 'Biaya Gaji Karyawan', 'LR', 'expense', 'D'],
        ['5-122', 'Biaya Listrik, Internet, PDAM', 'LR', 'expense', 'D'],
        ['5-123', 'Biaya Transport & Tiket', 'LR', 'expense', 'D'],
        ['5-124', 'Biaya Operasional', 'LR', 'expense', 'D'],
        ['5-125', 'Biaya Sewa Tempat', 'LR', 'expense', 'D'],
        ['5-126', 'Biaya Sewa Kendaraan', 'LR', 'expense', 'D'],
        ['5-127', 'Biaya Pemasaran & Entertaint', 'LR', 'expense', 'D'],
        ['5-128', 'Biaya Administrasi & Umum', 'LR', 'expense', 'D'],
        ['5-129', 'Biaya Pemeliharaan', 'LR', 'expense', 'D'],
        ['5-130', 'Biaya Pajak', 'LR', 'expense', 'D'],
        ['5-131', 'Selisih Persediaan Tabung', 'LR', 'expense', 'D'],
        ['5-132', 'Biaya Transportir & Expedisi', 'LR', 'expense', 'D'],
        ['5-133', 'Biaya Depresiasi & Penyusutan', 'LR', 'expense', 'D'],
        ['5-134', 'Biaya PPh 22', 'LR', 'expense', 'D'],
        ['5-135', 'Biaya PPh 23', 'LR', 'expense', 'D'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ACCOUNTS as $index => $row) {
            // $row[5] only exists for Kas/Bank accounts; ?? keeps PHP quiet.
            DB::table('chart_of_accounts')->updateOrInsert(
                ['code' => $row[0]],
                [
                    'name' => $row[1],
                    'pos' => $row[2],
                    'account_group' => $row[3],
                    'saldo_normal' => $row[4],
                    'is_cash_account' => (bool) ($row[5] ?? false),
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereIn('code', array_column(self::ACCOUNTS, 0))
            ->delete();
    }
};
