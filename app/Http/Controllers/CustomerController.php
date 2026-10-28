<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $customers,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('customers.view');

        $active = $request->filled('active')
            ? $request->boolean('active')
            : null;

        $customers = $this->customers->search(
            (string) $request->input('q', ''),
            $active,
        );

        return view('customers.index', compact('customers'));
    }

    public function create(): View
    {
        Gate::authorize('customers.create');

        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('customers.create');

        $customer = $this->customers->create(
            $request->user(),
            $this->validated($request),
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Cliente creado correctamente.');
    }

    public function show(Customer $customer): View
    {
        Gate::authorize('customers.view');

        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('customers.update');

        return view('customers.form', compact('customer'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('customers.update');

        $this->customers->update(
            $customer,
            $request->user(),
            $this->validated($request),
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Cliente actualizado correctamente.');
    }

    public function deactivate(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('customers.deactivate');

        $this->customers->deactivate($customer, $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Cliente desactivado. El historial se conserva.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'address' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $data;
    }
}