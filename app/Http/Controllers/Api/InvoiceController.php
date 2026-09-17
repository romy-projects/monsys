<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Invoice;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = Invoice::with(['branch', 'customer']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->branch_id && ($user->isOwnerPusat() || $user->isRegionalLeader())) {
            $query->where('branch_id', $request->branch_id);
        }

        $perPage = min((int) ($request->per_page ?? 15), 100);

        return $this->paginated($query->paginate($perPage), fn(Invoice $inv) => [
            'id'             => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'branch'         => $inv->branch?->name,
            'customer'       => $inv->customer?->name,
            'cylinder_type'  => $inv->cylinder_type,
            'quantity'       => $inv->quantity,
            'total_amount'   => (float) $inv->total_amount,
            'paid_amount'    => (float) $inv->paid_amount,
            'balance'        => $inv->balance,
            'issue_date'     => $inv->issue_date->toDateString(),
            'due_date'       => $inv->due_date->toDateString(),
            'status'         => $inv->status,
            'notes'          => $inv->notes,
        ]);
    }

    /** Create a draft invoice. Number is allocated server-side. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Non-approvers may only create invoices for their own branch.
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()
            && (int) $request->input('branch_id') !== $user->branch_id) {
            return $this->forbidden('You may only create invoices for your own branch.');
        }

        $data = Validator::make($request->all(), [
            'branch_id'     => 'required|exists:branches,id',
            'customer_id'   => 'nullable|exists:customers,id',
            'cylinder_type' => 'required|in:3kg,5.5kg,12kg,50kg',
            'quantity'      => 'required|integer|min:1',
            'unit_price'    => 'required|numeric|min:0',
            'issue_date'    => 'required|date',
            'due_date'      => 'required|date|after_or_equal:issue_date',
            'notes'         => 'nullable|string',
        ])->validated();

        $data['total_amount'] = (int) $data['quantity'] * (float) $data['unit_price'];
        $data['created_by']   = $user->id;
        $data['status']       = 'draft';

        // Allocate INV<year>-NNN atomically instead of the racy count()+1 — invoice_number
        // is uniquely indexed, so concurrent creates would collide.
        $invoice = DB::transaction(function () use ($data) {
            $data['invoice_number'] = $this->documentNumbers->next('inv');

            return Invoice::create($data);
        });

        return $this->created($invoice->fresh(['branch', 'customer']));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $user = auth()->user();
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader() && $invoice->branch_id !== $user->branch_id) {
            return $this->forbidden();
        }

        return $this->success($invoice->load(['branch', 'customer']));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $user = auth()->user();
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader() && $invoice->branch_id !== $user->branch_id) {
            return $this->forbidden();
        }

        if (! in_array($invoice->status, ['draft', 'issued', 'partial', 'overdue'])) {
            return $this->error('Cannot modify invoice with status: ' . $invoice->status, 422);
        }

        $data = Validator::make($request->all(), [
            'cylinder_type' => 'sometimes|in:3kg,5.5kg,12kg,50kg',
            'quantity'      => 'sometimes|integer|min:1',
            'unit_price'    => 'sometimes|numeric|min:0',
            'issue_date'    => 'sometimes|date',
            'due_date'      => 'sometimes|date',
            'status'        => 'sometimes|in:draft,issued,cancelled',
            'notes'         => 'nullable|string',
        ])->validated();

        if (isset($data['quantity']) || isset($data['unit_price'])) {
            $qty  = $data['quantity'] ?? $invoice->quantity;
            $unit = $data['unit_price'] ?? $invoice->unit_price;
            $data['total_amount'] = (int) $qty * (float) $unit;
        }

        $invoice->update($data);

        return $this->success($invoice->fresh(['branch', 'customer']));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        if (! auth()->user()->isOwnerPusat()) {
            return $this->forbidden();
        }

        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be deleted.', 422);
        }

        $invoice->delete();

        return $this->noContent();
    }

    /** Record a payment against an invoice. */
    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $user = auth()->user();
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader() && $invoice->branch_id !== $user->branch_id) {
            return $this->forbidden();
        }

        if ($invoice->status === 'paid' || $invoice->status === 'cancelled') {
            return $this->error('Cannot pay a ' . $invoice->status . ' invoice.', 422);
        }

        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
        ])->validated();

        $invoice->paid_amount += (float) $data['amount'];
        $invoice->save();
        $invoice->recalculateStatus();

        return $this->success($invoice->fresh(['branch', 'customer']), 'Payment recorded.');
    }

    /** Issue a draft invoice. */
    public function issue(Invoice $invoice): JsonResponse
    {
        $user = auth()->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader() && $invoice->branch_id !== $user->branch_id) {
            return $this->forbidden();
        }

        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be issued.', 422);
        }

        $invoice->update(['status' => 'issued']);

        return $this->success($invoice->fresh(['branch', 'customer']), 'Invoice issued.');
    }
}
