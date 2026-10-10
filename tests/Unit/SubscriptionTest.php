<?php

use LiveQuery\Concerns\InteractsWithLiveQueries;

it('builds private Echo listeners without handwritten events', function (): void {
    $component = new class
    {
        use InteractsWithLiveQueries;

        protected function liveQuerySubscriptions(): array
        {
            return ['orders' => 'acme'];
        }
    };

    expect($component->getListeners())->toBe([
        'echo-private:livequery.orders.acme,.livequery.invalidated' => 'refreshLiveQueries',
    ]);
});

it('allows a component to merge live query listeners into its own listeners', function (): void {
    $component = new class
    {
        use InteractsWithLiveQueries;

        protected function liveQuerySubscriptions(): array
        {
            return ['orders' => 'acme'];
        }

        public function getListeners(): array
        {
            return ['custom.event' => 'handleCustomEvent'] + $this->liveQueryListeners();
        }
    };

    expect($component->getListeners())->toBe([
        'custom.event' => 'handleCustomEvent',
        'echo-private:livequery.orders.acme,.livequery.invalidated' => 'refreshLiveQueries',
    ]);
});
