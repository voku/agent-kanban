<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\Lane;

/**
 * The immutable, in-memory aggregate of a board: its configuration plus the
 * cards that were read from the resolved card directory.
 *
 * Board itself stays a plain data holder. Query services, rendering,
 * verification, and mutation all live in separate collaborators that take a
 * Board as input (see `docs/architecture.md`), so none of them can silently
 * grow into a god object.
 */
final readonly class Board
{
    public function __construct(
        public BoardConfig $config,
        public CardCollection $cards,
        public string $cardDirectory,
        public int $doneCount = 0,
    ) {
    }

    public function withCards(CardCollection $cards): self
    {
        return new self($this->config, $cards, $this->cardDirectory, $this->doneCount);
    }

    public function get(CardId|string $id): ?Card
    {
        return $this->cards->get($id);
    }

    /**
     * @return list<Card>
     */
    public function byLane(Lane $lane): array
    {
        return array_values(array_filter(
            $this->cards->all(),
            static fn (Card $card): bool => $card->lane->equals($lane),
        ));
    }

    public function toSnapshot(): BoardSnapshot
    {
        return new BoardSnapshot(
            $this->config,
            $this->cards,
            $this->cardDirectory,
            $this->doneCount,
            new \DateTimeImmutable(),
        );
    }
}
