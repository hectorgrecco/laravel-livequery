<?php

namespace LiveQuery;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LiveQuery\Events\LiveQueryInvalidated;
use LiveQuery\Observers\LiveQueryObserver;
use LiveQuery\Support\ChannelName;

final class LiveQueryManager
{
    /** @var array<string, CollectionDefinition> */
    private array $collections = [];

    /** @var array<class-string<Model>, list<string>> */
    private array $byModel = [];

    /**
     * @param  Closure(mixed, string): bool  $authorize
     * @param  array<mixed>  $guards
     */
    public function register(string $name, string $model, string $tenantColumn, Closure $authorize, array $guards = []): CollectionDefinition
    {
        if (! array_is_list($guards) || array_filter($guards, fn (mixed $guard): bool => ! is_string($guard) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $guard))) {
            throw new InvalidArgumentException('Broadcast guards must be a list of guard names.');
        }

        if (isset($this->collections[$name])) {
            throw new InvalidArgumentException("LiveQuery collection [{$name}] is already registered.");
        }

        $definition = new CollectionDefinition($name, $model, $tenantColumn, $authorize);
        $this->collections[$name] = $definition;

        if (! isset($this->byModel[$model])) {
            $this->byModel[$model] = [];
            $model::observe(LiveQueryObserver::class);
        }

        $this->byModel[$model][] = $name;

        // Every subscription MUST be authenticated with an application-supplied policy.
        Broadcast::channel("livequery.{$name}.{tenant}", function (mixed $user, string $tenant) use ($name): bool {
            return $this->collections[$name]->authorizes($user, $tenant);
        }, $guards === [] ? [] : ['guards' => $guards]);

        return $definition;
    }

    /** @return list<CollectionDefinition> */
    public function collectionsFor(Model $model): array
    {
        $names = $this->byModel[$model::class] ?? [];

        return array_map(fn (string $name): CollectionDefinition => $this->collections[$name], $names);
    }

    public function invalidate(string $name, int|string $tenant): void
    {
        if (! isset($this->collections[$name])) {
            throw new InvalidArgumentException("LiveQuery collection [{$name}] is not registered.");
        }

        $key = ChannelName::normalizeTenant($tenant);

        if ($key === null) {
            throw new InvalidArgumentException('A tenant key is required.');
        }

        Event::dispatch(new LiveQueryInvalidated($name, $key));
    }

    public function changed(Model $model, string $operation): void
    {
        foreach ($this->collectionsFor($model) as $definition) {
            $tenants = [$definition->currentTenant($model)];

            // Tenant reassignment must invalidate both the old and new scopes.
            if ($operation === 'updated') {
                $tenants[] = $definition->originalTenant($model);
            }

            foreach (array_unique(array_filter($tenants, fn (?string $tenant): bool => $tenant !== null)) as $tenant) {
                // Laravel delays dispatch until the outer transaction commits.
                $this->invalidate($definition->name, $tenant);
            }
        }
    }
}
