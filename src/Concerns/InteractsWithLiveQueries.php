<?php

namespace LiveQuery\Concerns;

use LiveQuery\Support\ChannelName;

/**
 * Optional Livewire adapter. Add this trait to a Livewire/Volt class-based
 * component with a Computed query method. No Livewire runtime is required
 * by the Laravel core package itself.
 *
 * IMPORTANT: If the component already implements getListeners(), merge these
 * listeners manually rather than using this trait without reviewing conflicts.
 */
trait InteractsWithLiveQueries
{
    /**
     * @return array<string, int|string> Collection name => authorized tenant key
     */
    abstract protected function liveQuerySubscriptions(): array;

    public function getListeners(): array
    {
        return ['livequery-resync' => 'refreshLiveQueries'] + $this->liveQueryListeners();
    }

    /**
     * @return array<string, string>
     */
    protected function liveQueryListeners(): array
    {
        $listeners = ['livequery-resync' => 'refreshLiveQueries'];

        foreach ($this->liveQuerySubscriptions() as $collection => $tenant) {
            $channel = ChannelName::for($collection, $tenant);
            $listeners["echo-private:{$channel},.".ChannelName::EVENT] = 'refreshLiveQueries';
        }

        return $listeners;
    }

    public function refreshLiveQueries(): void
    {
        // A Livewire request re-renders the component. Request-scoped Computed
        // values are recalculated; persistent Computed caches are NOT supported.
    }
}
