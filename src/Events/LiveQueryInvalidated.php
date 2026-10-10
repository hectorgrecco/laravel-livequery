<?php

namespace LiveQuery\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use LiveQuery\Support\ChannelName;

final class LiveQueryInvalidated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $collection,
        public readonly string $tenant,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::for($this->collection, $this->tenant))];
    }

    public function broadcastAs(): string
    {
        return ChannelName::EVENT;
    }

    /** No model attributes, user records or tenant metadata are broadcast. */
    public function broadcastWith(): array
    {
        return ['collection' => $this->collection];
    }
}
