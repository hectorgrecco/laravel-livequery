<?php

namespace LiveQuery\Observers;

use Illuminate\Database\Eloquent\Model;
use LiveQuery\LiveQueryManager;

final class LiveQueryObserver
{
    public function __construct(private readonly LiveQueryManager $manager) {}

    public function created(Model $model): void
    {
        $this->manager->changed($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->manager->changed($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->manager->changed($model, 'deleted');
    }

    public function restored(Model $model): void
    {
        $this->manager->changed($model, 'restored');
    }
}
