<?php

arch('production source has no debugging functions', function (): void {
    expect('LiveQuery')->not->toUse(['dd', 'dump', 'var_dump', 'ray']);
});

arch('package source does not depend on demo application models', function (): void {
    expect('LiveQuery')->not->toUse('App\\Models');
});

arch('core package does not depend on optional frontend integrations', function (): void {
    expect('LiveQuery')->not->toUse(['Livewire', 'Volt']);
});
