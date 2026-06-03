<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ExpeditionResource;
use App\Http\Resources\LpgPriceResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expedition;
use App\Models\LpgPrice;
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
        $user     = $request->user();
        $branches = Branch::when(
            ! $user->isOwnerPusat() && ! $user->isRegionalLeader(),
            fn($q) => $q->where('id', $user->branch_id)
        )->where('status', 'active')->orderBy('name')->get();

        return $this->success(BranchResource::collection($branches));
    }

    public function storeBranch(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'code'        => ['required', 'string', 'max:20', 'unique:branches,code'],
            'name'        => ['required', 'string', 'max:100'],
            'city'        => ['nullable', 'string', 'max:100'],
            'province'    => ['nullable', 'string', 'max:100'],
            'address'     => ['nullable', 'string'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'regional_id' => ['nullable', 'integer'],
        ]);

        $data['status'] = 'active';
        return $this->created(new BranchResource(Branch::create($data)));
    }

    public function updateBranch(Branch $branch, Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'city'        => ['nullable', 'string', 'max:100'],
            'province'    => ['nullable', 'string', 'max:100'],
            'address'     => ['nullable', 'string'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'status'      => ['sometimes', 'in:active,inactive'],
        ]);

        $branch->update($data);
        return $this->success(new BranchResource($branch->fresh()));
    }

    // ── Expeditions ───────────────────────────────────────────

    public function expeditions(Request $request): JsonResponse
    {
        $expeditions = Expedition::where('status', 'active')->orderBy('name')->get();
        return $this->success(ExpeditionResource::collection($expeditions));
    }

    public function storeExpedition(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'code'           => ['nullable', 'string', 'max:20'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
        ]);

        $data['status'] = 'active';
        return $this->created(new ExpeditionResource(Expedition::create($data)));
    }

    public function updateExpedition(Expedition $expedition, Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:100'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'status'         => ['sometimes', 'in:active,inactive'],
        ]);

        $expedition->update($data);
        return $this->success(new ExpeditionResource($expedition->fresh()));
    }

    public function destroyExpedition(Expedition $expedition, Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();
        $expedition->delete();
        return $this->noContent();
    }

    // ── LPG Prices ────────────────────────────────────────────

    public function prices(Request $request): JsonResponse
    {
        $query = LpgPrice::with('branch')->orderBy('cylinder_type')->orderByDesc('effective_date');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        } elseif (! $request->user()->isOwnerPusat() && ! $request->user()->isRegionalLeader()) {
            $query->where(fn($q) => $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id'));
        }

        return $this->success(LpgPriceResource::collection($query->get()));
    }

    public function currentPrices(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $types    = ['3kg', '5.5kg', '12kg', '50kg'];
        $prices   = collect($types)->mapWithKeys(fn($type) => [
            $type => ($p = LpgPrice::currentPrice($type, $branchId))
                ? ['purchase_price' => (float) $p->purchase_price, 'selling_price' => (float) $p->selling_price]
                : null,
        ]);

        return $this->success($prices);
    }

    public function storePrice(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canViewFinance()) return $this->forbidden();

        $data = $request->validate([
            'cylinder_type'  => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price'  => ['required', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'branch_id'      => ['nullable', 'exists:branches,id'],
        ]);

        $data['created_by'] = $user->id;
        return $this->created(new LpgPriceResource(LpgPrice::create($data)));
    }

    // ── Customers ─────────────────────────────────────────────

    public function customers(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Customer::active()->with('branch')->orderBy('name');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return $this->success(CustomerResource::collection($query->get()));
    }

    // ── Vehicles ──────────────────────────────────────────────

    public function vehicles(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Vehicle::active()->with('expedition')->orderBy('plate_number');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->whereHas('expedition', fn($q) => $q->where('status', 'active'));
        } elseif ($request->filled('expedition_id')) {
            $query->where('expedition_id', $request->expedition_id);
        }

        return $this->success(VehicleResource::collection($query->get()));
    }

    // ── Users (owner_pusat only) ──────────────────────────────

    public function users(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $users = User::with('branch')->orderBy('name')->get();
        return $this->success(UserResource::collection($users));
    }

    public function storeUser(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'email'     => ['required', 'email', 'unique:users,email'],
            'password'  => ['required', 'string', 'min:8'],
            'role'      => ['required', 'in:owner_pusat,regional_leader,owner_cabang,staff_gudang'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone'     => ['nullable', 'string', 'max:20'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['status']   = 'active';

        return $this->created(new UserResource(User::create($data)->load('branch')));
    }

    public function updateUser(User $user, Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100'],
            'email'     => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'password'  => ['nullable', 'string', 'min:8'],
            'role'      => ['sometimes', 'in:owner_pusat,regional_leader,owner_cabang,staff_gudang'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone'     => ['nullable', 'string', 'max:20'],
            'status'    => ['sometimes', 'in:active,inactive'],
        ]);

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
        if (! $request->user()->isOwnerPusat()) return $this->forbidden();
        if ($user->id === $request->user()->id) return $this->error('Cannot delete your own account.', 422);

        $user->delete();
        return $this->noContent('User deleted.');
    }
}
