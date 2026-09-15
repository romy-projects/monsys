<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CylinderCirculation;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\StockClose;
use App\Models\StockItem;
use App\Models\StockMutation;
use App\Models\Transportir;
use App\Models\User;
use App\Support\XlsxWorkbookReader;
use Illuminate\Database\Seeder;

/**
 * Phase 10 — Initial Data Seeder (Tabung Pangkalan 2026).
 *
 * Replicates the client's real tabung-circulation recap workbook (11 pangkalan
 * sheets) as full demo data:
 *   - masters: transportirs (Willy/Tatik, Tri/Pak Tri), 11 pangkalan branches and
 *     1 default sea expedition
 *   - per pangkalan: full document chain (SO supply -> DO supply -> LO loading,
 *     then PO request -> DO delivery) for each distinct SO# found in the ledger
 *   - cylinder_circulations ledger rows (faithful DEBIT/KREDIT copy)
 *   - stock_mutations for every delivered DO (mirrors DeliveryOrderObserver::handleDelivered)
 *   - stock_items + stock_closes (Golden Rule: branches need a close before PO/DO)
 *
 * Idempotent: guarded on the InitialFlowSeeder marker in delivery_orders.notes.
 *
 * Run:
 *   php artisan tinker --execute="(new Database\Seeders\InitialFlowSeeder())->run();"
 */
class InitialFlowSeeder extends Seeder
{
    private const string XLSX_PATH = __DIR__ . '/../data/TABUNG PANGKALAN 2026.xlsx';

    private const string MARKER = 'InitialFlowSeeder';

    /** Sheet index (0-based) -> branch meta. Order matches workbook sheet order. */
    private array $pangkalan = [
        0  => ['name' => 'MJ Ruteng',              'city' => 'Ruteng'],
        1  => ['name' => 'Ce Sinta',               'city' => 'Atambua'],
        2  => ['name' => 'Cici Maumere',           'city' => 'Maumere'],
        3  => ['name' => 'ELISA Labuan Bajo',      'city' => 'Labuan Bajo'],
        4  => ['name' => 'Om Rio Labuan Bajo',     'city' => 'Labuan Bajo'],
        5  => ['name' => 'Jul Labuan Bajo',        'city' => 'Labuan Bajo'],
        6  => ['name' => 'Jul Sumba',              'city' => 'Sumba'],
        7  => ['name' => 'Jeni Ende',              'city' => 'Ende'],
        8  => ['name' => 'Audry MBAY Ende',        'city' => 'Ende'],
        9  => ['name' => 'Lewoleba / Lembata',     'city' => 'Lembata'],
        10 => ['name' => 'Pak Jhonry (PT Siantar)', 'city' => 'Siantar'],
    ];

    /** Document number counters (per prefix) — emulates the app's auto-numbering. */
    private array $counters = ['so' => 1, 'do' => 1, 'lo' => 1, 'po' => 1];

    /** Branch code counter for newly created pangkalan branches. */
    private int $branchCode = 10;

    public function run(): void
    {
        if (DeliveryOrder::where('notes', 'like', '%' . self::MARKER . '%')->exists()) {
            echo '[InitialFlowSeeder] Already applied — skipping.' . PHP_EOL;
            return;
        }

        $main  = Branch::mainBranch()->first() ?? Branch::find(1);
        $owner = User::where('role', 'owner_pusat')->first() ?? User::find(1);

        $willy  = $this->transportir('Willy', 'TRT-001', 'Tatik');
        $pakTri = $this->transportir('Tri (Pak Tri)', 'TRT-002', 'Tri');
        $exped  = $this->expedition();

        $sheets = (new XlsxWorkbookReader(self::XLSX_PATH))->read();

        foreach ($sheets as $idx => $sheet) {
            $meta = $this->pangkalan[$idx] ?? null;
            if (is_null($meta)) {
                continue;
            }
            $this->seedPangkalan($meta, $sheet, $main, $owner, $willy, $pakTri, $exped);
        }

        // ── Stock snapshot + Golden-Rule closes for every branch ──
        $this->runStockSnapshot();

        echo '[InitialFlowSeeder] Done.' . PHP_EOL;
    }

