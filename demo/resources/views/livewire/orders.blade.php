<?php

use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use LiveQuery\Concerns\InteractsWithLiveQueries;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    use InteractsWithLiveQueries;

    protected function liveQuerySubscriptions(): array
    {
        return ['orders' => (string) auth()->user()->tenant_id];
    }

    #[Computed]
    public function orders()
    {
        Gate::authorize('viewTenantOrders', (string) auth()->user()->tenant_id);

        return Order::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function completedOrders()
    {
        Gate::authorize('viewTenantOrders', (string) auth()->user()->tenant_id);

        return Order::query()->where('tenant_id', auth()->user()->tenant_id)->where('status', 'completed')->orderBy('id')->get();
    }

    #[Computed]
    public function deletedOrders()
    {
        Gate::authorize('viewTenantOrders', (string) auth()->user()->tenant_id);

        return Order::withTrashed()->where('tenant_id', auth()->user()->tenant_id)->whereNotNull('deleted_at')->orderBy('id')->get();
    }

    public function createOrder(): void
    {
        Gate::authorize('viewTenantOrders', (string) auth()->user()->tenant_id);
        Order::create(['tenant_id' => auth()->user()->tenant_id, 'name' => 'New order', 'status' => 'pending']);
        unset($this->orders);
    }

    public function completeOrder(int $id): void
    {
        $order = Order::query()->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);
        Gate::authorize('viewTenantOrders', $order->tenant_id);
        $order->update(['status' => 'completed']);
        unset($this->orders, $this->completedOrders);
    }

    public function deleteOrder(int $id): void
    {
        $order = Order::query()->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);
        Gate::authorize('viewTenantOrders', $order->tenant_id);
        $order->delete();
        unset($this->orders, $this->deletedOrders, $this->completedOrders);
    }

    public function reopenOrder(int $id): void
    {
        $order = Order::query()->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);
        Gate::authorize('viewTenantOrders', $order->tenant_id);
        $order->update(['status' => 'pending']);
        unset($this->orders, $this->completedOrders);
    }

    public function restoreOrder(int $id): void
    {
        $order = Order::withTrashed()->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);
        Gate::authorize('viewTenantOrders', $order->tenant_id);
        $order->restore();
        $order->update(['status' => 'pending']);
        unset($this->orders, $this->deletedOrders);
    }
}; ?>

<div>
    <h1>Pending orders for {{ auth()->user()->tenant_id }}</h1>
    <button wire:click="createOrder" data-testid="create-order">Create order</button>
    <ul data-testid="orders">
        @forelse ($this->orders as $order)
            <li wire:key="order-{{ $order->id }}" data-order-id="{{ $order->id }}">
                <span>{{ $order->name }} — {{ $order->status }}</span>
                <button wire:click="completeOrder({{ $order->id }})">Complete</button>
                <button wire:click="deleteOrder({{ $order->id }})">Delete</button>
            </li>
        @empty
            <li data-testid="empty">No pending orders</li>
        @endforelse
    </ul>
    <h2>Completed orders</h2>
    <ul data-testid="completed-orders">
        @foreach ($this->completedOrders as $order)
            <li wire:key="completed-{{ $order->id }}">{{ $order->name }} — {{ $order->status }} <button wire:click="reopenOrder({{ $order->id }})">Reopen</button></li>
        @endforeach
    </ul>
    <h2>Deleted orders</h2>
    <ul data-testid="deleted-orders">
        @foreach ($this->deletedOrders as $order)
            <li wire:key="deleted-{{ $order->id }}">{{ $order->name }} <button wire:click="restoreOrder({{ $order->id }})">Restore</button></li>
        @endforeach
    </ul>
    @liveQueryScripts
</div>
