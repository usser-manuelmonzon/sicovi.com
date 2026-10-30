<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
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

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'min:1', 'exists:customers,id'],
            'created_by' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'state' => ['nullable', 'in:draft,confirmed,voided,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sales = Sale::query()
            ->with(['customer', 'creator'])
            ->when(($validated['q'] ?? '') !== '', function ($query) use ($validated): void {
                $term = trim((string) $validated['q']);

                $query->where(function ($nested) use ($term): void {
                    $nested->where('code', 'ilike', "%{$term}%")
                        ->orWhereHas('customer', fn ($customer) =>
                            $customer->where('name', 'ilike', "%{$term}%")
                        );
                });
            })
            ->when(isset($validated['state']), fn ($query) =>
                $query->where('state', $validated['state'])
            )
            ->when(isset($validated['customer_id']), fn ($query) =>
                $query->where('customer_id', $validated['customer_id'])
            )
            ->when(isset($validated['created_by']), fn ($query) =>
                $query->where('created_by', $validated['created_by'])
            )
            ->when(isset($validated['from']), fn ($query) =>
                $query->whereDate('document_date', '>=', $validated['from'])
            )
            ->when(isset($validated['to']), fn ($query) =>
                $query->whereDate('document_date', '<=', $validated['to'])
            )
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'creators' => User::query()->whereIn('id', Sale::query()->select('created_by')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
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
            'voidedBy',
            'cancelledBy',
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
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);

        $this->saleService->confirmSale(
            $sale,
            $request->user(),
            trim($validated['idempotency_key']),
        );

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Venta confirmada correctamente.');
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.void');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);
        $this->saleService->voidSale(
            $sale,
            $request->user(),
            trim($validated['reason']),
            trim($validated['idempotency_key']),
        );

        return redirect()->route('sales.show', $sale)->with('success', 'Venta anulada y salida de inventario revertida.');
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.void');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->saleService->cancelDraft($sale, $request->user(), $validated['reason']);
        return redirect()->route('sales.show', $sale)->with('success', 'Borrador cancelado sin afectar el inventario.');
    }
}