    /**
     * Seed one pangkalan sheet: document chain(s), circulation ledger, stock.
     */
    private function seedPangkalan(array $meta, array $sheet, Branch $main, User $owner, Transportir $willy, Transportir $pakTri, Expedition $exped): void
    {
        $branch = $this->branch($meta, $main);
        $rows   = $this->parseRows($sheet);

        if (count($rows) === 0) {
            return;
        }

        // ── Document chain: one per distinct SO#, else the first KREDIT row ──
        foreach ($this->buildChains($rows) as $chain) {
            $transportir = $this->detectTransportir($chain['notes'], $willy, $pakTri) ?? $pakTri;

            foreach ($chain['quantities'] as $type => $qty) {
                $this->createChain($branch, $main, $owner, $transportir, $exped, $chain['date'], $chain['so_number'], $chain['container'], $type, $qty);
            }
        }

        // ── Circulation ledger (faithful DEBIT/KREDIT copy) ──
        $this->createCirculations($branch, $owner, $rows);
    }
// =============================================================
    // Masters
    // =============================================================

    /** Upsert a transportir by name. */
    private function transportir(string $name, string $code, string $contactPerson): Transportir
    {
        return Transportir::firstOrCreate(['name' => $name], [
            'code'           => $code,
            'phone'          => '',
            'contact_person' => $contactPerson,
            'status'         => 'active',
        ]);
    }

    /** Default sea-freight expedition used for every seeded shipment. */
    private function expedition(): Expedition
    {
        return Expedition::firstOrCreate(['name' => 'Ekspedisi Sea (RORO)'], [
            'code'           => 'EXP-101',
            'phone'          => '',
            'contact_person' => 'Sea Freight NTT',
            'status'         => 'active',
        ]);
    }

    /** Get or create the pangkalan branch for one sheet. */
    private function branch(array $meta, Branch $main): Branch
    {
        $existing = Branch::where('name', $meta['name'])->first();
        if ($existing) {
            return $existing;
        }

        $this->branchCode++;

        return Branch::create(array_merge($meta, [
            'code'       => 'PGK-' . str_pad($this->branchCode, 3, '0', STR_PAD_LEFT),
            'province'   => 'East Nusa Tenggara',
            'address'    => '',
            'phone'      => '',
            'status'     => 'active',
            'regional_id'=> $main->id,
        ]));
    }

    // =============================================================
    // Document chain — SO -> DO(supply) -> LO, then PO -> DO(delivery)
    // =============================================================

