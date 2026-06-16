<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_NAME = 'customer_orders_number_format_check';

    public function up(): void
    {
        $this->ensureCustomerOrdersTable();
        $this->ensureCustomerOrderItemsTable();
        $this->ensureCustomerOrderHistoryEventsTable();
        $this->ensureNumberCheckConstraint();
    }

    public function down(): void
    {
        //
    }

    private function ensureCustomerOrdersTable(): void
    {
        if (! Schema::hasTable('customer_orders')) {
            Schema::create('customer_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('number')->unique();
                $table->string('status')->default('processing')->index();
                $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
                $table->string('client_phone')->nullable()->index();
                $table->string('client_first_name')->nullable();
                $table->string('client_last_name')->nullable();
                $table->string('delivery_method')->nullable();
                $table->text('note')->nullable();
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->string('payment_type')->nullable();
                $table->decimal('payment_received_amount', 14, 2)->nullable();
                $table->decimal('payment_received_amount_uah', 14, 2)->nullable();
                $table->decimal('paid_cash_uah', 14, 2)->default(0);
                $table->decimal('paid_cash_usd', 14, 2)->default(0);
                $table->decimal('paid_bank_tov_uah', 14, 2)->default(0);
                $table->decimal('paid_bank_fop_uah', 14, 2)->default(0);
                $table->decimal('paid_amount_uah', 14, 2)->default(0);
                $table->timestamp('payment_confirmed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            return;
        }

        Schema::table('customer_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_orders', 'delivery_method')) {
                $table->string('delivery_method')->nullable()->after('client_last_name');
            }

            if (! Schema::hasColumn('customer_orders', 'payment_type')) {
                $table->string('payment_type')->nullable()->after('currency');
            }

            if (! Schema::hasColumn('customer_orders', 'payment_received_amount')) {
                $table->decimal('payment_received_amount', 14, 2)->nullable()->after('payment_type');
            }

            if (! Schema::hasColumn('customer_orders', 'payment_received_amount_uah')) {
                $table->decimal('payment_received_amount_uah', 14, 2)->nullable()->after('payment_received_amount');
            }

            foreach ([
                'paid_cash_uah',
                'paid_cash_usd',
                'paid_bank_tov_uah',
                'paid_bank_fop_uah',
                'paid_amount_uah',
            ] as $column) {
                if (! Schema::hasColumn('customer_orders', $column)) {
                    $table->decimal($column, 14, 2)->default(0);
                }
            }

            if (! Schema::hasColumn('customer_orders', 'payment_confirmed_at')) {
                $table->timestamp('payment_confirmed_at')->nullable()->after('paid_amount_uah');
            }
        });
    }

    private function ensureCustomerOrderItemsTable(): void
    {
        if (! Schema::hasTable('customer_order_items')) {
            Schema::create('customer_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('part_catalog_item_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('part_number')->nullable()->index();
                $table->string('code')->nullable()->index();
                $table->string('donor_vin')->nullable()->index();
                $table->text('category')->nullable();
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('total_price', 14, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->decimal('unit_price_usd_hint', 14, 2)->nullable();
                $table->decimal('total_price_usd_hint', 14, 2)->nullable();
                $table->decimal('usd_exchange_rate', 14, 6)->nullable();
                $table->decimal('catalog_original_price_amount', 14, 2)->nullable();
                $table->string('catalog_original_currency', 3)->nullable();
                $table->boolean('catalog_price_snapshot_taken')->default(false);
                $table->string('source_url')->nullable();
                $table->string('image_url')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_order_items', 'unit_price_usd_hint')) {
                $table->decimal('unit_price_usd_hint', 14, 2)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('customer_order_items', 'total_price_usd_hint')) {
                $table->decimal('total_price_usd_hint', 14, 2)->nullable()->after('unit_price_usd_hint');
            }

            if (! Schema::hasColumn('customer_order_items', 'usd_exchange_rate')) {
                $table->decimal('usd_exchange_rate', 14, 6)->nullable()->after('total_price_usd_hint');
            }

            if (! Schema::hasColumn('customer_order_items', 'catalog_original_price_amount')) {
                $table->decimal('catalog_original_price_amount', 14, 2)->nullable()->after('usd_exchange_rate');
            }

            if (! Schema::hasColumn('customer_order_items', 'catalog_original_currency')) {
                $table->string('catalog_original_currency', 3)->nullable()->after('catalog_original_price_amount');
            }

            if (! Schema::hasColumn('customer_order_items', 'catalog_price_snapshot_taken')) {
                $table->boolean('catalog_price_snapshot_taken')->default(false)->after('catalog_original_currency');
            }
        });
    }

    private function ensureCustomerOrderHistoryEventsTable(): void
    {
        if (Schema::hasTable('customer_order_history_events')) {
            return;
        }

        Schema::create('customer_order_history_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['customer_order_id', 'created_at']);
        });
    }

    private function ensureNumberCheckConstraint(): void
    {
        if (DB::getDriverName() !== 'mysql' || $this->checkConstraintExists()) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `customer_orders` ADD CONSTRAINT `%s` CHECK (`number` REGEXP %s)',
            self::CHECK_NAME,
            DB::getPdo()->quote('^ORD-[0-9]{8}-[0-9]{4}$'),
        ));
    }

    private function checkConstraintExists(): bool
    {
        return DB::table('information_schema.check_constraints')
            ->where('CONSTRAINT_SCHEMA', DB::raw('DATABASE()'))
            ->where('CONSTRAINT_NAME', self::CHECK_NAME)
            ->exists();
    }
};
