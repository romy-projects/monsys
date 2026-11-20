<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\TransportirRate;
use App\Services\DocumentNumberService;
use App\Support\InvoicePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = Invoice::with(['branch', 'customer', 'lpgPrice']);

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
            'unit_price'     => (float) $inv->unit_price,
            'lpg_price_id'   => $inv->lpg_price_id,
            'lpg_price'      => $inv->lpgPrice ? [
                'id' => $inv->lpgPrice->id,
                'branch_id' => $inv->lpgPrice->branch_id,
                'effective_date' => $inv->lpgPrice->effective_date?->toDateString(),
            ] : null,
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

        $validator = Validator::make($request->all(), [
            'branch_id'     => 'required|exists:branches,id',
            'customer_id'   => 'nullable|exists:customers,id',
            'reference_type' => 'sometimes|in:branch,customer',
            'reference_id'   => 'nullable|integer',
            'cylinder_type' => 'required|in:3kg,5.5kg,12kg,50kg',
            'quantity'      => 'required|integer|min:1',
            'unit_price'    => 'sometimes|numeric|min:0',
            'issue_date'    => 'required|date',
            'due_date'      => 'required|date|after_or_equal:issue_date',
            'loading_order_number' => 'nullable|string|max:100',
            'notes'         => 'nullable|string',
        ]);
        $validator->after(function ($validator) use ($request): void {
            $type = $request->input('reference_type');
            $referenceId = $request->input('reference_id');

            if ($type === 'branch' && (! $referenceId || ! \App\Models\Branch::whereKey($referenceId)->exists())) {
                $validator->errors()->add('reference_id', 'Select a valid destination branch.');
            } elseif ($type === 'customer' && $referenceId !== null && ! \App\Models\Customer::whereKey($referenceId)->exists()) {
                $validator->errors()->add('reference_id', 'Select a valid customer.');
            } elseif ($type === 'customer' && ! $referenceId) {
                $validator->errors()->add('reference_id', 'Select a customer.');
            }
        });
        $data = $validator->validate();

        $price = InvoicePricing::requiredPrice($data);
        $this->assertSubmittedPriceMatches($data['unit_price'] ?? null, (float) $price->selling_price, $price->id);

        if (isset($data['reference_type'])) {
            if ($data['reference_type'] === 'customer') {
                $data['customer_id'] = $data['reference_id'];
            } else {
                $data['customer_id'] = null;
            }
        }

        $data['lpg_price_id'] = $price->id;
        $data['unit_price'] = $price->selling_price;
        $data['total_amount'] = (int) $data['quantity'] * (float) $data['unit_price'];

        // BALI-14: snapshot the transportir rate / expedition fee of the linked
        // LO and, when configured, add the delivery fee to the invoice total.
        $data = $this->applyTransportCosts($data);

        $data['created_by']   = $user->id;
        $data['status']       = 'draft';

        // Allocate INV<year>-NNN atomically instead of the racy count()+1 — invoice_number
        // is uniquely indexed, so concurrent creates would collide.
        $invoice = DB::transaction(function () use ($data) {
            $data['invoice_number'] = $this->documentNumbers->next('inv');

            return Invoice::create($data);
        });

        return $this->created($invoice->fresh(['branch', 'customer', 'lpgPrice']));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $user = auth()->user();
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader() && $invoice->branch_id !== $user->branch_id) {
            return $this->forbidden();
        }

        return $this->success($invoice->load(['branch', 'customer', 'lpgPrice']));
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

        $validator = Validator::make($request->all(), [
            'cylinder_type' => 'sometimes|in:3kg,5.5kg,12kg,50kg',
            'quantity'      => 'sometimes|integer|min:1',
            'unit_price'    => 'sometimes|numeric|min:0',
            'issue_date'    => 'sometimes|date',
            'due_date'      => 'sometimes|date',
            'reference_type' => 'sometimes|in:branch,customer',
            'reference_id'   => 'sometimes|nullable|integer',
            'status'        => 'sometimes|in:draft,issued,cancelled',
            'notes'         => 'nullable|string',
        ]);
        $validator->after(function ($validator) use ($request, $invoice): void {
            $type = $request->input('reference_type', $invoice->reference_type);
            $referenceId = $request->input('reference_id', $invoice->reference_id);

            if ($type === 'branch' && (! $referenceId || ! \App\Models\Branch::whereKey($referenceId)->exists())) {
                $validator->errors()->add('reference_id', 'Select a valid destination branch.');
            } elseif ($type === 'customer' && $referenceId !== null && ! \App\Models\Customer::whereKey($referenceId)->exists()) {
                $validator->errors()->add('reference_id', 'Select a valid customer.');
            }
        });
        $data = $validator->validate();

        $submittedPrice = $data['unit_price'] ?? null;
        unset($data['unit_price']);

        $priceInputsChanged = isset($data['cylinder_type'])
            || isset($data['issue_date'])
            || isset($data['reference_type'])
            || isset($data['reference_id']);
        if ($priceInputsChanged) {
            $pricingData = array_merge([
                'branch_id' => $invoice->branch_id,
                'reference_type' => $invoice->reference_type,
                'reference_id' => $invoice->reference_id,
                'cylinder_type' => $invoice->cylinder_type,
                'issue_date' => $invoice->issue_date->toDateString(),
            ], $data);
            $price = InvoicePricing::requiredPrice($pricingData);
            $this->assertSubmittedPriceMatches($submittedPrice, (float) $price->selling_price, $price->id);

            $data['lpg_price_id'] = $price->id;
            $data['unit_price'] = $price->selling_price;
        } elseif ($submittedPrice !== null) {
            $this->assertSubmittedPriceMatches($submittedPrice, (float) $invoice->unit_price, (int) $invoice->lpg_price_id);
        }

        if (isset($data['reference_type'])) {
            $referenceId = $data['reference_id'] ?? $invoice->reference_id;
            $data['reference_id'] = $referenceId;
            $data['customer_id'] = $data['reference_type'] === 'customer' ? $referenceId : null;
        }

        if (isset($data['quantity']) || isset($data['unit_price'])) {
            $qty  = $data['quantity'] ?? $invoice->quantity;
            $unit = $data['unit_price'] ?? $invoice->unit_price;
            // Keep any delivery fee already charged on this invoice.
            $data['total_amount'] = (int) $qty * (float) $unit + (float) ($invoice->expedition_fee_amount ?? 0);
        }

        $invoice->update($data);

        return $this->success($invoice->fresh(['branch', 'customer', 'lpgPrice']));
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

        return $this->success($invoice->fresh(['branch', 'customer', 'lpgPrice']), 'Payment recorded.');
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

        return $this->success($invoice->fresh(['branch', 'customer', 'lpgPrice']), 'Invoice issued.');
    }

    /**
     * BALI-14: record the transportir rate and the expedition delivery fee used
     * for this invoice, and add the fee to the total only when the branch is the
     * one paying for it (config app.expedition_fee_charged_to_buyer).
     *
     * Invoices without a linked LO keep today's behaviour: no snapshot, no fee.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTransportCosts(array $data): array
    {
        $loNumber = $data['loading_order_number'] ?? null;

        $lo = $loNumber
            ? DeliveryOrder::loadingOrders()->where('do_number', $loNumber)->first()
            : null;

        if (! $lo) {
            return array_merge($data, [
                'transportir_rate_at_sale' => null,
                'expedition_cost_at_sale'  => null,
                'expedition_fee_amount'    => 0,
            ]);
        }

        // A Transportir LO never shares its expedition cost: expeditionFee() is 0.
        $fee = $lo->expeditionFee();

        $rate = $lo->transportir_id
            ? TransportirRate::amountAtDate(
                (int) $lo->transportir_id,
                (string) ($data['cylinder_type'] ?? $lo->cylinder_type),
                ($data['issue_date'] ?? null) ?: today()->toDateString()
            )
            : 0.0;

        // The fee may only be passed on to the branch that actually received the goods.
        $sameBranch = (int) $lo->destination_branch_id === (int) ($data['branch_id'] ?? 0);
        $charge = $sameBranch
            && $fee > 0
            && (bool) config('app.expedition_fee_charged_to_buyer', false);

        $data['transportir_rate_at_sale'] = $rate;
        $data['expedition_cost_at_sale']  = $fee;
        $data['expedition_fee_amount']    = $charge ? $fee : 0;

        if ($charge) {
            $data['total_amount'] = (float) $data['total_amount'] + $fee;
        }

        return $data;
    }

    private function assertSubmittedPriceMatches(mixed $submittedPrice, float $effectivePrice, int $priceId): void
    {
        if ($submittedPrice !== null && number_format((float) $submittedPrice, 2, '.', '') !== number_format($effectivePrice, 2, '.', '')) {
            throw ValidationException::withMessages([
                'unit_price' => "Unit price is controlled by LPG Price #{$priceId}. Use the effective LPG Price for this invoice.",
            ]);
        }
    }
}