    /** Create the full 4-document chain for one cylinder type/quantity leg. */
    private function createChain(Branch $branch, Branch $main, User $owner, Transportir $transportir, Expedition $exped, string $date, string $soNumber, string $container, string $type, int $qty): void
    {
        $defaultContainer = $container !== '' ? $container : 'TANPA KONTAINER';

        // 1) SO — SUM orders tabung from Pertamina
        $soNumberGenerated = $this->nextNumber('so');
        $this->createDoc([
            'do_number'            => $soNumberGenerated,
            'document_type'        => 'so',
            'counterparty_type'    => 'pertamina',
            'counterparty_name'    => 'Pertamina',
            'so_number'            => $soNumber,
            'order_type'           => 'supplier',
            'origin_branch_id'     => $main->id,
            'destination_branch_id'=> null,
            'transportir_id'       => $transportir->id,
            'expedition_id'        => $exped->id,
            'transportir_name'     => $transportir->name,
            'container_number'     => $defaultContainer,
            'cylinder_type'        => $type,
            'quantity_ordered'     => $qty,
            'order_date'           => $date,
            'eta'                  => $date,
            'status'               => 'approved',
            'requested_by'         => $owner->id,
            'approved_by'          => $owner->id,
            'approved_at'          => $date . ' 08:00:00',
            'notes'                => self::MARKER . ' | Sales Order to Pertamina — ' . $branch->name,
        ]);

        // 2) DO (supply) — auto-created from the SO, Pertamina delivers to SUM
        $doSupply = $this->nextNumber('do');
        $this->createDoc([
            'do_number'            => $doSupply,
            'document_type'        => 'do',
            'counterparty_type'    => 'pertamina',
            'counterparty_name'    => 'Pertamina',
            'so_number'            => $soNumberGenerated,
            'order_type'           => 'supplier',
            'origin_branch_id'     => $main->id,
            'destination_branch_id'=> null,
            'transportir_id'       => $transportir->id,
            'expedition_id'        => $exped->id,
            'transportir_name'     => $transportir->name,
            'container_number'     => $defaultContainer,
            'cylinder_type'        => $type,
            'quantity_ordered'     => $qty,
            'quantity_received'    => $qty,
            'order_date'           => $date,
            'received_date'        => $date,
            'status'               => 'delivered',
            'shipment_status'      => 'delivered_to_destination',
            'requested_by'         => $owner->id,
            'approved_by'          => $owner->id,
            'approved_at'          => $date . ' 08:00:00',
            'notes'                => self::MARKER . ' | Auto-created from SO #' . $soNumberGenerated . ' (Pertamina supply)',
        ]);
        $this->stockMutation($main->id, $type, 'in', $qty, $doSupply, 'Purchase from Pertamina — DO #' . $doSupply, $date, $owner->id);
// 3) LO — loading order, transportir picks up from Pertamina
        $this->createDoc([
            'do_number'            => $this->nextNumber('lo'),
            'document_type'        => 'lo',
            'counterparty_type'    => 'pertamina',
            'counterparty_name'    => 'Pertamina',
            'so_number'            => $soNumberGenerated,
            'order_type'           => 'supplier',
            'origin_branch_id'     => $main->id,
            'destination_branch_id'=> null,
            'transportir_id'       => $transportir->id,
            'expedition_id'        => $exped->id,
            'transportir_name'     => $transportir->name,
            'container_number'     => $defaultContainer,
            'cylinder_type'        => $type,
            'quantity_ordered'     => $qty,
            'order_date'           => $date,
            'loading_date'         => $date,
            'loaded_by'            => $owner->id,
            'status'               => 'approved',
            'requested_by'         => $owner->id,
            'notes'                => self::MARKER . ' | Loading Order — ' . $transportir->name . ' takes tabung from Pertamina',
        ]);

        // 4) PO — pangkalan requests tabung from SUM
        $poNumber = $this->nextNumber('po');
        $this->createDoc([
            'do_number'            => $poNumber,
            'document_type'        => 'po',
            'counterparty_type'    => 'branch',
            'counterparty_name'    => $main->name,
            'order_type'           => 'inter_branch',
            'origin_branch_id'     => $main->id,
            'destination_branch_id'=> $branch->id,
            'transportir_id'       => $transportir->id,
            'expedition_id'        => $exped->id,
            'transportir_name'     => $transportir->name,
            'container_number'     => $defaultContainer,
            'cylinder_type'        => $type,
            'quantity_ordered'     => $qty,
            'order_date'           => $date,
            'eta'                  => $date,
            'status'               => 'approved',
            'requested_by'         => $owner->id,
            'approved_by'          => $owner->id,
            'approved_at'          => $date . ' 09:00:00',
            'notes'                => self::MARKER . ' | Purchase Order from ' . $branch->name,
        ]);

        // 5) DO (delivery) — auto-created from the PO, delivered to the pangkalan
        $doDelivery = $this->nextNumber('do');
        $this->createDoc([
            'do_number'            => $doDelivery,
            'document_type'        => 'do',
            'counterparty_type'    => 'branch',
            'counterparty_name'    => $main->name,
            'so_number'            => $soNumber,
            'po_number'            => $poNumber,
            'order_type'           => 'inter_branch',
            'origin_branch_id'     => $main->id,
            'destination_branch_id'=> $branch->id,
            'transportir_id'       => $transportir->id,
            'expedition_id'        => $exped->id,
            'transportir_name'     => $transportir->name,
            'container_number'     => $defaultContainer,
            'cylinder_type'        => $type,
            'quantity_ordered'     => $qty,
            'quantity_received'    => $qty,
            'order_date'           => $date,
            'received_date'        => $date,
            'status'               => 'delivered',
            'shipment_status'      => 'delivered_to_destination',
            'requested_by'         => $owner->id,
            'approved_by'          => $owner->id,
            'approved_at'          => $date . ' 09:00:00',
            'notes'                => self::MARKER . ' | Auto-created from PO #' . $poNumber . ' — delivered to ' . $branch->name,
        ]);
        $this->stockMutation($main->id,  $type, 'out', $qty, $doDelivery, 'HPP for DO #' . $doDelivery . ' — ' . $branch->name, $date, $owner->id);
        $this->stockMutation($branch->id, $type, 'in',  $qty, $doDelivery, 'Purchase/receipt from DO #' . $doDelivery, $date, $owner->id);
    }

