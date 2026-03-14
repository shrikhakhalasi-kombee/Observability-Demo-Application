<?php

namespace App\Livewire;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Livewire\Component;

class DashboardStats extends Component
{
    public function render()
    {
        return view('livewire.dashboard-stats', [
            'totalUsers' => User::count(),
            'totalProducts' => Product::count(),
            'totalOrders' => Order::count(),
            'pendingOrders' => Order::where('status', 'pending')->count(),
            'recentOrders' => Order::with('user')
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }
}
