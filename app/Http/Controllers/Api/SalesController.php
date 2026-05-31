<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailySaleResource;
use App\Http\Traits\ApiResponse;
use App\Models\DailySale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DailySale::query()->with(['branch', 'customer', 'createdBy'])->orderByDesc('sale_date');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('buyer_type')) {
            $query->where('buyer_type', $request->buyer_type);
        }
        if ($request->filled('from')) {
            $query->whereDate('sale_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('sale_date', '<=', $request->until);
        }

        return $this->paginated($query->paginate(50));
    }

    public function show(DailySale $dailySale, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeBranch($user, $dailySale->branch_id);

        return $this->success(new DailySaleResource($dailySale->load('branch', 'customer', 'createdBy')));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'branch_id'     => ['nullable', 'exists:branches,id'],
            'customer_id'   => ['nullable', 'exists:customers,id'],
            'cylinder_type' => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'buyer_type'    => ['required', 'in:retail,agent,corporate'],
            'quantity'      => ['required', 'integer', 'min:1'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'sale_date'     => ['required', 'date'],
            'notes'         => ['nullable', 'string'],
        ]);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $data['branch_id'] = $user->branch_id;
        }
        $data['created_by'] = $user->id;

        $sale = DailySale::create($data);

        return $this->created(new DailySaleResource($sale->load('branch', 'customer', 'createdBy')));
    }

    public function update(DailySale $dailySale, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeBranch($user, $dailySale->branch_id);

        $data = $request->validate([
            'customer_id'   => ['nullable', 'exists:customers,id'],
            'cylinder_type' => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'buyer_type'    => ['sometimes', 'in:retail,agent,corporate'],
            'quantity'      => ['sometimes', 'integer', 'min:1'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_date'     => ['sometimes', 'date'],
            'notes'         => ['nullable', 'string'],
        ]);

        $dailySale->update($data);

        return $this->success(new DailySaleResource($dailySale->fresh()->load('branch', 'customer', 'createdBy')));
    }

    public function destroy(DailySale $dailySale, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeBranch($user, $dailySale->branch_id);

        $dailySale->delete();

        return $this->noContent('Sale record deleted.');
    }

    private function authorizeBranch($user, int $branchId): void
    {
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) return;
        if ($user->branch_id !== $branchId) abort(403);
    }
}
