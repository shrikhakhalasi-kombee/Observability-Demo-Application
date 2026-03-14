<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Component;
use Livewire\WithPagination;

class ProductManager extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $minPrice = '';

    public string $maxPrice = '';

    // Modal state
    public bool $showModal = false;

    public bool $showConfirm = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    // Form fields
    public string $name = '';

    public string $description = '';

    public string $price = '';

    public string $stock = '';

    // Alert
    public ?string $successMessage = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingMinPrice(): void
    {
        $this->resetPage();
    }

    public function updatingMaxPrice(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->editingId = $id;
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->price = (string) $product->price;
        $this->stock = (string) $product->stock;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId) {
            Product::findOrFail($this->editingId)->update([
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price,
                'stock' => $this->stock,
            ]);
            $this->successMessage = 'Product updated successfully.';
        } else {
            Product::create([
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price,
                'stock' => $this->stock,
            ]);
            $this->successMessage = 'Product created successfully.';
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showConfirm = true;
    }

    public function delete(): void
    {
        if ($this->deletingId) {
            Product::findOrFail($this->deletingId)->delete();
            $this->successMessage = 'Product deleted.';
        }
        $this->showConfirm = false;
        $this->deletingId = null;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showConfirm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->description = '';
        $this->price = '';
        $this->stock = '';
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Product::query();

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(fn ($q) => $q->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term));
        }
        if ($this->minPrice !== '') {
            $query->where('price', '>=', $this->minPrice);
        }
        if ($this->maxPrice !== '') {
            $query->where('price', '<=', $this->maxPrice);
        }

        return view('livewire.product-manager', [
            'products' => $query->latest()->paginate(10),
        ]);
    }
}
