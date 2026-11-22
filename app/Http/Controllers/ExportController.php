<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\LpgPrice;
use App\Models\OperationalCost;
use App\Models\Receivable;
use App\Support\JournalMapping;
use App\Support\SalesDetailReport;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function sales(Request $request)
    {
        $user = auth()->user();

        if (! $user?->hasPermission('sales.view')) {
            abort($user ? 403 : 401);
        }

        $records = DailySale::query()
            ->with('branch')
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->start_date, fn ($q) => $q->whereDate('sale_date', '>=', $request->start_date))
            ->when($request->end_date, fn ($q) => $q->whereDate('sale_date', '<=', $request->end_date))
            ->orderBy('sale_date', 'desc')
            ->get();

        $xlsx = new XlsxWriter;
        $xlsx->addRow(['Date', 'Branch', 'Cylinder Type', 'Buyer Type', 'Quantity', 'Price/Unit (Rp)', 'Total Revenue (Rp)']);

        foreach ($records as $row) {
            $xlsx->addRow([
                $row->sale_date->format('Y-m-d'),
                $row->branch?->name ?? '—',
                $row->cylinder_type,
                ucfirst($row->buyer_type),
                (int) $row->quantity,
                (float) $row->selling_price,
                (float) $row->total_revenue,
            ]);
        }

        $xlsx->addRow([]);
        $xlsx->addRow(['', '', '', '', 'TOTAL', '', (float) $records->sum('total_revenue')]);

        return $xlsx->download('daily-sales-'.now()->format('Y-m-d').'.xlsx');
    }

    public function profitLoss(Request $request)
    {
        $user = auth()->user();

        if (! $user?->hasPermission('reports.profit_loss.view')) {
            abort(403, 'Access denied.');
        }

        $branchId = $request->branch_id;
        if (! $branchId && ! $user->canAccessAllBranches()) {
            $branchIds = $user->accessibleBranchIds();
            $branchId = $user->branch_id && $user->canAccessBranch((int) $user->branch_id)
                ? $user->branch_id
                : (count($branchIds) === 1 ? $branchIds[0] : null);
        }
        $startDate = $request->start_date ?: now()->startOfMonth()->toDateString();
        $endDate = $request->end_date ?: now()->toDateString();

        $sales = DailySale::query()
            ->with('branch')
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('sale_date')
            ->get();

        $costs = OperationalCost::query()
            ->with('branch')
            ->whereBetween('cost_date', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('cost_date')
            ->get();

        $totalHpp = LpgPrice::totalHppForSales($sales, $branchId);
        $branchQuery = Branch::query();
        $user->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchLabel = $branchId
            ? ($branchQuery->whereKey($branchId)->value('name') ?? 'Unknown')
            : 'All Accessible Branches';
        $totalRevenue = (float) $sales->sum('total_revenue');
        $totalCosts = (float) $costs->sum('amount');
        $totalExpenses = $totalHpp + $totalCosts;
        $netProfit = $totalRevenue - $totalExpenses;
        $margin = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 1) : 0;

        $categoryLabels = [
            'fuel' => 'Fuel / BBM',
            'salary' => 'Salary / Gaji',
            'logistics' => 'Logistics / Ongkir',
            'levy' => 'Levy / Retribusi',
            'other' => 'Other',
        ];

        $xlsx = new XlsxWriter;

        // Header block (bold rows 1–4)
        $xlsx->boldRows(4);
        $xlsx->addRow(['PROFIT & LOSS REPORT — SUM Energy Network']);
        $xlsx->addRow(['Branch:', $branchLabel]);
        $xlsx->addRow(['Period:', $startDate.' to '.$endDate]);
        $xlsx->addRow(['Generated:', now()->format('Y-m-d H:i')]);
        $xlsx->addRow([]);

        // HPP section
        $xlsx->addRow(['--- HPP / COST OF GOODS SOLD ---']);
        $xlsx->addRow(['Total HPP:', $totalHpp]);
        $xlsx->addRow([]);

        // Revenue section
        $xlsx->addRow(['--- REVENUE / PENDAPATAN ---']);
        $xlsx->addRow(['Date', 'Branch', 'Cylinder Type', 'Buyer Type', 'Qty', 'Price/Unit', 'Total Revenue']);

        foreach ($sales as $row) {
            $xlsx->addRow([
                $row->sale_date->format('Y-m-d'),
                $row->branch?->name,
                $row->cylinder_type,
                ucfirst($row->buyer_type),
                (int) $row->quantity,
                (float) $row->selling_price,
                (float) $row->total_revenue,
            ]);
        }

        $xlsx->addRow(['', '', '', '', '', 'TOTAL REVENUE:', $totalRevenue]);
        $xlsx->addRow([]);

        // Cost section
        $xlsx->addRow(['--- COSTS / BIAYA OPERASIONAL ---']);
        $xlsx->addRow(['Date', 'Branch', 'Category', 'Description', 'Amount']);

        foreach ($costs as $row) {
            $xlsx->addRow([
                $row->cost_date->format('Y-m-d'),
                $row->branch?->name,
                $categoryLabels[$row->cost_category] ?? $row->cost_category,
                $row->description,
                (float) $row->amount,
            ]);
        }

        $xlsx->addRow(['', '', '', 'TOTAL COSTS:', $totalCosts]);
        $xlsx->addRow([]);

        // Summary
        $xlsx->addRow(['--- SUMMARY ---']);
        $xlsx->addRow(['Total Revenue (Rp):', $totalRevenue]);
        $xlsx->addRow(['Total HPP (COGS) (Rp):', $totalHpp]);
        $xlsx->addRow(['Total Costs (Rp):', $totalCosts]);
        $xlsx->addRow(['Total Expenses (Rp):', $totalExpenses]);
        $xlsx->addRow(['Net Profit (Rp):', $netProfit]);
        $xlsx->addRow(['Margin:', $margin.'%']);

        return $xlsx->download('profit-loss-'.now()->format('Y-m-d').'.xlsx');
    }

    public function receivables(Request $request)
    {
        $user = auth()->user();

        if (! $user?->hasPermission('receivables.view')) {
            abort(403, 'Access denied.');
        }

        $query = Receivable::query()
            ->with('branch')
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderBy('due_date');

        $records = $query->get();

        $xlsx = new XlsxWriter;
        $xlsx->addRow(['Branch', 'Buyer Name', 'Type', 'Invoice #', 'Invoice Date', 'Due Date', 'Amount (Rp)', 'Paid (Rp)', 'Balance (Rp)', 'Status', 'Days Overdue']);

        $today = today();

        foreach ($records as $row) {
            $balance = max(0, (float) $row->amount - (float) $row->paid_amount);
            $daysOverdue = $row->due_date->lt($today) && $row->status !== 'paid'
                ? $today->diffInDays($row->due_date)
                : 0;

            $xlsx->addRow([
                $row->branch?->name ?? '—',
                $row->buyer_name,
                ucfirst($row->buyer_type),
                $row->invoice_number ?? '—',
                $row->invoice_date->format('Y-m-d'),
                $row->due_date->format('Y-m-d'),
                (float) $row->amount,
                (float) $row->paid_amount,
                $balance,
                ucfirst($row->status),
                $daysOverdue > 0 ? $daysOverdue : '',
            ]);
        }

        $xlsx->addRow([]);
        $xlsx->addRow(['', '', '', '', '', 'TOTAL BALANCE:', '', '', (float) $records->sum(fn ($r) => max(0, $r->amount - $r->paid_amount))]);

        return $xlsx->download('receivables-'.now()->format('Y-m-d').'.xlsx');
    }

    /** Phase 19A / T19A-07: Jurnal Umum export from the operational tables. */
    public function journal(Request $request)
    {
        $user = auth()->user();

        if (! $user?->hasPermission('reports.journal.view')) {
            abort(403, 'Access denied.');
        }

        $branchId = $this->resolveBranchId($request);
        $startDate = $request->start_date ?: now()->startOfMonth()->toDateString();
        $endDate = $request->end_date ?: now()->toDateString();

        $rows = JournalMapping::build($branchId, $startDate, $endDate);
        $totals = JournalMapping::totals($rows);

        $xlsx = new XlsxWriter;
        $xlsx->addRow(['Tanggal', 'No. Bukti', 'Keterangan', 'Kode Akun', 'Nama Akun', 'Cabang', 'Sumber', 'Debit (Rp)', 'Kredit (Rp)']);

        foreach ($rows as $row) {
            $xlsx->addRow([
                $row['date'],
                $row['ref'],
                $row['description'],
                $row['account_code'],
                $row['account_name'],
                $row['branch'],
                $row['source'],
                $row['debit'] ?: null,
                $row['credit'] ?: null,
            ]);
        }

        $xlsx->addRow([]);
        $xlsx->addRow(['', '', 'TOTAL', '', '', '', '', $totals['debit'], $totals['credit']]);
        $xlsx->addRow(['', '', $totals['balanced'] ? 'BALANCE' : 'SELISIH', '', '', '', '', '', $totals['difference']]);

        return $xlsx->download('jurnal-umum-'.$startDate.'_'.$endDate.'.xlsx');
    }

    /** Phase 19A / T19A-08: SALES REPORT parity (payment date + method included). */
    public function salesDetail(Request $request)
    {
        $user = auth()->user();

        if (! $user?->hasPermission('reports.sales_detail.view')) {
            abort(403, 'Access denied.');
        }

        $branchId = $this->resolveBranchId($request);
        $startDate = $request->start_date ?: now()->startOfMonth()->toDateString();
        $endDate = $request->end_date ?: now()->toDateString();

        $report = SalesDetailReport::build($branchId, $startDate, $endDate);

        $xlsx = new XlsxWriter;
        $xlsx->addRow([
            'Tgl Faktur', 'No Faktur', 'Pangkalan', 'Cabang', 'Tipe', 'Qty', 'Harga', 'Total Barang',
            'Ongkos Expedisi', 'Total Tagihan', 'Tgl Bayar', 'Metode', 'No. Referensi',
            'Dibayar', 'Sisa', 'Status', 'No. Pertamina',
        ]);

        foreach ($report['rows'] as $row) {
            $xlsx->addRow([
                $row['issue_date'],
                $row['invoice_number'],
                $row['buyer'],
                $row['branch'],
                $row['cylinder_type'],
                $row['quantity'],
                $row['unit_price'],
                $row['product_total'],
                $row['expedition_fee'],
                $row['total'],
                $row['paid_on'],
                $row['method'],
                $row['reference_no'],
                $row['paid'],
                $row['balance'],
                $row['status'],
                $row['pertamina_reference'],
            ]);
        }

        $xlsx->addRow([]);
        $xlsx->addRow([
            '', '', '', '', 'TOTAL', $report['totals']['quantity'], '', $report['totals']['product'],
            $report['totals']['expedition_fee'], $report['totals']['total'], '', '', '',
            $report['totals']['paid'], $report['totals']['balance'], '', '',
        ]);

        return $xlsx->download('sales-detail-'.$startDate.'_'.$endDate.'.xlsx');
    }

    /** Branch filter resolution shared by the report exports. */
    private function resolveBranchId(Request $request): ?int
    {
        $user = auth()->user();
        $branchId = $request->branch_id;

        if (! $branchId && ! $user->canAccessAllBranches()) {
            $branchIds = $user->accessibleBranchIds();
            $branchId = $user->branch_id && $user->canAccessBranch((int) $user->branch_id)
                ? $user->branch_id
                : (count($branchIds) === 1 ? $branchIds[0] : null);
        }

        return $branchId ? (int) $branchId : null;
    }
}
