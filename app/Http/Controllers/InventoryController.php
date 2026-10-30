<?php

namespace App\Http\Controllers;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function __construct(private OrganizationContext $organization, private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'low_stock' => ['nullable', 'in:0,1'],
        ]);
        $warehouse = $this->organization->warehouse();
        $balances = DB::table('inventory_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('b.warehouse_id', $warehouse->id)
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('p.name', 'ilike', '%'.trim((string) $filters['q']).'%')->orWhere('p.sku', 'ilike', '%'.trim((string) $filters['q']).'%')))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('p.category_id', $filters['category_id']))
            ->when(($filters['low_stock'] ?? '0') === '1', fn ($query) => $query->whereColumn('b.quantity', '<', 'p.minimum_stock'))
            ->select('b.*', 'p.name', 'p.sku', 'p.active', 'p.minimum_stock', 'u.abbreviation', 'c.name as category_name')
            ->orderBy('p.name')->orderBy('p.id')->paginate(20)->withQueryString();
        $movements = DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.warehouse_id', $warehouse->id)->latest('m.id')->limit(20)->get(['m.*', 'p.name', 'p.sku']);

        return view('inventory.index', ['balances' => $balances, 'movements' => $movements, 'categories' => DB::table('categories')->where('active', true)->orderBy('name')->get()]);
    }

    public function createAdjustment(): View
    {
        Gate::authorize('inventory.adjust');

        return view('inventory.adjustment', ['products' => Product::query()->where('active', true)->with('unit')->orderBy('name')->get()]);
    }

    public function adjustments(Request $request): View
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:opening,extraordinary_entry,count_adjustment'],
            'state' => ['nullable', 'in:applied,voided'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $warehouse = $this->organization->warehouse();
        $adjustments = InventoryAdjustment::query()
            ->with('creator')
            ->where('warehouse_id', $warehouse->id)
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where(function ($nested) use ($filters): void {
                $value = '%'.trim((string) $filters['q']).'%';
                $nested->where('code', 'ilike', $value)->orWhere('reason', 'ilike', $value);
            }))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['state']), fn ($query) => $query->where('state', $filters['state']))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('adjustment_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('adjustment_date', '<=', $filters['date_to']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.adjustments', compact('adjustments'));
    }

    public function showAdjustment(InventoryAdjustment $adjustment): View
    {
        Gate::authorize('inventory.view');
        $adjustment = $this->adjustmentForCurrentWarehouse($adjustment);

        return view('inventory.adjustment-show', compact('adjustment'));
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        Gate::authorize('inventory.adjust');
        $validated = $request->validate([
            'type' => ['required', 'in:opening,extraordinary_entry,count_adjustment'],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string'],
            'lines.*.counted_quantity' => ['nullable', 'string'],
        ]);
        $adjustment = $this->inventory->applyAdjustment($request->user(), $validated);

        return redirect()->route('inventory.index')->with('success', "Ajuste {$adjustment->code} aplicado.");
    }

    public function voidAdjustment(Request $request, InventoryAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('inventory.void_adjustment');
        $adjustment = $this->adjustmentForCurrentWarehouse($adjustment);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->inventory->voidAdjustment($request->user(), $adjustment, $validated['reason']);

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', 'Ajuste anulado.');
    }

    private function adjustmentForCurrentWarehouse(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $warehouseId = $this->organization->warehouse()->id;
        if ((int) $adjustment->warehouse_id !== (int) $warehouseId) {
            throw (new ModelNotFoundException)->setModel(InventoryAdjustment::class, [$adjustment->id]);
        }

        return $adjustment->load(['creator', 'voidedBy', 'items.product.unit']);
    }
}
