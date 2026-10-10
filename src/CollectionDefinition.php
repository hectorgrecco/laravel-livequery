<?php

namespace LiveQuery;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LiveQuery\Support\ChannelName;

final class CollectionDefinition
{
    /** @var Closure(mixed, string): bool */
    private readonly Closure $authorize;

    /**
     * @param  Closure(mixed, string): bool  $authorize
     */
    public function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly string $tenantColumn,
        Closure $authorize,
    ) {
        ChannelName::validateCollection($name);

        if (! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Registered model must extend Eloquent Model.');
        }

        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $tenantColumn)) {
            throw new InvalidArgumentException('Tenant column must be a simple column identifier.');
        }

        $this->authorize = $authorize;
    }

    public function authorizes(mixed $user, string $tenant): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            ChannelName::normalizeTenant($tenant);
        } catch (InvalidArgumentException) {
            return false;
        }

        return ($this->authorize)($user, $tenant) === true;
    }

    public function currentTenant(Model $model): ?string
    {
        return $this->tenant($model->getAttribute($this->tenantColumn));
    }

    public function originalTenant(Model $model): ?string
    {
        return $this->tenant($model->getRawOriginal($this->tenantColumn));
    }

    private function tenant(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && $value !== null) {
            throw new InvalidArgumentException('Tenant column must contain a string, integer or null.');
        }

        return ChannelName::normalizeTenant($value);
    }
}
