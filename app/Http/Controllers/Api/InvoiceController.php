<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\TransportirRate;
use App\Services\DocumentNumberService;
use App\Services\PaymentLedger;
use App\Support\InvoicePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('invoices.view')) {
            return $this->forbidden();
        }

        $query = Invoice::with(['branch', 'customer', 'lpgPrice']);

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where('branch_id', $request->branch_id);
        }

        $perPage = min((int) ($request->per_page ?? 15), 100);

        return $this->paginated($query->paginate($perPage), fn (Invoice $inv) => [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'branch' => $inv->branch?->name,
            'customer' => $inv->customer?->name,
            'cylinder_type' => $inv->cylinder_type,
            'quantity' => $inv->quantity,
            'unit_price' => (float) $inv->unit_price,
            'lpg_price_id' => $inv->lpg_price_id,
            'lpg_price' => $inv->lpgPrice ? [
                'id' => $inv->lpgPrice->id,
                'branch_id' => $inv->lpgPrice->branch_id,
                'effective_date' => $inv->lpgPrice->effective_date?->toDateString(),
            ] : null,
            'total_amount' => (float) $inv->total_amount,
            'paid_amount' => (float) $inv->paid_amount,
            'balance' => $inv->balance,
            'issue_date' => $inv->issue_date->toDateString(),
            'due_date' => $inv->due_date->toDateString(),
            'status' => $inv->status,
            'notes' => $inv->notes,
        ]);
    }

    /** Create a draft invoice. Number is allocated server-side. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('invoices.create')) {
            return $this->forbidden();
        }

        if (! $user->canAccessBranch((int) $request->input('branch_id', 0))) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'reference_type' => 'sometimes|in:branch,customer',
            'reference_id' => 'nullable|integer',
            'cylinder_type' => 'required|in:3kg,5.5kg,12kg,50kg',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'sometimes|numeric|min:0',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'loading_order_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);
        $validator->after(function ($validator) use ($request, $user): void {
            $type = $request->input('reference_type');
            $referenceId = $request->input('reference_id');
            $branchId = (int) $request->input('branch_id', 0);

            if ($type === 'branch' && (
                ! $referenceId ||
                ! $user->canAccessBranch((int) $referenceId) ||
                ! Branch::whereKey($referenceId)->exists()
            )) {
                $validator->errors()->add('reference_id', 'Select a valid destination branch.');
            } elseif ($type === 'customer' && $referenceId !== null && ! Customer::whereKey($referenceId)->where('branch_id', $branchId)->exists()) {
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
        $data['total_amount'] = InvoicePricing::total($data);

        // BALI-14: snapshot the transportir rate / expedition fee of the linked
        // LO and, when configured, add the delivery fee to the invoice total.
        $data = $this->applyTransportCosts($data);

        $data['created_by'] = $user->id;
        $data['status'] = 'draft';

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
        if (! $user->hasPermission('invoices.view') || ! $user->canAccessBranch((int) $invoice->branch_id)) {
            return $this->forbidden();
        }

        return $this->success($invoice->load(['branch', 'customer', 'lpgPrice']));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $user = auth()->user();
        if (! $user->hasPermission('invoices.update') || ! $user->canAccessBranch((int) $invoice->branch_id)) {
            return $this->forbidden();
        }

        if (! in_array($invoice->status, ['draft', 'issued', 'partial', 'overdue'])) {
            return $this->error('Cannot modify invoice with status: '.$invoice->status, 422);
        }

        $validator = Validator::make($request->all(), [
            'cylinder_type' => 'sometimes|in:3kg,5.5kg,12kg,50kg',
            'quantity' => 'sometimes|integer|min:1',
            'unit_price' => 'sometimes|numeric|min:0',
            'issue_date' => 'sometimes|date',
            'due_date' => 'sometimes|date',
            'reference_type' => 'sometimes|in:branch,customer',
            'reference_id' => 'sometimes|nullable|integer',
            'status' => 'sometimes|in:draft,issued,cancelled',
            'loading_order_number' => 'sometimes|nullable|string|max:100',
            'transportir_rate_at_sale' => 'sometimes|nullable|numeric|min:0',
            'expedition_cost_at_sale' => 'sometimes|nullable|numeric|min:0',
            'expedition_fee_amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        $validator->after(function ($validator) use ($request, $invoice, $user): void {
            $type = $request->input('reference_type', $invoice->reference_type);
            $referenceId = $request->input('reference_id', $invoice->reference_id);

            if ($type === 'branch' && (
                ! $referenceId ||
                ! $user->canAccessBranch((int) $referenceId) ||
                ! Branch::whereKey($referenceId)->exists()
            )) {
                $validator->errors()->add('reference_id', 'Select a valid destination branch.');
            } elseif ($type === 'customer' && $referenceId !== null && ! Customer::whereKey($referenceId)->where('branch_id', $invoice->branch_id)->exists()) {
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

        // Phase 19B / T19B-11 (D5): the delivery fields are editable here too and
        // the total always goes through the single definition in InvoicePricing.
        if (array_intersect(['quantity', 'unit_price', 'expedition_fee_amount', 'expedition_cost_at_sale'], array_keys($data)) !== []) {
            $merged = [
                'unit_price' => $data['unit_price'] ?? (float) $invoice->unit_price,
                'quantity' => $data['quantity'] ?? (int) $invoice->quantity,
                'expedition_fee_amount' => array_key_exists('expedition_fee_amount', $data)
                    ? $data['expedition_fee_amount']
                    : (float) $invoice->expedition_fee_amount,
                'expedition_cost_at_sale' => array_key_exists('expedition_cost_at_sale', $data)
                    ? $data['expedition_cost_at_sale']
                    : $invoice->expedition_cost_at_sale,
            ];

            InvoicePricing::assertExpeditionFeeWithinCost($merged);
            $data['total_amount'] = InvoicePricing::total($merged);
        }

        $invoice->update($data);

        return $this->success($invoice->fresh(['branch', 'customer', 'lpgPrice']));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        if (! auth()->user()->hasPermission('invoices.delete')
            || ! auth()->user()->canAccessBranch((int) $invoice->branch_id)) {
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
        if (! $user->hasPermission('invoices.pay') || ! $user->canAccessBranch((int) $invoice->branch_id)) {
            return $this->forbidden();
        }

        if ($invoice->status === 'paid' || $invoice->status === 'cancelled') {
            return $this->error('Cannot pay a '.$invoice->status.' invoice.', 422);
        }

        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'paid_on' => 'nullable|date',
            'method' => 'nullable|in:cash,transfer,giro,other',
            'cash_account_id' => 'nullable|exists:chart_of_accounts,id',
            'reference_no' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:255',
        ])->validated();

        // Phase 19A / T19A-03: every payment is a ledger row, never a hand-added
        // paid_amount — the Jurnal export needs the date and the method.
        $payment = app(PaymentLedger::class)->recordInvoice($invoice, $data, $user->id);

        $fresh = $invoice->fresh(['branch', 'customer', 'lpgPrice']);

        // Backward compatible payload: the invoice fields stay at the top level,
        // the ledger row is added under `payment`.
        return $this->success(
            array_merge($fresh->toArray(), ['payment' => $payment->toArray()]),
            'Payment recorded.'
        );
    }

    /** Issue a draft invoice. */
    public function issue(Invoice $invoice): JsonResponse
    {
        $user = auth()->user();

        if (! $user->hasPermission('invoices.issue') || ! $user->canAccessBranch((int) $invoice->branch_id)) {
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
                'expedition_cost_at_sale' => null,
                'expedition_fee_amount' => 0,
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

        // The fee may only be passed on to the branch that actually received the
        // goods. A per-shipment decision on the DO wins over the global config.
        $sameBranch = (int) $lo->destination_branch_id === (int) ($data['branch_id'] ?? 0);
        $charge = $sameBranch
            && $fee > 0
            && InvoicePricing::expeditionChargedToBuyer($lo->expedition_fee_charged_to_buyer);

        $data['transportir_rate_at_sale'] = $rate;
        $data['expedition_cost_at_sale'] = $fee;
        $data['expedition_fee_amount'] = $charge ? $fee : 0;
        $data['total_amount'] = InvoicePricing::total($data);

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
