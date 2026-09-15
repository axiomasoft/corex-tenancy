<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

final readonly class ActorRef
{
    public function __construct(
        public ActorType $type,
        string $id,
    ) {
        $this->id = CentralValue::uuid(value: $id, field: 'actor.id');
    }

    public string $id;
}
