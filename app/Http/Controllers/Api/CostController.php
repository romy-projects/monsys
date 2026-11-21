<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationalCostResource;
use App\Http\Traits\ApiResponse;
use App\Models\OperationalCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('operational_costs.view')) {
            return $this->forbidden();
        }

        $query = OperationalCost::query()->with(['branch', 'createdBy'])->orderByDesc('cost_date');

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('cost_category')) {
            $query->where('cost_category', $request->cost_category);
        }
        if ($request->filled('from')) {
            $query->whereDate('cost_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('cost_date', '<=', $request->until);
        }

        return $this->paginated($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('operational_costs.create')) {
            return $this->forbidden('Finance data is restricted to owners and managers.');
        }

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'cost_category' => ['required', 'in:fuel,salary,logistics,levy,other'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cost_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $branchId = (int) ($data['branch_id'] ?? $user->branch_id ?? 0);
        if (! $user->canAccessBranch($branchId)) {
            return $this->forbidden();
        }
        $data['branch_id'] = $branchId;
        $data['created_by'] = $user->id;

        $cost = OperationalCost::create($data);

        return $this->created(new OperationalCostResource($cost->load('branch', 'createdBy')));
    }

    public function update(OperationalCost $operationalCost, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('operational_costs.update')) {
            return $this->forbidden();
        }

        if (! $user->canAccessBranch((int) $operationalCost->branch_id)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'cost_category' => ['sometimes', 'in:fuel,salary,logistics,levy,other'],
            'description' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'cost_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $operationalCost->update($data);

        return $this->success(new OperationalCostResource($operationalCost->fresh()->load('branch', 'createdBy')));
    }

    public function destroy(OperationalCost $operationalCost, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('operational_costs.delete')
            || ! $user->canAccessBranch((int) $operationalCost->branch_id)) {
            return $this->forbidden();
        }

        $operationalCost->delete();

        return $this->noContent('Cost record deleted.');
    }
}
