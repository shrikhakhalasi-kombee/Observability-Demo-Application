<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Faker\Factory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Truncate in reverse FK order for idempotency
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        OrderItem::truncate();
        Order::truncate();
        Product::withTrashed()->forceDelete();
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 10 users
        $users = User::factory()->count(10)->create();

        // 100 products with varied data
        $products = $this->createProducts(100);

        // Track remaining stock per product
        $stock = $products->pluck('stock', 'id')->toArray();

        // 1000 orders distributed across users
        $statuses = ['pending', 'completed', 'cancelled'];
        $userIds = $users->pluck('id')->toArray();

        for ($i = 0; $i < 1000; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $status = $statuses[array_rand($statuses)];

            // Pick 1–5 distinct products that still have stock
            $available = array_keys(array_filter($stock, fn ($s) => $s > 0));

            if (empty($available)) {
                // No stock left — create order with status cancelled, no items
                Order::create([
                    'user_id' => $userId,
                    'status' => 'cancelled',
                    'total_price' => '0.00',
                ]);

                continue;
            }

            $itemCount = min(rand(1, 5), count($available));
            $selectedIds = (array) array_rand(array_flip($available), $itemCount);

            $totalPrice = '0.00';
            $items = [];

            foreach ($selectedIds as $productId) {
                $product = $products->firstWhere('id', $productId);
                $maxQty = min($stock[$productId], 10);
                $quantity = rand(1, max(1, $maxQty));

                $unitPrice = $product->price;
                $lineTotal = bcmul((string) $unitPrice, (string) $quantity, 2);
                $totalPrice = bcadd($totalPrice, $lineTotal, 2);

                $items[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];

                $stock[$productId] -= $quantity;
            }

            $order = Order::create([
                'user_id' => $userId,
                'status' => $status,
                'total_price' => $totalPrice,
            ]);

            foreach ($items as $item) {
                OrderItem::create(array_merge($item, ['order_id' => $order->id]));
            }
        }

        $this->command->info('Demo data seeded: 10 users, 100 products, 1000 orders.');
    }

    private function createProducts(int $count): Collection
    {
        $faker = Factory::create();

        $categories = [
            'Widget', 'Gadget', 'Gizmo', 'Device', 'Tool',
            'Component', 'Module', 'Unit', 'Part', 'Kit',
        ];

        $adjectives = [
            'Premium', 'Standard', 'Deluxe', 'Basic', 'Advanced',
            'Professional', 'Industrial', 'Compact', 'Heavy-Duty', 'Portable',
        ];

        $products = [];
        for ($i = 0; $i < $count; $i++) {
            $adj = $adjectives[array_rand($adjectives)];
            $cat = $categories[array_rand($categories)];
            $name = "{$adj} {$cat} ".strtoupper($faker->lexify('??-###'));

            $products[] = Product::create([
                'name' => $name,
                'description' => $faker->sentence(rand(8, 20)),
                'price' => $faker->randomFloat(2, 1.00, 999.99),
                'stock' => rand(0, 500),
            ]);
        }

        return Product::whereIn('id', array_column($products, 'id'))->get();
    }
}
