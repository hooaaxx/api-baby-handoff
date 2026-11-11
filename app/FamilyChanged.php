<?php

namespace App;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class FamilyChanged implements ShouldBroadcastNow
{
    public function __construct(private string $family) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('family.'.$this->family);
    }

    public function broadcastAs(): string
    {
        return 'family.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
