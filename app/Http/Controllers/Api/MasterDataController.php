<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ExpeditionResource;
use App\Http\Resources\LpgPriceResource;
use App\Http\Resources\TransportirResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expedition;
use App\Models\LpgPrice;
use App\Models\Transportir;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MasterDataController extends Controller
{
    use ApiResponse;

    // ── Branches ──────────────────────────────────────────────

    public function branches(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('branches.view')) {
            return $this->forbidden();
        }

        $query = Branch::query()->where('status', 'active');
        $user->applyAccessibleBranchScope($query, 'branches.id');

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->whereKey($request->branch_id);
        }

        $branches = $query->orderBy('name')->get();

        return $this->success(BranchResource::collection($branches));
    }

    public function storeBranch(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('branches.create')
            || ! $request->user()->canAccessAllBranches()) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'regional_id' => ['nullable', 'integer'],
        ]);

        $data['status'] = 'active';

        return $this->created(new BranchResource(Branch::create($data)));
    }

    public function updateBranch(Branch $branch, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('branches.update')
            || ! $request->user()->canAccessBranch((int) $branch->id)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $branch->update($data);

        return $this->success(new BranchResource($branch->fresh()));
    }

    // ── Expeditions ───────────────────────────────────────────

    public function expeditions(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('expeditions.view')) {
            return $this->forbidden();
        }

        $expeditions = Expedition::where('status', 'active')->orderBy('name')->get();

        return $this->success(ExpeditionResource::collection($expeditions));
    }

    public function storeExpedition(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('expeditions.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
        ]);

        $data['status'] = 'active';

        return $this->created(new ExpeditionResource(Expedition::create($data)));
    }

    public function updateExpedition(Expedition $expedition, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('expeditions.update')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $expedition->update($data);

        return $this->success(new ExpeditionResource($expedition->fresh()));
    }

    public function destroyExpedition(Expedition $expedition, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('expeditions.delete')) {
            return $this->forbidden();
        }
        $expedition->delete();

        return $this->noContent();
    }

    // ── Transportirs ──────────────────────────────────────────

    public function transportirs(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('transportirs.view')) {
            return $this->forbidden();
        }

        $transportirs = Transportir::where('status', 'active')->orderBy('name')->get();

        return $this->success(TransportirResource::collection($transportirs));
    }

    public function storeTransportir(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('transportirs.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
        ]);

        $data['status'] = 'active';

        return $this->created(new TransportirResource(Transportir::create($data)));
    }

    public function updateTransportir(Transportir $transportir, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('transportirs.update')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $transportir->update($data);

        return $this->success(new TransportirResource($transportir->fresh()));
    }

    public function destroyTransportir(Transportir $transportir, Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('transportirs.delete')) {
            return $this->forbidden();
        }
        $transportir->delete();

        return $this->noContent();
    }

    // ── LPG Prices ────────────────────────────────────────────

    public function prices(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('prices.view')) {
            return $this->forbidden();
        }

        $query = LpgPrice::with('branch')
            ->orderBy('cylinder_type')
            ->orderByDesc('effective_date')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where('branch_id', $request->branch_id);
        }

        return $this->success(LpgPriceResource::collection($query->get()));
    }

    public function currentPrices(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('prices.view')) {
            return $this->forbidden();
        }

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        if ($branchId !== null && ! $user->canAccessBranch($branchId)) {
            return $this->forbidden();
        }

        $types = ['3kg', '5.5kg', '12kg', '50kg'];
        $prices = collect($types)->mapWithKeys(fn ($type) => [
            $type => ($p = LpgPrice::currentPrice($type, $branchId))
                ? [
                    'id' => $p->id,
                    'branch_id' => $p->branch_id,
                    'effective_date' => $p->effective_date?->toDateString(),
                    'purchase_price' => (float) $p->purchase_price,
                    'selling_price' => (float) $p->selling_price,
                ]
                : null,
        ]);

        return $this->success($prices);
    }

    public function storePrice(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('prices.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'cylinder_type' => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        $branchId = $data['branch_id'] ?? null;

        if ($branchId !== null && ! $user->canAccessBranch((int) $branchId)) {
            return $this->forbidden();
        }

        if ($branchId === null && ! $user->canAccessAllBranches()) {
            return $this->forbidden();
        }

        $data['created_by'] = $user->id;

        return $this->created(new LpgPriceResource(LpgPrice::create($data)));
    }

    // ── Customers ─────────────────────────────────────────────

    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('customers.view')) {
            return $this->forbidden();
        }

        $query = Customer::active()->with('branch')->orderBy('name');

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where('branch_id', $request->branch_id);
        }

        return $this->success(CustomerResource::collection($query->get()));
    }

    // ── Vehicles ──────────────────────────────────────────────

    public function vehicles(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('vehicles.view')) {
            return $this->forbidden();
        }

        $query = Vehicle::active()->with('transportir')->orderBy('plate_number');

        if ($request->filled('transportir_id')) {
            $query->where('transportir_id', $request->transportir_id);
        }

        return $this->success(VehicleResource::collection($query->get()));
    }

    // ── Users (owner_pusat only) ──────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('users.view')) {
            return $this->forbidden();
        }

        $query = User::with('branch')->orderBy('name');
        $user->applyAccessibleBranchScope($query);
        $users = $query->get();

        return $this->success(UserResource::collection($users));
    }

    public function storeUser(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('users.create')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:owner_pusat,regional_leader,owner_cabang,staff_gudang'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($data['branch_id'] === null && ! $user->canAccessAllBranches()) {
            return $this->forbidden();
        }

        if (! empty($data['branch_id']) && ! $user->canAccessBranch((int) $data['branch_id'])) {
            return $this->forbidden();
        }

        $data['password'] = Hash::make($data['password']);
        $data['status'] = 'active';

        return $this->created(new UserResource(User::create($data)->load('branch')));
    }

    public function updateUser(User $user, Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->hasPermission('users.update')) {
            return $this->forbidden();
        }
        if (($user->branch_id === null && ! $actor->canAccessAllBranches())
            || ($user->branch_id !== null && ! $actor->canAccessBranch((int) $user->branch_id))) {
            return $this->forbidden();
        }
        if ($user->accessRole?->is_super_admin
            && (array_key_exists('role', $request->all()) || $request->input('status') === 'inactive')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'in:owner_pusat,regional_leader,owner_cabang,staff_gudang'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (array_key_exists('branch_id', $data)) {
            if ($data['branch_id'] === null && ! $actor->canAccessAllBranches()) {
                return $this->forbidden();
            }

            if ($data['branch_id'] !== null && ! $actor->canAccessBranch((int) $data['branch_id'])) {
                return $this->forbidden();
            }
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $this->success(new UserResource($user->fresh()->load('branch')));
    }

    public function destroyUser(User $user, Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->hasPermission('users.delete')
            || ($user->branch_id === null && ! $actor->canAccessAllBranches())
            || ($user->branch_id !== null && ! $actor->canAccessBranch((int) $user->branch_id))
            || $user->accessRole?->is_super_admin) {
            return $this->forbidden();
        }
        if ($user->id === $actor->id) {
            return $this->error('Cannot delete your own account.', 422);
        }

        $user->delete();

        return $this->noContent('User deleted.');
    }
}
