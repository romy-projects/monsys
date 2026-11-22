<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayablePaymentResource;
use App\Http\Resources\PayableResource;
use App\Http\Traits\ApiResponse;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Services\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayableController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Payable::query()->with('transportir');

        if (! $user->hasPermission('payables.view')) {
            return $this->forbidden();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('transportir_id')) {
            $query->where('transportir_id', $request->transportir_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('created_at', '<=', $request->until);
        }

        return $this->paginated($query->orderByDesc('created_at')->paginate(30));
    }

    public function show(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.view')) {
            return $this->forbidden();
        }

        return $this->success(
            new PayableResource($this->withPayments($payable)->load('transportir', 'deliveryOrder'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'transportir_id' => ['required', 'exists:transportirs,id'],
            'delivery_order_id' => [
                'nullable',
                Rule::exists('delivery_orders', 'id')
                    ->where('document_type', 'lo')
                    ->where('transportir_id', (int) $request->input('transportir_id')),
            ],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,paid'],
        ]);

        $opening = (float) ($data['paid_amount'] ?? 0);
        unset($data['paid_amount']);

        $payable = Payable::create($data + ['paid_amount' => 0, 'status' => 'pending']);

        if ($opening > 0) {
            try {
                app(PaymentLedger::class)->recordPayable($payable, [
                    'amount' => $opening,
                    'method' => 'other',
                    'notes' => 'Pembayaran awal saat pembuatan tagihan',
                ], $request->user()->id);
            } catch (ValidationException $e) {
                $payable->delete();

                return $this->error($this->firstError($e), 422);
            }
        }

        return $this->created(
            new PayableResource($this->withPayments($payable->fresh())->load('transportir'))
        );
    }

    public function update(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.update')) {
            return $this->forbidden();
        }

        if ($payable->status === 'paid') {
            return $this->error('Paid payables cannot be edited.', 422);
        }

        $transportirId = (int) $request->input('transportir_id', $payable->transportir_id);

        $data = $request->validate([
            'transportir_id' => ['sometimes', 'exists:transportirs,id'],
            'delivery_order_id' => [
                'nullable',
                Rule::exists('delivery_orders', 'id')
                    ->where('document_type', 'lo')
                    ->where('transportir_id', $transportirId),
            ],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,paid'],
        ]);

        // BALI-17: paid_amount is owned by the payment ledger; it may only be
        // changed by recording or reversing a payment.
        if (array_key_exists('paid_amount', $data) && $data['paid_amount'] !== null) {
            if (round((float) $data['paid_amount'], 2) !== round((float) $payable->paid_amount, 2)) {
                return $this->error('paid_amount cannot be set directly. Record a payment on POST /payables/{payable}/payments instead.', 422);
            }
        }

        unset($data['paid_amount'], $data['status']);

        if (array_key_exists('amount', $data) && (float) $data['amount'] < (float) $payable->paid_amount) {
            return $this->error('amount cannot be lower than the amount already paid.', 422);
        }

        $payable->update($data);
        $payable->recalculateStatus();

        return $this->success(
            new PayableResource($this->withPayments($payable->fresh())->load('transportir'))
        );
    }

    public function destroy(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.delete')) {
            return $this->forbidden();
        }

        $payable->delete();

        return $this->noContent();
    }

    /**
     * BALI-17: legacy endpoint kept for the mobile app. It still receives the
     * *absolute* paid_amount target (the dialog is prefilled with the balance),
     * so only the difference is recorded as a ledger row.
     */
    public function pay(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.record_payment')) {
            return $this->forbidden();
        }

        if ($payable->status === 'paid') {
            return $this->error('Already paid.', 422);
        }

        $data = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $target = (float) $data['paid_amount'];

        if ($target < (float) $payable->paid_amount) {
            return $this->error(
                'paid_amount cannot be lower than the amount already paid. Reverse a payment on DELETE /payables/{payable}/payments/{payment} instead.',
                422
            );
        }

        try {
            app(PaymentLedger::class)->recordPayableTarget($payable, $target, $request->user()->id);
        } catch (ValidationException $e) {
            return $this->error($this->firstError($e), 422);
        }

        return $this->success(
            new PayableResource($this->withPayments($payable->fresh())->load('transportir')),
            'Payment recorded.'
        );
    }

    /** BALI-17: payment history of one payable. */
    public function payments(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.view')) {
            return $this->forbidden();
        }

        return $this->success(
            PayablePaymentResource::collection($this->paymentRows($payable))
        );
    }

    /** BALI-17: record one payable payment instalment. */
    public function storePayment(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.record_payment')) {
            return $this->forbidden();
        }

        if ($payable->status === 'paid') {
            return $this->error('Already paid.', 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(array_keys(Payment::methods()))],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payment = app(PaymentLedger::class)->recordPayable($payable, $data, $request->user()->id);
        } catch (ValidationException $e) {
            return $this->error($this->firstError($e), 422);
        }

        return $this->created(
            new PayablePaymentResource($payment->load('deliveryOrder', 'payment.createdBy'))
        );
    }

    /** BALI-17: reverse one payment row. Owner pusat only. */
    public function destroyPayment(Payable $payable, PaymentDetail $payment, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('payables.reverse_payment')) {
            return $this->forbidden();
        }

        if ((int) $payment->payable_id !== (int) $payable->id) {
            return $this->error('Payment not found for this payable.', 404);
        }

        app(PaymentLedger::class)->reversePayablePayment($payment);

        return $this->noContent('Payment reversed.');
    }

    private function withPayments(Payable $payable): Payable
    {
        // `paid_on` lives on the payment header now, so the ordering goes
        // through the join that PaymentDetail::latestFirst() adds.
        return $payable->load([
            'payments' => fn ($query) => $query->with('payment')->latestFirst(),
        ]);
    }

    private function paymentRows(Payable $payable)
    {
        return $payable->payments()
            ->with('deliveryOrder', 'payment.createdBy')
            ->latestFirst()
            ->get();
    }

    private function firstError(ValidationException $e): string
    {
        return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
    }
}