    /** Create a DeliveryOrder without firing observers (we create mutations explicitly). */
    private function createDoc(array $data): DeliveryOrder
    {
        $doc = new DeliveryOrder($data);
        $doc->saveQuietly();

        return $doc;
    }

    /** Insert one StockMutation row (mirrors the observer's handleDelivered behaviour). */
    private function stockMutation(int $branchId, string $type, string $mutationType, int $qty, string $ref, string $notes, string $date, int $createdBy): void
    {
        StockMutation::create([
            'branch_id'    => $branchId,
            'cylinder_type'=> $type,
            'mutation_type'=> $mutationType,
            'quantity'     => $qty,
            'reference_no' => $ref,
            'notes'        => $notes,
            'mutation_date'=> $date,
            'created_by'   => $createdBy,
        ]);
    }
// =============================================================
    // Circulation ledger
    // =============================================================

    /** Insert one cylinder_circulations row per non-zero DEBIT/KREDIT cell. */
    private function createCirculations(Branch $branch, User $owner, array $rows): void
    {
        foreach ($rows as $row) {
            $typeName = $this->transactionType($row['uraian']);

            foreach ($row['debit'] as $type => $qty) {
                $this->circulation($branch, $owner, $row, $typeName, $type, $qty, 'debit');
            }
            foreach ($row['kredit'] as $type => $qty) {
                $this->circulation($branch, $owner, $row, $typeName, $type, $qty, 'kredit');
            }
        }
    }

    private function circulation(Branch $branch, User $owner, array $row, string $typeName, string $type, int $qty, string $direction): void
    {
        CylinderCirculation::create([
            'branch_id'        => $branch->id,
            'transaction_date' => $row['date'],
            'so_number'        => $row['so'],
            'transaction_type' => $typeName,
            'description'      => $row['uraian'],
            'cylinder_type'    => $type,
            'direction'        => $direction,
            'quantity'         => $qty,
            'container_no'     => $row['container'] !== '' ? $row['container'] : 'TANPA KONTAINER',
            'handled_by'       => $row['notes'],
            'notes'            => $row['notes'],
            'created_by'       => $owner->id,
        ]);
    }

    /** Map the ledger URAIAN text to a cylinder_circulations transaction_type. */
    private function transactionType(string $uraian): string
    {
        $u = strtolower($uraian);

        if (str_contains($u, 'bongkar')) return 'bongkar_kosong';
        if (str_contains($u, 'beli') || str_contains($u, 'pembelian')) return 'pembelian';
        if (str_contains($u, 'kirim'))   return 'kirim';

        return 'penyesuaian';
    }
// =============================================================
    // Parsing
    // =============================================================

