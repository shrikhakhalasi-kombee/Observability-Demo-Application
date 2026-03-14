<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Products</h2>
            <p class="text-gray-500 text-sm mt-1">Manage your product catalogue</p>
        </div>
        <button wire:click="openCreate"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            + New Product
        </button>
    </div>

    {{-- Success alert --}}
    @if ($successMessage)
        <div class="bg-green-50 border border-green-300 text-green-700 rounded-lg px-4 py-3 mb-4 flex justify-between items-center text-sm">
            {{ $successMessage }}
            <button wire:click="$set('successMessage', null)" class="text-green-500 hover:text-green-700">✕</button>
        </div>
    @endif

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name or description…"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm flex-1 min-w-48 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <input wire:model.live="minPrice" type="number" placeholder="Min price" min="0" step="0.01"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-32 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <input wire:model.live="maxPrice" type="number" placeholder="Max price" min="0" step="0.01"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-32 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <button wire:click="$set('search',''); $set('minPrice',''); $set('maxPrice','')"
                class="text-sm text-gray-500 hover:text-gray-700 px-2">Clear</button>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3 text-left">Name</th>
                    <th class="px-6 py-3 text-left">Description</th>
                    <th class="px-6 py-3 text-right">Price</th>
                    <th class="px-6 py-3 text-right">Stock</th>
                    <th class="px-6 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 font-medium text-gray-800">{{ $product->name }}</td>
                        <td class="px-6 py-3 text-gray-500 max-w-xs truncate">{{ $product->description ?? '—' }}</td>
                        <td class="px-6 py-3 text-right">${{ number_format($product->price, 2) }}</td>
                        <td class="px-6 py-3 text-right">
                            <span class="{{ $product->stock < 10 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                                {{ $product->stock }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-center space-x-2">
                            <button wire:click="openEdit({{ $product->id }})"
                                    class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Edit</button>
                            <button wire:click="confirmDelete({{ $product->id }})"
                                    class="text-red-500 hover:text-red-700 text-xs font-medium">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-gray-400">
                            No products found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($products->hasPages())
            <div class="px-6 py-4 border-t border-gray-100">
                {{ $products->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Modal --}}
    @if ($showModal)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6" wire:click.stop>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">
                        {{ $editingId ? 'Edit Product' : 'New Product' }}
                    </h3>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                        <input wire:model="name" type="text"
                               class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400
                                      {{ $errors->has('name') ? 'border-red-400' : 'border-gray-300' }}">
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea wire:model="description" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                            <input wire:model="price" type="number" min="0" step="0.01"
                                   class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400
                                          {{ $errors->has('price') ? 'border-red-400' : 'border-gray-300' }}">
                            @error('price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Stock *</label>
                            <input wire:model="stock" type="number" min="0"
                                   class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400
                                          {{ $errors->has('stock') ? 'border-red-400' : 'border-gray-300' }}">
                            @error('stock') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" wire:click="closeModal"
                                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium">
                            {{ $editingId ? 'Update' : 'Create' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Delete Confirm Modal --}}
    @if ($showConfirm)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Delete Product?</h3>
                <p class="text-sm text-gray-500 mb-6">This action cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button wire:click="closeModal"
                            class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-600 hover:text-gray-800">
                        Cancel
                    </button>
                    <button wire:click="delete"
                            class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
