<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockCloseResource;
use App\Http\Resources\StockItemResource;
use App\Http\Resources\StockMutationResource;
use App\Http\Traits\ApiResponse;
use App\Models\StockClose;
use App\Models\StockItem;
use App\Models\StockMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ApiResponse;

    // ── Stock Items ───────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = StockItem::query()->with('branch');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }

        $stock = $query->orderBy('branch_id')->orderBy('cylinder_type')->get();

        return $this->success(StockItemResource::collection($stock));
    }

    // ── Stock Mutations ───────────────────────────────────────

    public function mutations(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = StockMutation::query()->with(['branch', 'createdBy'])->orderByDesc('mutation_date');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('mutation_type')) {
            $query->where('mutation_type', $request->mutation_type);
        }
        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('from')) {
            $query->whereDate('mutation_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('mutation_date', '<=', $request->until);
        }

        return $this->paginated($query->paginate(50));
    }

    public function storeMutation(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'cylinder_type'       => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'mutation_type'       => ['required', 'in:in,out,transfer,adjustment'],
            'quantity'            => ['required', 'integer', 'min:1'],
            'mutation_date'       => ['required', 'date'],
            'branch_id'           => ['nullable', 'exists:branches,id'],
            'source_branch_id'    => ['nullable', 'exists:branches,id'],
            'destination_branch_id' => ['nullable', 'exists:branches,id'],
            'reference_no'        => ['nullable', 'string', 'max:100'],
            'notes'               => ['nullable', 'string'],
        ]);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $data['branch_id'] = $user->branch_id;
        }
        $data['created_by'] = $user->id;

        $mutation = StockMutation::create($data);

        return $this->created(new StockMutationResource($mutation->load('branch', 'createdBy')));
    }

    // ── Stock Close ───────────────────────────────────────────

    public function closes(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = StockClose::query()->with(['branch', 'submittedBy'])->orderByDesc('close_date');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('close_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('close_date', '<=', $request->until);
        }

        return $this->paginated($query->paginate(50));
    }

    public function storeClose(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'cylinder_type' => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'close_date'    => ['required', 'date'],
            'qty_full'      => ['required', 'integer', 'min:0'],
            'qty_empty'     => ['required', 'integer', 'min:0'],
            'qty_damaged'   => ['required', 'integer', 'min:0'],
            'notes'         => ['nullable', 'string'],
        ]);

        $branchId = $user->isOwnerPusat() || $user->isRegionalLeader()
            ? ($request->branch_id ?? $user->branch_id)
            : $user->branch_id;

        $close = StockClose::updateOrCreate(
            ['branch_id' => $branchId, 'close_date' => $data['close_date'], 'cylinder_type' => $data['cylinder_type']],
            array_merge($data, [
                'branch_id'    => $branchId,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status'       => 'submitted',
            ])
        );

        return $this->created(new StockCloseResource($close->load('branch', 'submittedBy')));
    }

    public function isTodayClosed(Request $request): JsonResponse
    {
        $user     = $request->user();
        $branchId = $user->branch_id ?? $request->integer('branch_id');

        return $this->success([
            'branch_id'    => $branchId,
            'date'         => today()->toDateString(),
            'is_submitted' => StockClose::isTodaySubmitted($branchId),
        ]);
    }
}
