<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

class AuditService
{
    private const FIELDS = [
        User::class => ['name', 'username', 'email', 'role_id', 'active', 'is_demo', 'last_login_at', 'permission_codes'],
        Role::class => ['code', 'name', 'description', 'active', 'permission_ids', 'permission_codes'],
        Permission::class => ['code', 'name', 'group'],
        Company::class => ['trade_name', 'legal_name', 'tax_id', 'address', 'phone', 'email', 'currency', 'display_timezone'],
        Branch::class => ['company_id', 'code', 'name', 'address', 'phone', 'active'],
        Warehouse::class => ['branch_id', 'code', 'name', 'location', 'active'],
        Category::class => ['code', 'name', 'description', 'active'],
        Subcategory::class => ['category_id', 'name', 'description', 'active'],
        Brand::class => ['name', 'description', 'active'],
        Unit::class => ['code', 'name', 'abbreviation', 'decimal_places', 'active'],
        Product::class => ['sku', 'barcode', 'name', 'description', 'category_id', 'subcategory_id', 'brand_id', 'unit_id', 'presentation', 'sale_price', 'reference_cost', 'minimum_stock', 'location', 'active'],
        InventoryAdjustment::class => ['code', 'warehouse_id', 'type', 'adjustment_date', 'reason', 'reference', 'state', 'voided_by', 'void_reason'],
        Supplier::class => ['code', 'name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes', 'active'],
        Customer::class => ['code', 'name', 'tax_id', 'phone', 'email', 'address', 'active'],
        Purchase::class => ['code', 'supplier_id', 'warehouse_id', 'document_date', 'supplier_reference', 'state', 'total', 'created_by', 'confirmed_by', 'confirmed_at', 'voided_by', 'voided_at', 'void_reason'],
        Sale::class => [
    'code',
    'customer_id',
    'warehouse_id',
    'document_date',
    'state',
    'total',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'confirmation_idempotency_key',
],
SaleItem::class => [
    'sale_id',
    'product_id',
    'unit_id',
    'product_sku',
    'product_name',
    'quantity',
    'unit_price',
    'line_total',
],
    ];

    public function record(?User $actor, string $action, Model $entity, array $before = [], array $after = [], ?string $reason = null): void
    {
        $connection = $entity->getConnection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('La modificación y su auditoría deben estar dentro de la misma transacción.');
        }
        if (! isset(self::FIELDS[$entity::class]) || ! $entity->exists || trim($action) === '' || mb_strlen($action) > 100) {
            throw new InvalidArgumentException('La entidad o la acción no están habilitadas para auditoría.');
        }
        $allowed = array_flip(self::FIELDS[$entity::class]);

        $event = new AuditEvent;
        $event->setConnection($entity->getConnectionName());
        $event->forceFill([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'before_values' => $this->sanitize(array_intersect_key($before, $allowed)),
            'after_values' => $this->sanitize(array_intersect_key($after, $allowed)),
            'reason' => $reason,
            'occurred_at' => now('UTC'),
        ])->saveOrFail();
    }

    /** Reject arbitrary nested payloads even under an allowed attribute name. */
    private function sanitize(array $values): array
    {
        foreach ($values as $field => $value) {
            if (in_array($field, ['permission_ids', 'permission_codes'], true)) {
                $values[$field] = is_array($value) ? array_values(array_filter($value, fn ($item) => is_int($item) || is_string($item))) : [];
            } elseif ($value instanceof \DateTimeInterface) {
                $values[$field] = $value->format(DATE_ATOM);
            } elseif (! is_scalar($value) && $value !== null) {
                unset($values[$field]);
            }
        }

        return $values;
    }
}
