<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\Claim;
use voku\AgentKanban\Tests\Support\CardFactory;

final class CardTest extends TestCase
{
    public function testWithClaimNullClearsAnExistingClaim(): void
    {
        $claim = new Claim('codex', new DateTimeImmutable(), null, CardRevision::fromContent('x'));
        $card = CardFactory::make(claim: $claim);

        $cleared = $card->withClaim(null);

        self::assertNull($cleared->claim);
    }

    public function testWithClaimNonNullSetsTheClaim(): void
    {
        $card = CardFactory::make();
        $claim = new Claim('codex', new DateTimeImmutable(), null, CardRevision::fromContent('x'));

        $claimed = $card->withClaim($claim);

        self::assertSame($claim, $claimed->claim);
    }
}
