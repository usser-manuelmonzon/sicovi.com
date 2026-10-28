<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('suppliers.view');
        $suppliers = Supplier::query()
            ->withCount('purchases')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->string('q'));
                $query->where(fn ($nested) => $nested->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%")->orWhere('tax_id', 'ilike', "%{$term}%"));
            })
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        Gate::authorize('suppliers.create');

        return view('suppliers.form', ['supplier' => new Supplier]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('suppliers.create');
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = Supplier::query()->create($this->validated($request));
            $this->audit->record($request->user(), 'suppliers.created', $supplier, [], $supplier->getAttributes());

            return $supplier;
        });

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor creado.');
    }

    public function show(Supplier $supplier): View
    {
        Gate::authorize('suppliers.view');
        $purchases = $supplier->purchases()->latest('document_date')->latest('id')->paginate(10, ['*'], 'purchases_page');

        return view('suppliers.show', compact('supplier', 'purchases'));
    }

    public function edit(Supplier $supplier): View
    {
        Gate::authorize('suppliers.update');

        return view('suppliers.form', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('suppliers.update');
        DB::transaction(function () use ($request, $supplier): void {
            $before = $supplier->getAttributes();
            $supplier->update($this->validated($request));
            $this->audit->record($request->user(), 'suppliers.updated', $supplier, $before, $supplier->getAttributes());
        });

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor actualizado.');
    }

    public function deactivate(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('suppliers.deactivate');
        DB::transaction(function () use ($supplier, $request): void {
            $before = $supplier->getAttributes();
            $supplier->update(['active' => false]);
            $this->audit->record($request->user(), 'suppliers.deactivated', $supplier, $before, $supplier->getAttributes(), 'Proveedor desactivado.');
        });

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor desactivado. El historial se conserva.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $data;
    }
}
