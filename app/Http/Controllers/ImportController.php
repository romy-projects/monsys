<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expedition;
use App\Models\LpgPrice;
use App\Models\SalesTarget;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportController extends Controller
{
    private array $tables = [
        'branches'      => ['code', 'name', 'city', 'status'],
        'users'         => ['name', 'email', 'password', 'role', 'branch_code', 'phone', 'status'],
        'customers'     => ['branch_code', 'name', 'type', 'phone', 'credit_limit'],
        'vehicles'      => ['branch_code', 'plate_number', 'driver_name', 'capacity_kg', 'status'],
        'expeditions'   => ['name', 'code', 'phone', 'address', 'status'],
        'lpg_prices'    => ['cylinder_type', 'purchase_price', 'selling_price', 'effective_date'],
        'stock_items'   => ['branch_code', 'cylinder_type', 'qty_full', 'qty_empty', 'qty_damaged'],
        'sales_targets' => ['branch_code', 'year', 'month', 'cylinder_type', 'target_qty', 'target_revenue'],
    ];

    private array $enums = [
        'role'            => ['owner_pusat', 'regional_leader', 'owner_cabang', 'staff_gudang'],
        'status'          => ['active', 'inactive'],
        'type'            => ['retail', 'wholesale', 'agent'],
        'cylinder_type'   => ['3kg', '5.5kg', '12kg', '50kg'],
    ];

    // ── Download Template ─────────────────────────────────────

    public function downloadTemplate(string $table, Request $request)
    {
        if (! $request->user()->isOwnerPusat() && ! $request->user()->isRegionalLeader()) {
            abort(403);
        }

        if (! isset($this->tables[$table])) {
            abort(404, 'Table not found.');
        }

        $columns = $this->tables[$table];

        $xlsx = new XlsxWriter();
        $xlsx->addRow($columns);
        $xlsx->addRow(array_map(fn () => '', $columns)); // empty data row as example

        return $xlsx->download('template-' . $table . '-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ── Import Data ───────────────────────────────────────────

    public function import(string $table, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! isset($this->tables[$table])) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        ]);

        $path  = $request->file('file')->getRealPath();
        $columns = $this->tables[$table];

        try {
            $reader = new XlsxReader($path);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $header = $reader->header();
        $data   = $reader->data();

        if (empty($data)) {
            return response()->json(['message' => 'File is empty (no data rows).'], 422);
        }

        // Validate header matches expected columns
        $expectedHeader = $columns;
        $normalizedHeader = array_map(fn ($h) => trim(strtolower((string) $h)), $header);
        $normalizedExpected = array_map(fn ($h) => trim(strtolower($h)), $expectedHeader);

        if ($normalizedHeader !== $normalizedExpected) {
            return response()->json([
                'message' => 'Column mismatch. Expected: ' . implode(', ', $expectedHeader)
                    . '. Got: ' . implode(', ', $header),
            ], 422);
        }

        $errors   = [];
        $imported = 0;

        DB::beginTransaction();

        try {
            foreach ($data as $rowIdx => $row) {
                $rowNum  = $rowIdx + 2; // 1-indexed + header row
                $rowData = array_combine($columns, array_slice($row, 0, count($columns)));

                // Skip completely empty rows
                if (empty(array_filter($rowData, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                $rowErrors = $this->validateRow($table, $rowData, $rowNum);
                if (! empty($rowErrors)) {
                    $errors = array_merge($errors, $rowErrors);
                    continue;
                }

                // Sanitize all string values
                $rowData = $this->deepSanitize($rowData);

                // Import the row
                $this->insertRow($table, $rowData);
                $imported++;
            }

            if (! empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'message'  => 'Validation failed. No rows were imported.',
                    'imported' => 0,
                    'errors'   => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'message'  => "Successfully imported {$imported} row(s).",
                'imported' => $imported,
                'errors'   => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message'  => 'Import failed: ' . $e->getMessage(),
                'imported' => 0,
                'errors'   => [['row' => null, 'message' => $e->getMessage()]],
            ], 500);
        }
    }

    // ── Row Validation ────────────────────────────────────────

    private function validateRow(string $table, array $row, int $rowNum): array
    {
        $errors = [];

        switch ($table) {
            case 'branches':
                if (empty($row['code'])) $errors[] = ['row' => $rowNum, 'field' => 'code', 'message' => 'Branch code is required.'];
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Branch name is required.'];
                if (! empty($row['status']) && ! in_array($row['status'], $this->enums['status'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'status', 'message' => 'Status must be: ' . implode(', ', $this->enums['status'])];
                }
                if (! empty($row['code']) && Branch::where('code', $row['code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'code', 'message' => "Branch code '{$row['code']}' already exists."];
                }
                break;

            case 'users':
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Name is required.'];
                if (empty($row['email'])) $errors[] = ['row' => $rowNum, 'field' => 'email', 'message' => 'Email is required.'];
                if (empty($row['password'])) $errors[] = ['row' => $rowNum, 'field' => 'password', 'message' => 'Password is required.'];
                if (empty($row['role'])) $errors[] = ['row' => $rowNum, 'field' => 'role', 'message' => 'Role is required.'];
                if (! empty($row['role']) && ! in_array($row['role'], $this->enums['role'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'role', 'message' => 'Role must be: ' . implode(', ', $this->enums['role'])];
                }
                if (! empty($row['email']) && User::where('email', $row['email'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'email', 'message' => "Email '{$row['email']}' already exists."];
                }
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch code '{$row['branch_code']}' not found."];
                }
                if (! empty($row['status']) && ! in_array($row['status'], $this->enums['status'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'status', 'message' => 'Status must be: ' . implode(', ', $this->enums['status'])];
                }
                break;

            case 'customers':
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Customer name is required.'];
                if (! empty($row['type']) && ! in_array($row['type'], $this->enums['type'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'type', 'message' => 'Type must be: ' . implode(', ', $this->enums['type'])];
                }
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch code '{$row['branch_code']}' not found."];
                }
                break;

            case 'vehicles':
                if (empty($row['plate_number'])) $errors[] = ['row' => $rowNum, 'field' => 'plate_number', 'message' => 'Plate number is required.'];
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch code '{$row['branch_code']}' not found."];
                }
                if (! empty($row['status']) && ! in_array($row['status'], $this->enums['status'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'status', 'message' => 'Status must be: ' . implode(', ', $this->enums['status'])];
                }
                break;

            case 'expeditions':
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Expedition name is required.'];
                if (! empty($row['status']) && ! in_array($row['status'], $this->enums['status'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'status', 'message' => 'Status must be: ' . implode(', ', $this->enums['status'])];
                }
                break;

            case 'lpg_prices':
                if (empty($row['cylinder_type'])) $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type is required.'];
                if (! empty($row['cylinder_type']) && ! in_array($row['cylinder_type'], $this->enums['cylinder_type'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type must be: ' . implode(', ', $this->enums['cylinder_type'])];
                }
                if (empty($row['purchase_price']) && $row['purchase_price'] !== 0) $errors[] = ['row' => $rowNum, 'field' => 'purchase_price', 'message' => 'Purchase price is required.'];
                if (empty($row['effective_date'])) $errors[] = ['row' => $rowNum, 'field' => 'effective_date', 'message' => 'Effective date is required.'];
                break;

            case 'stock_items':
                if (empty($row['branch_code'])) $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => 'Branch code is required.'];
                if (empty($row['cylinder_type'])) $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type is required.'];
                if (! empty($row['cylinder_type']) && ! in_array($row['cylinder_type'], $this->enums['cylinder_type'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type must be: ' . implode(', ', $this->enums['cylinder_type'])];
                }
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch code '{$row['branch_code']}' not found."];
                }
                break;

            case 'sales_targets':
                if (empty($row['branch_code'])) $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => 'Branch code is required.'];
                if (empty($row['year'])) $errors[] = ['row' => $rowNum, 'field' => 'year', 'message' => 'Year is required.'];
                if (empty($row['month'])) $errors[] = ['row' => $rowNum, 'field' => 'month', 'message' => 'Month is required.'];
                if (empty($row['cylinder_type'])) $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type is required.'];
                if (! empty($row['cylinder_type']) && ! in_array($row['cylinder_type'], $this->enums['cylinder_type'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'cylinder_type', 'message' => 'Cylinder type must be: ' . implode(', ', $this->enums['cylinder_type'])];
                }
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch code '{$row['branch_code']}' not found."];
                }
                // Validate month range
                if (! empty($row['month']) && ($row['month'] < 1 || $row['month'] > 12)) {
                    $errors[] = ['row' => $rowNum, 'field' => 'month', 'message' => 'Month must be between 1 and 12.'];
                }
                break;
        }

        return $errors;
    }

    // ── Insert Row ────────────────────────────────────────────

    private function insertRow(string $table, array $data): void
    {
        switch ($table) {
            case 'branches':
                Branch::create([
                    'code'   => $data['code'],
                    'name'   => $data['name'],
                    'city'   => $data['city'] ?? null,
                    'status' => $data['status'] ?? 'active',
                ]);
                break;

            case 'users':
                $branchId = null;
                if (! empty($data['branch_code'])) {
                    $branchId = Branch::where('code', $data['branch_code'])->value('id');
                }
                User::create([
                    'name'      => $data['name'],
                    'email'     => $data['email'],
                    'password'  => Hash::make($data['password']),
                    'role'      => $data['role'],
                    'branch_id' => $branchId,
                    'phone'     => $data['phone'] ?? null,
                    'status'    => $data['status'] ?? 'active',
                ]);
                break;

            case 'customers':
                $branchId = null;
                if (! empty($data['branch_code'])) {
                    $branchId = Branch::where('code', $data['branch_code'])->value('id');
                }
                Customer::create([
                    'branch_id'    => $branchId,
                    'name'         => $data['name'],
                    'type'         => $data['type'] ?? 'retail',
                    'phone'        => $data['phone'] ?? null,
                    'credit_limit' => $data['credit_limit'] ?? 0,
                ]);
                break;

            case 'vehicles':
                $branchId = null;
                if (! empty($data['branch_code'])) {
                    $branchId = Branch::where('code', $data['branch_code'])->value('id');
                }
                Vehicle::create([
                    'branch_id'    => $branchId,
                    'plate_number' => $data['plate_number'],
                    'driver_name'  => $data['driver_name'] ?? null,
                    'capacity_kg'  => $data['capacity_kg'] ?? null,
                    'status'       => $data['status'] ?? 'active',
                ]);
                break;

            case 'expeditions':
                Expedition::create([
                    'name'    => $data['name'],
                    'code'    => $data['code'] ?? null,
                    'phone'   => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'status'  => $data['status'] ?? 'active',
                ]);
                break;

            case 'lpg_prices':
                LpgPrice::create([
                    'cylinder_type'   => $data['cylinder_type'],
                    'purchase_price'  => (float) ($data['purchase_price'] ?? 0),
                    'selling_price'   => (float) ($data['selling_price'] ?? 0),
                    'effective_date'  => $data['effective_date'],
                ]);
                break;

            case 'stock_items':
                $branchId = null;
                if (! empty($data['branch_code'])) {
                    $branchId = Branch::where('code', $data['branch_code'])->value('id');
                }
                StockItem::updateOrCreate(
                    ['branch_id' => $branchId, 'cylinder_type' => $data['cylinder_type']],
                    [
                        'qty_full'    => (int) ($data['qty_full'] ?? 0),
                        'qty_empty'   => (int) ($data['qty_empty'] ?? 0),
                        'qty_damaged' => (int) ($data['qty_damaged'] ?? 0),
                    ]
                );
                break;

            case 'sales_targets':
                $branchId = null;
                if (! empty($data['branch_code'])) {
                    $branchId = Branch::where('code', $data['branch_code'])->value('id');
                }
                SalesTarget::create([
                    'branch_id'     => $branchId,
                    'year'          => (int) ($data['year'] ?? now()->year),
                    'month'         => (int) ($data['month'] ?? now()->month),
                    'cylinder_type' => $data['cylinder_type'],
                    'target_qty'    => (int) ($data['target_qty'] ?? 0),
                    'target_revenue'=> (float) ($data['target_revenue'] ?? 0),
                ]);
                break;
        }
    }

    /**
     * Deep sanitize all string values in array: strip HTML, prevent formula injection.
     */
    private function deepSanitize(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_string($value)) {
                $value = strip_tags($value);
                $value = ltrim($value, '=+-@');
                $value = trim($value);
            }
        }

        return $data;
    }
}