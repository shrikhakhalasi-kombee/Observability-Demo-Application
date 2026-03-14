<div>
    {{-- Stat cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-indigo-500">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Users</p>
            <p class="text-3xl font-bold text-gray-800 mt-1">{{ $totalUsers }}</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-green-500">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Products</p>
            <p class="text-3xl font-bold text-gray-800 mt-1">{{ $totalProducts }}</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-yellow-500">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Total Orders</p>
            <p class="text-3xl font-bold text-gray-800 mt-1">{{ $totalOrders }}</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-red-500">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Pending Orders</p>
            <p class="text-3xl font-bold text-gray-800 mt-1">{{ $pendingOrders }}</p>
        </div>
    </div>

    {{-- Recent orders table --}}
    <div class="bg-white rounded-xl shadow">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-700">Recent Orders</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3 text-left">ID</th>
                    <th class="px-6 py-3 text-left">User</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-right">Total</th>
                    <th class="px-6 py-3 text-left">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($recentOrders as $order)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-500">#{{ $order->id }}</td>
                        <td class="px-6 py-3">{{ $order->user->name }}</td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $order->status === 'completed' ? 'bg-green-100 text-green-700' :
                                   ($order->status === 'cancelled' ? 'bg-red-100 text-red-700' :
                                   'bg-yellow-100 text-yellow-700') }}">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right font-medium">${{ number_format($order->total_price, 2) }}</td>
                        <td class="px-6 py-3 text-gray-400">{{ $order->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-6 text-center text-gray-400">No orders yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
