<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailySaleResource;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\DailySale;
use App\Models\LpgPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('sales.view')) {
            return $this->forbidden();
        }

        $query = DailySale::query()->with(['branch', 'customer', 'createdBy', 'lpgPrice'])->orderByDesc('sale_date');

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

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
        if (! $user->hasPermission('sales.view') || ! $user->canAccessBranch((int) $dailySale->branch_id)) {
            return $this->forbidden();
        }

        return $this->success(new DailySaleResource($dailySale->load('branch', 'customer', 'createdBy', 'lpgPrice')));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('sales.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'cylinder_type' => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'buyer_type' => ['required', 'in:retail,agent,corporate'],
            'quantity' => ['required', 'integer', 'min:1'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['branch_id'] = (int) ($data['branch_id'] ?? $user->branch_id ?? 0);
        if (! $user->canAccessBranch($data['branch_id'])) {
            return $this->forbidden();
        }

        if (! empty($data['customer_id'])
            && ! Customer::query()
                ->whereKey($data['customer_id'])
                ->where('branch_id', $data['branch_id'])
                ->exists()) {
            return $this->forbidden();
        }

        $price = LpgPrice::requiredPriceAtDate(
            $data['cylinder_type'],
            $data['sale_date'],
            (int) $data['branch_id'],
        );
        $this->assertSubmittedPriceMatches($data['selling_price'] ?? null, (float) $price->selling_price, $price->id);

        $data['lpg_price_id'] = $price->id;
        $data['purchase_price_at_sale'] = $price->purchase_price;
        $data['selling_price'] = $price->selling_price;
        $data['created_by'] = $user->id;

        $sale = DailySale::create($data);

        return $this->created(new DailySaleResource($sale->load('branch', 'customer', 'createdBy', 'lpgPrice')));
    }

    public function update(DailySale $dailySale, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('sales.update') || ! $user->canAccessBranch((int) $dailySale->branch_id)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'cylinder_type' => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'buyer_type' => ['sometimes', 'in:retail,agent,corporate'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['customer_id'])
            && ! Customer::query()
                ->whereKey($data['customer_id'])
                ->where('branch_id', $dailySale->branch_id)
                ->exists()) {
            return $this->forbidden();
        }

        $submittedPrice = $data['selling_price'] ?? null;
        unset($data['selling_price']);

        $priceInputsChanged = isset($data['cylinder_type']) || isset($data['sale_date']);
        if ($priceInputsChanged) {
            $cylinderType = $data['cylinder_type'] ?? $dailySale->cylinder_type;
            $saleDate = $data['sale_date'] ?? $dailySale->sale_date->toDateString();
            $price = LpgPrice::requiredPriceAtDate($cylinderType, $saleDate, $dailySale->branch_id);
            $this->assertSubmittedPriceMatches($submittedPrice, (float) $price->selling_price, $price->id);

            $data['lpg_price_id'] = $price->id;
            $data['purchase_price_at_sale'] = $price->purchase_price;
            $data['selling_price'] = $price->selling_price;
        } elseif ($submittedPrice !== null) {
            $this->assertSubmittedPriceMatches($submittedPrice, (float) $dailySale->selling_price, (int) $dailySale->lpg_price_id);
        }

        $dailySale->update($data);

        return $this->success(new DailySaleResource($dailySale->fresh()->load('branch', 'customer', 'createdBy', 'lpgPrice')));
    }

    public function destroy(DailySale $dailySale, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('sales.delete') || ! $user->canAccessBranch((int) $dailySale->branch_id)) {
            return $this->forbidden();
        }

        $dailySale->delete();

        return $this->noContent('Sale record deleted.');
    }

    private function assertSubmittedPriceMatches(mixed $submittedPrice, float $effectivePrice, int $priceId): void
    {
        if ($submittedPrice !== null && number_format((float) $submittedPrice, 2, '.', '') !== number_format($effectivePrice, 2, '.', '')) {
            throw ValidationException::withMessages([
                'selling_price' => "Selling price is controlled by LPG Price #{$priceId}. Use the effective LPG Price for this transaction.",
            ]);
        }
    }
}