    /** Extract data rows from one sheet (skips headers, balance-repeat rows, '!' markers). */
    private function parseRows(array $sheet): array
    {
        $result    = [];
        $dataStart = -1;

        foreach ($sheet as $i => $row) {
            if (trim((string) ($row[3] ?? '')) === 'URAIAN') {
                $dataStart = $i + 2; // skip the 5.5 | 12 | 50 sub-header row
                break;
            }
        }

        if ($dataStart < 0) {
            return $result;
        }

        for ($i = $dataStart; $i < count($sheet); $i++) {
            $cells  = $sheet[$i];
            $uraian = trim((string) ($cells[3] ?? ''));

            if ($uraian === '' || $uraian === '!') {
                continue;
            }

            $debit  = $this->quantities($cells, 4);
            $kredit = $this->quantities($cells, 7);

            if (count($debit) === 0 && count($kredit) === 0 && trim((string) ($cells[13] ?? '')) === '') {
                continue;
            }

            $result[] = [
                'date'     => $this->excelDate((string) ($cells[1] ?? '')),
                'so'       => trim((string) ($cells[2] ?? '')),
                'uraian'   => $uraian,
                'debit'    => $debit,
                'kredit'   => $kredit,
                'saldo'    => $this->quantities($cells, 10),
                'container'=> trim((string) ($cells[13] ?? '')),
                'notes'    => trim((string) ($cells[14] ?? '')),
            ];
        }

        return $result;
    }

    /** Map the three quantity columns starting at $startOffset into ['5.5kg'=>n,'12kg'=>n,'50kg'=>n]. */
    private function quantities(array $cells, int $startOffset): array
    {
        $result = [];
        $labels = ['5.5kg', '12kg', '50kg'];

        for ($j = 0; $j < 3; $j++) {
            $value = (int) ($cells[$startOffset + $j] ?? 0);
            if ($value > 0) {
                $result[$labels[$j]] = $value;
            }
        }

        return $result;
    }

    /** Excel serial date (e.g. 46129) -> Y-m-d; falls back to today when empty/invalid. */
    private function excelDate(string $serial): string
    {
        if ($serial === '' || ! is_numeric($serial)) {
            return date('Y-m-d');
        }

        return gmdate('Y-m-d', ((int) $serial - 25569) * 86400);
    }

    /** Group KREDIT (terkirim) rows into chains: one per distinct leading SO#, else the first KREDIT row. */
    private function buildChains(array $rows): array
    {
        $groups = [];
        $seq    = 0;

        foreach ($rows as $row) {
            if (count($row['kredit']) === 0) {
                continue;
            }

            $key = $row['so'] !== '' ? $row['so'] : 'row-' . $seq;
            $seq++;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'quantities'=> [],
                    'date'      => $row['date'],
                    'so_number' => $row['so'],
                    'container' => '',
                    'notes'     => '',
                ];
            }

            $group = $groups[$key];

            foreach ($row['kredit'] as $type => $qty) {
                $group['quantities'][$type] = ($group['quantities'][$type] ?? 0) + $qty;
            }

            if ($group['container'] === '' && $row['container'] !== '') $group['container'] = $row['container'];
            if ($group['notes'] === '' && $row['notes'] !== '') $group['notes'] = $row['notes'];
            if ($row['date'] < $group['date']) $group['date'] = $row['date'];
            $groups[$key] = $group;
        }

        return array_values($groups);
    }

    /** Map a ledger transportir mention (KETERANGAN column) to a Transportir row. */
    private function detectTransportir(string $notes, Transportir $willy, Transportir $pakTri): ?Transportir
    {
        $n = strtolower($notes);

        if (str_contains($n, 'tatik')) return $willy;
        if (str_contains($n, 'tri'))   return $pakTri;

        return null;
    }
