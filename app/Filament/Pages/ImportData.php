<?php

namespace App\Filament\Pages;

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
use Filament\Pages\Page;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ZipArchive;

class ImportData extends Page implements HasForms
{
    use \Filament\Forms\Concerns\InteractsWithForms;

    protected static string $view = 'filament.pages.import-data';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Import Data';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Import Data from Excel';

    public ?string $table = null;
    public ?UploadedFile $file = null;

    public array $result = [];
    public bool $showResult = false;
    public bool $showStatus = false;

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

    public function getTableStatus(): array
    {
        $status = [];
        foreach ($this->tables as $table => $columns) {
            $count = match ($table) {
                'branches'      => \App\Models\Branch::count(),
                'users'         => \App\Models\User::count(),
                'customers'     => \App\Models\Customer::count(),
                'vehicles'      => \App\Models\Vehicle::count(),
                'expeditions'   => \App\Models\Expedition::count(),
                'lpg_prices'    => \App\Models\LpgPrice::count(),
                'stock_items'   => \App\Models\StockItem::count(),
                'sales_targets' => \App\Models\SalesTarget::count(),
                default         => 0,
            };
            $status[] = [
                'table'     => $table,
                'label'     => $this->tableLabel($table),
                'count'     => $count,
                'imported'  => $count > 0,
                'columns'   => $columns,
            ];
        }
        return $status;
    }

    private function tableLabel(string $table): string
    {
        return match ($table) {
            'branches'      => 'Branches / Cabang',
            'users'         => 'Users / Pengguna',
            'customers'     => 'Customers / Pelanggan',
            'vehicles'      => 'Vehicles / Kendaraan',
            'expeditions'   => 'Expeditions / Ekspedisi',
            'lpg_prices'    => 'LPG Prices / Harga LPG',
            'stock_items'   => 'Stock Items / Stok',
            'sales_targets' => 'Sales Targets / Target Penjualan',
            default         => $table,
        };
    }

    public function getForm(?string $name = null): ?\Filament\Forms\Form
    {
        return $this->form(
            \Filament\Forms\Form::make($this)
                ->schema([
                    Select::make('table')
                        ->label('Table / Data Type')
                        ->options([
                            'branches'      => 'Branches / Cabang',
                            'users'         => 'Users / Pengguna',
                            'customers'     => 'Customers / Pelanggan',
                            'vehicles'      => 'Vehicles / Kendaraan',
                            'expeditions'   => 'Expeditions / Ekspedisi',
                            'lpg_prices'    => 'LPG Prices / Harga LPG',
                            'stock_items'   => 'Stock Items / Stok',
                            'sales_targets' => 'Sales Targets / Target Penjualan',
                        ])
                        ->searchable()
                        ->required(),
                    FileUpload::make('file')
                        ->label('Excel File (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(2048)
                        ->required(),
                ])
        );
    }

    public function downloadTemplate(): void
    {
        $table = $this->table;

        if (! $table || ! isset($this->tables[$table])) {
            Notification::make()->title('Please select a table first.')->warning()->send();
            return;
        }

        $columns = $this->tables[$table];
        $xlsx = new XlsxWriter();
        $xlsx->addRow($columns);
        $xlsx->addRow(array_map(fn() => '', $columns));

        $filename = 'template-' . $table . '-' . now()->format('Y-m-d') . '.xlsx';
        $path = storage_path('app/temp/' . $filename);
        $xlsx->save($path);

        $this->redirect(route('import-data.download', ['file' => $filename]));
    }

    public function downloadAllTemplates(): void
    {
        $tmpDir = storage_path('app/temp/templates_' . now()->timestamp);
        mkdir($tmpDir, 0777, true);

        foreach ($this->tables as $table => $columns) {
            $xlsx = new XlsxWriter();
            $xlsx->addRow($columns);
            $xlsx->addRow(array_map(fn() => '', $columns));

            $xlsx->save($tmpDir . '/template-' . $table . '.xlsx');
        }

        // Create ZIP
        $zipFilename = 'all-templates-' . now()->format('Y-m-d') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipFilename);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = glob($tmpDir . '/*.xlsx');
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        // Cleanup temp files
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);

