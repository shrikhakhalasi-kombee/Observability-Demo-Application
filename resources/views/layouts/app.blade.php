<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} — {{ $title ?? 'Dashboard' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen">

<nav class="bg-indigo-700 text-white shadow">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-6">
            <span class="font-bold text-lg tracking-tight">🔭 Observability Demo</span>
            <a href="{{ route('dashboard') }}"
               class="text-sm hover:text-indigo-200 {{ request()->routeIs('dashboard') ? 'underline' : '' }}">
                Dashboard
            </a>
            <a href="{{ route('products.index') }}"
               class="text-sm hover:text-indigo-200 {{ request()->routeIs('products.index') ? 'underline' : '' }}">
                Products
            </a>
            <a href="{{ route('orders.index') }}"
               class="text-sm hover:text-indigo-200 {{ request()->routeIs('orders.index') ? 'underline' : '' }}">
                Orders
            </a>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <span class="text-indigo-200">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="bg-indigo-900 hover:bg-indigo-800 px-3 py-1 rounded">Logout</button>
            </form>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 py-8">
    {{ $slot }}
</main>

@livewireScripts
</body>
</html>