// =============================================================
    // Stock & Golden Rule
    // =============================================================

    /** Build stock_items (delivered totals from the circulation ledger) + verified Golden-Rule closes for ALL branches. */
    private function seedStock(Branch $branch, User $owner, array $rows): void
    {
        $this->runStockSnapshot();
    }

    /**
     * Rebuild stock_items + stock_closes from the circulation ledger.
     * - every pangkalan gets qty_full = total tabung shipped to it (KREDIT totals)
     * - the main branch (SUM) gets qty_full = grand total shipped (what it received from Pertamina)
     * - every branch gets a verified stock close for today (Golden Rule) so DO/PO flows are demo-able
     */
    public function runStockSnapshot(): void
    {
        $owner = User::where('role', 'owner_pusat')->first() ?? User::find(1);
        $today = date('Y-m-d');

        // Delivered totals per branch + cylinder type (from the KREDIT side of the ledger)
        $totals = [];

        foreach (CylinderCirculation::where('direction', 'kredit')->get() as $circulation) {
            $key = $circulation->branch_id . '|' . $circulation->cylinder_type;
            $totals[$key] = ($totals[$key] ?? 0) + $circulation->quantity;
        }

        // Main branch (SUM) stock = grand total received from Pertamina per type
        foreach (['3kg', '5.5kg', '12kg', '50kg'] as $type) {
            $sum = 0;

            foreach ($totals as $key => $qty) {
                if (str_contains($key, '|' . $type)) {
                    $sum += $qty;
                }
            }

            if ($sum > 0) {
                StockItem::firstOrCreate(['branch_id' => 1, 'cylinder_type' => $type, 'recorded_at' => $today], [
                    'qty_full' => $sum, 'qty_empty' => 0, 'qty_damaged' => 0,
                ]);
            }
        }

        // Replace today's pangkalan seed stock with delivered totals
        $pangkalanIds = [];

        foreach ($this->pangkalan as $meta) {
            $item = Branch::where('name', $meta['name'])->first();
            if ($item) {
                $pangkalanIds[] = $item->id;
            }
        }

        StockItem::whereIn('branch_id', $pangkalanIds)->where('recorded_at', $today)->delete();

        foreach ($totals as $key => $qty) {
            $parts = explode('|', $key);

            StockItem::firstOrCreate(['branch_id' => (int) $parts[0], 'cylinder_type' => $parts[1], 'recorded_at' => $today], [
                'qty_full' => $qty, 'qty_empty' => 0, 'qty_damaged' => 0,
            ]);
        }

        // Golden-Rule closes for EVERY branch, all 4 cylinder types
        foreach (Branch::all() as $branch) {
            foreach (['3kg', '5.5kg', '12kg', '50kg'] as $type) {
                $row = StockItem::where('branch_id', $branch->id)
                    ->where('cylinder_type', $type)
                    ->where('recorded_at', $today)
                    ->first();

                StockClose::firstOrCreate(['branch_id' => $branch->id, 'close_date' => $today, 'cylinder_type' => $type], [
                    'qty_full'    => $row?->qty_full ?? 0,
                    'qty_empty'   => 0,
                    'qty_damaged' => 0,
                    'submitted_by'=> $owner->id,
                    'verified_by' => $owner->id,
                    'status'      => 'verified',
                ]);
            }
        }

        echo '[InitialFlowSeeder] Stock snapshot rebuilt.' . PHP_EOL;
    }

    // =============================================================
    // Helpers
    // =============================================================

    /** Emulate the app's auto-numbering: PREFIX.YEAR-001 (uppercase), increasing per prefix. */
    private function nextNumber(string $prefix): string
    {
        $num = $this->counters[$prefix];
        $this->counters[$prefix] = $num + 1;

        return strtoupper($prefix) . date('Y') . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    }
}