        $this->redirect(route('import-data.download', ['file' => $zipFilename]));
    }

    public function import(): void
    {
        $this->validate();

        $table   = $this->table;
        $columns = $this->tables[$table];
        $path    = $this->file->getRealPath();

        try {
            $reader = new XlsxReader($path);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            return;
        }

        $header = $reader->header();
        $data   = $reader->data();

        if (empty($data)) {
            Notification::make()->title('File is empty (no data rows).')->warning()->send();
            return;
        }

        // Validate header
        $normalizedHeader   = array_map(fn($h) => trim(strtolower((string) $h)), $header);
        $normalizedExpected = array_map(fn($h) => trim(strtolower($h)), $columns);

        if ($normalizedHeader !== $normalizedExpected) {
            Notification::make()
                ->title('Column mismatch. Expected: ' . implode(', ', $columns))
                ->danger()
                ->send();
            return;
        }

        $errors   = [];
        $imported = 0;

        DB::beginTransaction();

        try {
            foreach ($data as $rowIdx => $row) {
                $rowNum  = $rowIdx + 2;
                $rowData = array_combine($columns, array_slice($row, 0, count($columns)));

                if (empty(array_filter($rowData, fn($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                $rowData = $this->deepSanitize($rowData);

                $rowErrors = $this->validateRow($table, $rowData, $rowNum);
                if (! empty($rowErrors)) {
                    $errors = array_merge($errors, $rowErrors);
                    continue;
                }

                $this->insertRow($table, $rowData);
                $imported++;
            }

            if (! empty($errors)) {
                DB::rollBack();
                $this->result = [
                    'success'  => false,
                    'message'  => 'Validation failed. No rows were imported.',
                    'imported' => 0,
                    'errors'   => $errors,
                ];
                $this->showResult = true;

                Notification::make()
                    ->title("Validation failed — {$imported} rows skipped")
                    ->danger()
                    ->body(collect($errors)->take(5)->map(fn($e) => "Row {$e['row']}: {$e['message']}")->implode("\n"))
                    ->send();
                return;
            }

            DB::commit();

            $this->result = [
                'success'  => true,
                'message'  => "Successfully imported {$imported} row(s).",
                'imported' => $imported,
                'errors'   => [],
            ];
            $this->showResult = true;
            $this->showStatus = true;

            Notification::make()
                ->title("Imported {$imported} row(s) successfully!")
                ->success()
                ->send();

            $this->resetForm();
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()->title('Import failed: ' . $e->getMessage())->danger()->send();
        }
    }

    private function validateRow(string $table, array $row, int $rowNum): array
    {
        $errors = [];

        switch ($table) {
            case 'branches':
                if (empty($row['code'])) $errors[] = ['row' => $rowNum, 'field' => 'code', 'message' => 'Branch code is required.'];
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Branch name is required.'];
                if (! empty($row['status']) && ! in_array($row['status'], $this->enums['status'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'status', 'message' => 'Status must be: active, inactive'];
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
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch '{$row['branch_code']}' not found."];
                }
                break;

            case 'customers':
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Customer name is required.'];
                if (! empty($row['type']) && ! in_array($row['type'], $this->enums['type'])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'type', 'message' => 'Type must be: ' . implode(', ', $this->enums['type'])];
                }
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch '{$row['branch_code']}' not found."];
                }
                break;

            case 'vehicles':
                if (empty($row['plate_number'])) $errors[] = ['row' => $rowNum, 'field' => 'plate_number', 'message' => 'Plate number is required.'];
                if (! empty($row['branch_code']) && ! Branch::where('code', $row['branch_code'])->exists()) {
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch '{$row['branch_code']}' not found."];
                }
                break;

            case 'expeditions':
                if (empty($row['name'])) $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'Expedition name is required.'];
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
                    $errors[] = ['row' => $rowNum, 'field' => 'branch_code', 'message' => "Branch '{$row['branch_code']}' not found."];
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
                if (! empty($row['month']) && ($row['month'] < 1 || $row['month'] > 12)) {
                    $errors[] = ['row' => $rowNum, 'field' => 'month', 'message' => 'Month must be 1–12.'];
                }
                break;
        }

        return $errors;
    }

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
                $branchId = Branch::where('code', $data['branch_code'])->value('id');
                Customer::create([
                    'branch_id'    => $branchId,
                    'name'         => $data['name'],
                    'type'         => $data['type'] ?? 'retail',
                    'phone'        => $data['phone'] ?? null,
                    'credit_limit' => $data['credit_limit'] ?? 0,
                ]);
                break;

            case 'vehicles':
                $branchId = Branch::where('code', $data['branch_code'])->value('id');
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
                    'cylinder_type'  => $data['cylinder_type'],
                    'purchase_price' => (float) ($data['purchase_price'] ?? 0),
                    'selling_price'  => (float) ($data['selling_price'] ?? 0),
                    'effective_date' => $data['effective_date'],
                ]);
                break;

            case 'stock_items':
                $branchId = Branch::where('code', $data['branch_code'])->value('id');
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
                $branchId = Branch::where('code', $data['branch_code'])->value('id');
                SalesTarget::create([
                    'branch_id'      => $branchId,
                    'year'           => (int) $data['year'],
                    'month'          => (int) $data['month'],
                    'cylinder_type'  => $data['cylinder_type'],
                    'target_qty'     => (int) ($data['target_qty'] ?? 0),
                    'target_revenue' => (float) ($data['target_revenue'] ?? 0),
                ]);
                break;
        }
    }

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

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && ($user->isOwnerPusat() || $user->isRegionalLeader());
    }
}
