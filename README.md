# Arka Inventory

A multi-tenant inventory management API built with Laravel and MySQL.

Companies share the same database schema, while tenant-owned records are isolated using `tenant_id`.

## Requirements

- PHP and extensions compatible with `composer.json`
- Composer
- MySQL with InnoDB tables

## Setup

```bash
git clone https://github.com/elnazbatshi/arka_inventory.git
cd arka_inventory
composer install
```

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Create a MySQL database named `arka_inventory` and configure `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=arka_inventory
DB_USERNAME=root
DB_PASSWORD=
```

Use credentials appropriate for your local MySQL installation.

```bash
php artisan key:generate
php artisan migrate
php artisan db:seed --class=InventoryDemoSeeder
php artisan serve
```

The demo seeder prints tenant IDs. Use those IDs when sending API requests.

## Multi-Tenancy

Each API request must include:

```http
X-Tenant-ID: 3
Accept: application/json
```

Replace `3` with an existing tenant ID.

`ResolveTenant` validates the header and initializes `TenantContext`.
The context is cleared after the request.

The `BelongsToTenant` trait registers `TenantScope`, which automatically filters Eloquent queries by the current tenant.

The trait also assigns tenant ownership during creation and prevents changing ownership or saving/deleting another tenant's model.

Query Builder operations do not apply Eloquent scopes or model events. Service queries therefore explicitly include tenant filters.

Tenant selection is not authentication. The assessment implementation validates tenant existence; production authorization must additionally verify that the authenticated user belongs to the selected tenant.

## Database Structure

- `tenants`: companies.
- `products`: tenant-owned product definitions.
- `warehouses`: tenant-owned warehouses.
- `stock_movements`: recorded incoming, outgoing and transfer movements.
- `stock_levels`: current quantity per product and warehouse.

Unique constraints:

- `products (tenant_id, sku)`
- `stock_levels (tenant_id, product_id, warehouse_id)`

Product definitions must exist before recording movements.
An `in` movement increases inventory for an existing product and creates its stock-level record when necessary.

## API

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/stock-movements` | Record a movement |
| GET | `/api/stock-levels` | List stock levels |
| GET | `/api/products/{sku}/history` | List movement history |

Input is validated with Form Requests. Successful responses are formatted using API Resources.

### Record Incoming Stock

```json
{
  "sku": "SKU-001",
  "type": "in",
  "warehouse_id": 1,
  "quantity": 10,
  "reference": "RECEIPT-001"
}
```

### Record Outgoing Stock

```json
{
  "sku": "SKU-001",
  "type": "out",
  "warehouse_id": 1,
  "quantity": 3,
  "reference": "ISSUE-001"
}
```

### Transfer Stock

```json
{
  "sku": "SKU-001",
  "type": "transfer",
  "warehouse_id": 1,
  "to_warehouse_id": 2,
  "quantity": 4,
  "reference": "TRANSFER-001"
}
```

Use product SKUs and warehouse IDs belonging to the selected tenant.
For transfers, `warehouse_id` identifies the source.

Transfer metadata includes `from_warehouse_id` and `to_warehouse_id`.

Successful creation returns HTTP `201`.
Insufficient stock returns HTTP `409`:

```json
{
  "message": "موجودی انبار برای این عملیات کافی نیست.",
  "code": "INSUFFICIENT_STOCK"
}
```

### List Stock Levels

```http
GET /api/stock-levels?product_id=3&warehouse_id=1&per_page=20
```

Supported parameters:

- `product_id`
- `warehouse_id`
- `per_page`: 1–100
- `page`

### Product History

```http
GET /api/products/SKU-001/history?per_page=20
```

Optional filters: `type` and `warehouse_id`.

Warehouse filtering includes transfers where the warehouse is either the source or destination.

## Transactions and Concurrency

`StockMovementService` performs movement creation and stock updates in one database transaction.

A product row is locked using `SELECT ... FOR UPDATE`. This serializes movements for the same product and coordinates creation of initially missing stock-level rows.

Affected stock-level rows are also locked for update. Warehouse IDs are sorted before locking to maintain a consistent lock order.

Outgoing and transfer movements throw `InsufficientStockException`, a domain exception, when stock is insufficient. The transaction rolls back.

Transfers decrement the source and increment the destination within the same transaction.

Transactions allow up to three attempts for retryable concurrency errors.

Tradeoff: the product lock also serializes movements for that product across different warehouses. All inventory writers must follow the same locking protocol.

## Reconciliation

```bash
php artisan inventory:reconcile 3
```

The command:

1. Recomputes quantities from recorded movements.
2. Compares them with current stock levels.
3. Logs discrepancies with tenant, product, warehouse, expected and actual quantities.

Incoming movements add quantity, outgoing movements subtract quantity, and transfers subtract from the source and add to the destination.

The calculation and comparison use one SQL statement to read a consistent view of InnoDB data under normal consistent-read isolation.

The command reports discrepancies without modifying inventory. Repeated runs against unchanged data produce the same results; each run logs detected discrepancies again.

This reporting-only behavior follows the assessment's comparison and logging requirements.

## Indexing Strategy

Stock-level indexes:

- `(tenant_id, id)` supports tenant-filtered listing ordered by ID.
- `(tenant_id, product_id, id)` supports product filtering.
- `(tenant_id, warehouse_id, id)` supports warehouse filtering.
- Unique `(tenant_id, product_id, warehouse_id)` supports exact stock-level lookup and prevents duplicates.

Other indexes:

- Unique products `(tenant_id, sku)` supports tenant-scoped SKU lookup.
- Stock movements `(tenant_id, product_id, id)` supports product history ordered by ID.

### Performance Verification Status

The requirement of a response below 200 ms with 1,000,000 stock-level rows for one tenant has not yet been benchmarked.

Indexes alone do not establish compliance. Measurement must include pagination count queries, relationship loading and response serialization.

## Testing

Create a separate MySQL database named `arka_inventory_test`.

Copy `.env` to `.env.testing` and configure:

```dotenv
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=arka_inventory_test
DB_USERNAME=root
DB_PASSWORD=
DB_URL=

CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
```

```bash
php artisan key:generate --env=testing
php artisan config:clear
php artisan migrate --env=testing
```

### Movement Tests

```bash
php artisan test --filter=StockMovementTest
```

Coverage includes:

- Incoming stock.
- Outgoing stock.
- Insufficient stock rejection.
- Successful transfer.
- Failed transfer rollback.
- Rejection of another tenant's warehouse.

### Concurrency Test

```bash
php artisan test --filter=StockMovementConcurrencyTest
```

The test starts with 50 units and runs 100 outgoing movements of quantity 1 using Laravel's `Concurrency` process driver.

All child processes connect to the same test database.

Assertions verify:

- Exactly 50 successful operations.
- Exactly 50 insufficient-stock failures.
- Final stock of 0.
- Exactly 50 recorded outgoing movements.

Unexpected exceptions fail the test.

This test does not use `DatabaseTransactions` or `RefreshDatabase`, because child processes require committed shared fixtures. Its tenant-specific fixtures are removed after execution.

Implementation note: the current test uses Laravel Concurrency rather than queue workers or the Parallel Testing runner. This tooling choice still requires alignment with the assessment's explicit queue/parallel-testing requirement.

## Remaining Verification

- Automated reconciliation tests.
- Stock-level and product-history endpoint tests.
- Read isolation tests for tenant-scoped endpoints.
- Performance benchmark with 1,000,000 stock-level rows.
- Final alignment of concurrency test tooling with the assessment.
