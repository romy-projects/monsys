<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayableResource;
use App\Http\Traits\ApiResponse;
use App\Models\Payable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayableController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Payable::query()->with('expedition');

        // Branch-scoped: only owner_pusat and regional_leader see all; others see nothing (payables are pusat-level)
        if (! $user->canViewFinance()) {
            return $this->forbidden();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('expedition_id')) {
            $query->where('expedition_id', $request->expedition_id);
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
        if (! $request->user()->canViewFinance()) {
            return $this->forbidden();
        }

        return $this->success(
            new PayableResource($payable->load('expedition', 'deliveryOrder'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->canViewFinance()) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'expedition_id'     => ['required', 'exists:expeditions,id'],
            'delivery_order_id' => ['nullable', 'exists:delivery_orders,id'],
            'invoice_number'    => ['nullable', 'string', 'max:100'],
            'description'       => ['required', 'string', 'max:255'],
            'amount'            => ['required', 'numeric', 'min:0'],
            'paid_amount'       => ['nullable', 'numeric', 'min:0'],
            'due_date'          => ['nullable', 'date'],
            'status'            => ['nullable', 'in:pending,paid'],
        ]);

        $data['paid_amount'] ??= 0;
        $data['status']      ??= 'pending';

        $payable = Payable::create($data);

        return $this->created(
            new PayableResource($payable->load('expedition'))
        );
    }

    public function update(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->canViewFinance()) {
            return $this->forbidden();
        }

        if ($payable->status === 'paid') {
            return $this->error('Paid payables cannot be edited.', 422);
        }

        $data = $request->validate([
            'expedition_id'     => ['sometimes', 'exists:expeditions,id'],
            'delivery_order_id' => ['nullable', 'exists:delivery_orders,id'],
            'invoice_number'    => ['nullable', 'string', 'max:100'],
            'description'       => ['sometimes', 'string', 'max:255'],
            'amount'            => ['sometimes', 'numeric', 'min:0'],
            'paid_amount'       => ['nullable', 'numeric', 'min:0'],
            'due_date'          => ['nullable', 'date'],
            'status'            => ['nullable', 'in:pending,paid'],
        ]);

        $payable->update($data);

        return $this->success(
            new PayableResource($payable->fresh()->load('expedition'))
        );
    }

    public function destroy(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->canViewFinance()) {
            return $this->forbidden();
        }

        $payable->delete();

        return $this->noContent();
    }

    public function pay(Payable $payable, Request $request): JsonResponse
    {
        if (! $request->user()->canViewFinance()) {
            return $this->forbidden();
        }

        if ($payable->status === 'paid') {
            return $this->error('Already paid.', 422);
        }

        $data = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $payable->paid_amount = $data['paid_amount'];
        $payable->save();

        // Auto-recalculate status
        $payable->recalculateStatus();

        if ($payable->status === 'paid') {
            $payable->paid_at = now();
            $payable->saveQuietly();
        }

        return $this->success(
            new PayableResource($payable->fresh()->load('expedition')),
            'Payment recorded.'
        );
    }
}
