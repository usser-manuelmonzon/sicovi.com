<?php

namespace App\Http\Controllers;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\OrganizationContext;
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
        $warehouse = $this->organization->warehouse();
        $balances = DB::table('inventory_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('b.warehouse_id', $warehouse->id)
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($nested) => $nested->where('p.name', 'ilike', '%'.trim((string) $request->string('q')).'%')->orWhere('p.sku', 'ilike', '%'.trim((string) $request->string('q')).'%')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('p.category_id', $request->integer('category_id')))
            ->when($request->boolean('low_stock'), fn ($query) => $query->whereColumn('b.quantity', '<', 'p.minimum_stock'))
            ->select('b.*', 'p.name', 'p.sku', 'p.active', 'p.minimum_stock', 'u.abbreviation', 'c.name as category_name')
            ->orderBy('p.name')->paginate(20)->withQueryString();
        $movements = DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.warehouse_id', $warehouse->id)->latest('m.id')->limit(20)->get(['m.*', 'p.name', 'p.sku']);

        return view('inventory.index', ['balances' => $balances, 'movements' => $movements, 'categories' => DB::table('categories')->where('active', true)->orderBy('name')->get()]);
    }

    public function createAdjustment(): View
    {
        Gate::authorize('inventory.adjust');

        return view('inventory.adjustment', ['products' => Product::query()->where('active', true)->with('unit')->orderBy('name')->get()]);
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
        Gate::authorize('inventory.adjust');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->inventory->voidAdjustment($request->user(), $adjustment, $validated['reason']);

        return back()->with('success', 'Ajuste anulado.');
    }
}
