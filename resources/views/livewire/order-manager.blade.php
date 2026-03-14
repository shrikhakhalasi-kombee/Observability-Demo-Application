<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Orders</h2>
            <p class="text-gray-500 text-sm mt-1">Your order history</p>
        </div>
        <button wire:click="openCreate"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            + New Order
        </button>
    </div>

    {{-- Alerts --}}
    @if ($successMessage)
        <div class="bg-green-50 border border-green-300 text-green-700 rounded-lg px-4 py-3 mb-4 flex justify-between items-center text-sm">
            {{ $successMessage }}
            <button wire:click="$set('successMessage', null)">✕</button>
        </div>
    @endif

    @if ($errorMessage)
        <div class="bg-red-50 border border-red-300 text-red-700 rounded-lg px-4 py-3 mb-4 flex justify-between items-center text-sm">
            {{ $errorMessage }}
            <button wire:click="$set('errorMessage', null)">✕</button>
        </div>
    @endif

    {{-- Filter --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4 flex gap-3 items-center">
        <label class="text-sm text-gray-600 font-medium">Status:</label>
        <select wire:model.live="statusFilter"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3 text-left">Order ID</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-right">Total</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-500 font-mono">#{{ $order->id }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $order->status === 'completed' ? 'bg-green-100 text-green-700' :
                                   ($order->status === 'cancelled' ? 'bg-red-100 text-red-700' :
                                   'bg-yellow-100 text-yellow-700') }}">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right font-medium">${{ number_format($order->total_price, 2) }}</td>
                        <td class="px-6 py-3 text-gray-400">{{ $order->created_at->format('M d, Y H:i') }}</td>
                        <td class="px-6 py-3 text-center">
                            <button wire:click="viewDetail({{ $order->id }})"
                                    class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">
                                View
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-gray-400">No orders found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($orders->hasPages())
            <div class="px-6 py-4 border-t border-gray-100">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    {{-- Create Order Modal --}}
    @if ($showModal)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" wire:click.stop>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">New Order</h3>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
                </div>

                @if ($errorMessage)
                    <div class="bg-red-50 border border-red-300 text-red-700 rounded p-3 mb-4 text-sm">
                        {{ $errorMessage }}
                    </div>
                @endif

                <form wire:submit="placeOrder" class="space-y-4">
                    @foreach ($items as $i => $item)
                        <div class="flex gap-3 items-start">
                            <div class="flex-1">
                                <label class="block text-xs text-gray-500 mb-1">Product</label>
                                <select wire:model="items.{{ $i }}.product_id"
                                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400
                                               {{ $errors->has("items.$i.product_id") ? 'border-red-400' : 'border-gray-300' }}">
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->name }} — ${{ number_format($product->price, 2) }}
                                            ({{ $product->stock }} in stock)
                                        </option>
                                    @endforeach
                                </select>
                                @error("items.$i.product_id")
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="w-24">
                                <label class="block text-xs text-gray-500 mb-1">Qty</label>
                                <input wire:model="items.{{ $i }}.quantity" type="number" min="1"
                                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400
                                              {{ $errors->has("items.$i.quantity") ? 'border-red-400' : 'border-gray-300' }}">
                            </div>
                            @if (count($items) > 1)
                                <button type="button" wire:click="removeItem({{ $i }})"
                                        class="mt-5 text-red-400 hover:text-red-600 text-lg">✕</button>
                            @endif
                        </div>
                    @endforeach

                    <button type="button" wire:click="addItem"
                            class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                        + Add item
                    </button>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" wire:click="closeModal"
                                class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-600">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium">
                            Place Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Order Detail Modal --}}
    @if ($showDetail && $detailOrder)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6" wire:click.stop>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Order #{{ $detailOrder->id }}</h3>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
                </div>

                <div class="flex gap-4 mb-4 text-sm">
                    <div>
                        <span class="text-gray-500">Status:</span>
                        <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $detailOrder->status === 'completed' ? 'bg-green-100 text-green-700' :
                               ($detailOrder->status === 'cancelled' ? 'bg-red-100 text-red-700' :
                               'bg-yellow-100 text-yellow-700') }}">
                            {{ ucfirst($detailOrder->status) }}
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500">Date:</span>
                        <span class="ml-1">{{ $detailOrder->created_at->format('M d, Y H:i') }}</span>
                    </div>
                </div>

                <table class="w-full text-sm mb-4">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Product</th>
                            <th class="px-4 py-2 text-right">Qty</th>
                            <th class="px-4 py-2 text-right">Unit Price</th>
                            <th class="px-4 py-2 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($detailOrder->orderItems as $item)
                            <tr>
                                <td class="px-4 py-2">{{ $item->product->name ?? 'Deleted' }}</td>
                                <td class="px-4 py-2 text-right">{{ $item->quantity }}</td>
                                <td class="px-4 py-2 text-right">${{ number_format($item->unit_price, 2) }}</td>
                                <td class="px-4 py-2 text-right">${{ number_format($item->quantity * $item->unit_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="3" class="px-4 py-2 text-right text-gray-700">Total</td>
                            <td class="px-4 py-2 text-right">${{ number_format($detailOrder->total_price, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="flex justify-end">
                    <button wire:click="closeModal"
                            class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-600 hover:text-gray-800">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
