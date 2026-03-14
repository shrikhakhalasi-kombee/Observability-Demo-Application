<?php

namespace App\Livewire;

use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class OrderManager extends Component
{
    use WithPagination;

    // Filters
    public string $statusFilter = '';

    // Create order modal
    public bool $showModal = false;

    public array $items = [['product_id' => '', 'quantity' => 1]];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // Detail modal
    public bool $showDetail = false;

    public ?int $detailOrderId = null;

    protected function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function addItem(): void
    {
        $this->items[] = ['product_id' => '', 'quantity' => 1];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function openCreate(): void
    {
        $this->items = [['product_id' => '', 'quantity' => 1]];
        $this->errorMessage = null;
        $this->showModal = true;
    }

    public function placeOrder(OrderService $orderService): void
    {
        $this->validate();

        try {
            $orderService->create(
                items: array_map(fn ($i) => [
                    'product_id' => (int) $i['product_id'],
                    'quantity' => (int) $i['quantity'],
                ], $this->items),
                userId: auth()->id(),
            );

            $this->showModal = false;
            $this->successMessage = 'Order placed successfully.';
            $this->items = [['product_id' => '', 'quantity' => 1]];
        } catch (ValidationException $e) {
            $this->errorMessage = collect($e->errors())->flatten()->first();
        } catch (\Throwable) {
            $this->errorMessage = 'Could not place order. Please try again.';
        }
    }

    public function viewDetail(int $id): void
    {
        $this->detailOrderId = $id;
        $this->showDetail = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showDetail = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Order::with('user')
            ->where('user_id', auth()->id());

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $detailOrder = $this->detailOrderId
            ? Order::with('orderItems.product')->find($this->detailOrderId)
            : null;

        return view('livewire.order-manager', [
            'orders' => $query->latest()->paginate(10),
            'products' => Product::orderBy('name')->get(['id', 'name', 'price', 'stock']),
            'detailOrder' => $detailOrder,
        ]);
    }
}
