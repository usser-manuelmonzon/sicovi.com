<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('sales.view');

        $sales = Sale::query()
            ->with(['customer', 'creator'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->input('q'));

                $query->where(function ($nested) use ($term): void {
                    $nested->where('code', 'ilike', "%{$term}%")
                        ->orWhereHas('customer', fn ($customer) =>
                            $customer->where('name', 'ilike', "%{$term}%")
                        );
                });
            })
            ->when($request->filled('state'), fn ($query) =>
                $query->where('state', $request->input('state'))
            )
            ->latest('document_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('sales.index', compact('sales'));
    }

    public function create(): View
    {
        Gate::authorize('sales.create');

        return view('sales.form', [
            'sale' => new Sale(),
            'customers' => Customer::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->where('active', true)
                ->with('unit')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('sales.create');

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'document_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'string'],
        ]);

        $sale = $this->saleService->createDraft(
            $request->user(),
            [
                'customer_id' => $validated['customer_id'] ?? null,
                'document_date' => $validated['document_date'],
                'notes' => $validated['notes'] ?? null,
            ],
            $validated['lines'] ?? [],
        );

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Borrador de venta creado correctamente.');
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('sales.view');

        $sale->load([
            'items.product.unit',
            'customer',
            'warehouse',
            'creator',
            'confirmer',
        ]);

        return view('sales.show', compact('sale'));
    }

    public function edit(Sale $sale): View
    {
        Gate::authorize('sales.update');

        abort_unless($sale->state === 'draft', 422, 'Solo se pueden editar ventas en borrador.');

        $sale->load('items');

        return view('sales.form', [
            'sale' => $sale,
            'customers' => Customer::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->where('active', true)
                ->with('unit')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.update');

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'document_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'string'],
        ]);

        $this->saleService->updateDraft(
            $sale,
            $request->user(),
            [
                'customer_id' => $validated['customer_id'] ?? null,
                'document_date' => $validated['document_date'],
                'notes' => $validated['notes'] ?? null,
            ],
            $validated['lines'],
        );

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Borrador de venta actualizado correctamente.');
    }

    public function confirm(Request $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.confirm');

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);

        $this->saleService->confirmSale(
            $sale,
            $validated['lines'],
            $request->user(),
            trim($validated['idempotency_key']),
        );

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Venta confirmada correctamente.');
    }
}
