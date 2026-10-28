<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\DecimalService;
use App\Services\OrganizationContext;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseService $purchaseService,
        private DecimalService $decimals,
        private OrganizationContext $organization,
        private AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('purchases.view');
        $purchases = Purchase::query()
            ->with('supplier')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->string('q'));
                $query->where(function ($nested) use ($term): void {
                    $nested->where('code', 'ilike', "%{$term}%")
                        ->orWhere('supplier_reference', 'ilike', "%{$term}%")
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'ilike', "%{$term}%"));
                });
            })
            ->when($request->filled('supplier_id'), fn ($query) => $query->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('document_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('document_date', '<=', $request->date('to')))
            ->latest('document_date')->latest('id')->paginate(15)->withQueryString();

        return view('purchases.index', ['purchases' => $purchases, 'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('purchases.create');

        return view('purchases.create', [
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(),
            'products' => Product::query()->where('active', true)->with('unit')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('purchases.create');
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'document_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_cost' => ['required', 'string'],
        ]);

        $purchase = DB::transaction(function () use ($validated): Purchase {
            $warehouse = $this->organization->warehouse();
            $purchase = new Purchase;
            $purchase->forceFill([
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $warehouse->id,
                'document_date' => $validated['document_date'],
                'supplier_reference' => isset($validated['reference']) && trim($validated['reference']) !== '' ? trim($validated['reference']) : null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
                'total' => '0.00',
            ])->save();

            $seen = [];
            foreach ($validated['lines'] as $line) {
                $product = Product::query()->with('unit')->whereKey($line['product_id'])->where('active', true)->firstOrFail();
                if (isset($seen[$product->id])) {
                    abort(422, 'Un producto no puede repetirse en la misma compra.');
                }
                $seen[$product->id] = true;
                try {
                    $quantity = $this->decimals->quantity($line['quantity'], (int) $product->unit->decimal_places, true);
                    $unitCost = $this->decimals->money($line['unit_cost']);
                    $lineTotal = $this->decimals->lineTotal($quantity, $unitCost);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }
                $purchase->items()->create([
                    'product_id' => $product->id,
                    'unit_id' => $product->unit_id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);
            }

            $this->audit->record(auth()->user(), 'purchases.created', $purchase, [], $purchase->getAttributes(), 'Borrador de compra creado.');

            return $purchase;
        });

        return redirect()->route('purchases.show', $purchase)->with('success', 'Borrador de compra creado.');
    }

    public function show(Purchase $purchase): View
    {
        Gate::authorize('purchases.view');
        $purchase->load(['items.product.unit', 'supplier', 'creator', 'confirmer']);

        return view('purchases.show', compact('purchase'));
    }

    public function confirm(Request $request, Purchase $purchase): RedirectResponse
    {
        Gate::authorize('purchases.confirm');
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_cost' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);

        $this->purchaseService->confirmPurchase($purchase, $validated['lines'], $request->user(), trim($validated['idempotency_key']));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra confirmada y mercadería recibida.');
    }

    public function void(Request $request, Purchase $purchase): RedirectResponse
    {
        Gate::authorize('purchases.void');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);
        $this->purchaseService->voidPurchase($purchase, $request->user(), $validated['reason'], trim($validated['idempotency_key']));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra anulada y entrada de inventario revertida.');
    }
}
