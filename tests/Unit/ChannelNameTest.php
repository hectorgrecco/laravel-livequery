<?php

use LiveQuery\Support\ChannelName;

it('names a scoped private channel', function (): void {
    expect(ChannelName::for('orders', 'tenant_42'))->toBe('livequery.orders.tenant_42');
});

it('rejects invalid collection and tenant identifiers', function (): void {
    expect(fn () => ChannelName::for('orders.*', 'a'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ChannelName::for('orders', 'other.tenant'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ChannelName::for('orders', ''))->toThrow(InvalidArgumentException::class);
});

it('normalizes integer tenant keys and represents missing keys as null', function (): void {
    expect(ChannelName::normalizeTenant(42))->toBe('42')
        ->and(ChannelName::normalizeTenant(null))->toBeNull()
        ->and(ChannelName::normalizeTenant(''))->toBeNull();
});
