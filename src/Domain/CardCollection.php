<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use voku\AgentKanban\Exception\ValidationException;

/**
 * An immutable, order-preserving set of cards, unique by {@see CardId}.
 *
 * @implements IteratorAggregate<int, Card>
 */
final readonly class CardCollection implements Countable, IteratorAggregate
{
    /**
     * @var list<Card>
     */
    private array $cards;

    /**
     * @param list<Card> $cards
     */
    private function __construct(array $cards)
    {
        $this->cards = $cards;
    }

    /**
     * @param list<Card> $cards
     */
    public static function fromArray(array $cards): self
    {
        $seen = [];
        foreach ($cards as $card) {
            $key = $card->id->toString();
            if (isset($seen[$key])) {
                throw new ValidationException(
                    sprintf('Duplicate card ID: %s', $key),
                    cardId: $key,
                );
            }

            $seen[$key] = true;
        }

        return new self($cards);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<Card>
     */
    public function all(): array
    {
        return $this->cards;
    }

    public function get(CardId|string $id): ?Card
    {
        $needle = $id instanceof CardId ? $id->toString() : strtoupper(trim($id));
        foreach ($this->cards as $card) {
            if ($card->id->toString() === $needle) {
                return $card;
            }
        }

        return null;
    }

    public function has(CardId|string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Returns a new collection with $card added, or replacing the existing
     * card with the same ID.
     */
    public function withCard(Card $card): self
    {
        $replaced = false;
        $cards = [];
        foreach ($this->cards as $existing) {
            if ($existing->id->equals($card->id)) {
                $cards[] = $card;
                $replaced = true;

                continue;
            }

            $cards[] = $existing;
        }

        if (!$replaced) {
            $cards[] = $card;
        }

        return new self($cards);
    }

    public function withoutCard(CardId $id): self
    {
        return new self(array_values(array_filter(
            $this->cards,
            static fn (Card $card): bool => !$card->id->equals($id),
        )));
    }

    /**
     * @param callable(Card): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->cards, $predicate)));
    }

    public function count(): int
    {
        return count($this->cards);
    }

    /**
     * @return Traversable<int, Card>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cards);
    }
}
