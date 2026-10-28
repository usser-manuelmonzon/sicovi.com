<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        private AuditService $audit,
    ) {}

    public function search(string $term = '', ?bool $active = null)
    {
        return Customer::query()
            ->when(trim($term) !== '', function (Builder $query) use ($term): void {
                $value = trim($term);

                $query->where(function (Builder $nested) use ($value): void {
                    $nested->where('name', 'ilike', "%{$value}%")
                        ->orWhere('code', 'ilike', "%{$value}%")
                        ->orWhere('tax_id', 'ilike', "%{$value}%")
                        ->orWhere('phone', 'ilike', "%{$value}%")
                        ->orWhere('email', 'ilike', "%{$value}%");
                });
            })
            ->when($active !== null, fn (Builder $query) => $query->where('active', $active))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }


public function create(User $actor, array $data): Customer
{
    $data = $this->normalize($data);

    try {
        return DB::transaction(function () use ($actor, $data): Customer {
            $this->assertTaxIdAvailable($data['tax_id']);

            $customer = Customer::query()->create($data);

            $this->audit->record(
                $actor,
                'customers.created',
                $customer,
                [],
                $customer->getAttributes(),
            );

            return $customer;
        });
    } catch (QueryException $e) {
        $this->throwIfDuplicateTaxId($e);
        throw $e;
    }
}

public function update(Customer $customer, User $actor, array $data): Customer
{
    $data = $this->normalize($data);

    try {
        return DB::transaction(function () use ($customer, $actor, $data): Customer {
            $locked = Customer::query()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTaxIdAvailable($data['tax_id'], $locked->id);

            $before = $locked->getAttributes();
            $locked->update($data);

            $this->audit->record(
                $actor,
                'customers.updated',
                $locked,
                $before,
                $locked->getAttributes(),
            );

            return $locked->refresh();
        });
    } catch (QueryException $e) {
        $this->throwIfDuplicateTaxId($e);
        throw $e;
    }
}

/**
 * Converts only the normalized NIT unique-index violation into a
 * user-friendly validation error. Other database errors are rethrown.
 */
private function throwIfDuplicateTaxId(QueryException $e): void
{
    $isDuplicateTaxId = ($e->errorInfo[0] ?? null) === '23505'
        && str_contains(
            $e->getMessage(),
            'customers_tax_id_normalized_unique'
        );

    if ($isDuplicateTaxId) {
        throw ValidationException::withMessages([
            'tax_id' => 'Ya existe un cliente con ese NIT.',
        ]);
    }
}


    public function deactivate(Customer $customer, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $locked = Customer::query()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->active) {
                return $locked;
            }

            $before = $locked->getAttributes();
            $locked->update(['active' => false]);

            $this->audit->record(
                $actor,
                'customers.deactivated',
                $locked,
                $before,
                $locked->getAttributes(),
                'Cliente desactivado.',
            );

            return $locked->refresh();
        });
    }


private function normalize(array $data): array
{
    $data['name'] = preg_replace(
        '/\s+/u',
        ' ',
        trim((string) ($data['name'] ?? ''))
    );

    if ($data['name'] === '') {
        throw ValidationException::withMessages([
            'name' => 'El nombre del cliente es obligatorio.',
        ]);
    }

    foreach (['tax_id', 'phone', 'email', 'address'] as $field) {
        $value = trim((string) ($data[$field] ?? ''));
        $data[$field] = $value === '' ? null : $value;
    }

    return $data;
}



private function assertTaxIdAvailable(
    ?string $taxId,
    ?int $exceptId = null
): void {
    if ($taxId === null || trim($taxId) === '') {
        return;
    }

    $normalized = strtolower(
        preg_replace('/[\s-]+/u', '', trim($taxId))
    );

    $query = Customer::query()
        ->whereNotNull('tax_id')
        ->whereRaw(
            "lower(regexp_replace(btrim(tax_id), '[\\s-]+', '', 'g')) = ?",
            [$normalized],
        );

    if ($exceptId !== null) {
        $query->whereKeyNot($exceptId);
    }

    if ($query->exists()) {
        throw ValidationException::withMessages([
            'tax_id' => 'Ya existe un cliente con ese NIT.',
        ]);
    }
}
}
