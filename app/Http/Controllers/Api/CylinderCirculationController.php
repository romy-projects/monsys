<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CylinderCirculationResource;
use App\Http\Traits\ApiResponse;
use App\Models\CylinderCirculation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CylinderCirculationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $this->authorizeHeadquarters($user);
        $query = CylinderCirculation::query()->with('branch');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('from')) {
            $query->whereDate('transaction_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('transaction_date', '<=', $request->until);
        }

        return $this->paginated($query->orderByDesc('transaction_date')->paginate(30));
    }

    public function show(CylinderCirculation $cylinderCirculation, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeHeadquarters($user);

        return $this->success(
            new CylinderCirculationResource($cylinderCirculation->load('branch', 'createdBy'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeHeadquarters($user);

        $data = $request->validate([
            'branch_id'         => ['required', 'exists:branches,id'],
            'transaction_date'  => ['required', 'date'],
            'so_number'         => ['nullable', 'string', 'max:100'],
            'transaction_type'  => ['required', 'in:kirim,bongkar_kosong,pembelian,penyesuaian'],
            'description'       => ['nullable', 'string'],
            'cylinder_type'     => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'direction'         => ['nullable', 'in:debit,kredit'],
            'quantity'          => ['required', 'integer', 'min:1'],
            'container_no'      => ['nullable', 'string', 'max:100'],
            'handled_by'        => ['nullable', 'string', 'max:200'],
            'notes'             => ['nullable', 'string'],
        ]);

        $data['created_by'] = $user->id;

        $circulation = CylinderCirculation::create($data);

        return $this->created(
            new CylinderCirculationResource($circulation->load('branch'))
        );
    }

    public function update(CylinderCirculation $cylinderCirculation, Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorizeHeadquarters($user);
        if ($cylinderCirculation->purchase_order_id !== null) {
            return $this->error('PO receipt circulation entries cannot be edited.', 422);
        }

        $data = $request->validate([
            'transaction_date' => ['sometimes', 'date'],
            'so_number'        => ['nullable', 'string', 'max:100'],
            'transaction_type' => ['sometimes', 'in:kirim,bongkar_kosong,pembelian,penyesuaian'],
            'description'      => ['nullable', 'string'],
            'cylinder_type'    => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'direction'        => ['nullable', 'in:debit,kredit'],
            'quantity'         => ['sometimes', 'integer', 'min:1'],
            'container_no'     => ['nullable', 'string', 'max:100'],
            'handled_by'       => ['nullable', 'string', 'max:200'],
            'notes'            => ['nullable', 'string'],
        ]);

        $cylinderCirculation->update($data);

        return $this->success(
            new CylinderCirculationResource($cylinderCirculation->fresh()->load('branch'))
        );
    }

    public function destroy(CylinderCirculation $cylinderCirculation, Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorizeHeadquarters($user);
        if ($cylinderCirculation->purchase_order_id !== null) {
            return $this->error('PO receipt circulation entries cannot be deleted.', 422);
        }

        $cylinderCirculation->delete();

        return $this->noContent();
    }

    /** Report: get running balance per branch per cylinder type. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeHeadquarters($user);
        $types = ['3kg', '5.5kg', '12kg', '50kg'];

        $branches = \App\Models\Branch::where('status', 'active')->orderBy('name')->get();
        $rows = [];

        foreach ($branches as $branch) {
            $row = [
                'branch_id'   => $branch->id,
                'branch_name' => $branch->name,
                'balances'    => [],
            ];

            foreach ($types as $type) {
                $debits = (int) CylinderCirculation::where('branch_id', $branch->id)
                    ->where('cylinder_type', $type)
                    ->where('direction', 'debit')
                    ->sum('quantity');

                $credits = (int) CylinderCirculation::where('branch_id', $branch->id)
                    ->where('cylinder_type', $type)
                    ->where('direction', 'kredit')
                    ->sum('quantity');

                $row['balances'][$type] = max(0, $debits - $credits);
            }

            $rows[] = $row;
        }

        return $this->success([
            'types' => $types,
            'rows'  => $rows,
        ]);
    }

    private function authorizeHeadquarters(?User $user): void
    {
        abort_unless($user?->isOwnerPusat(), 403);
    }
}
