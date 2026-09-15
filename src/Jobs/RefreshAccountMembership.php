<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Jobs;

use CoreX\Tenancy\Actions\ApplyMembershipProjection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshAccountMembership implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $identityId,
        public readonly string $accountId,
        public readonly string $product,
        public readonly string $requestId,
    ) {}

    public function handle(ApplyMembershipProjection $projection): void
    {
        $projection->refresh(identityId: $this->identityId, accountId: $this->accountId, product: $this->product, requestId: $this->requestId);
    }
}
