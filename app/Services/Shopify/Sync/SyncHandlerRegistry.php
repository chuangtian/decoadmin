<?php

namespace App\Services\Shopify\Sync;

use App\Contracts\Shopify\SyncHandlerInterface;
use InvalidArgumentException;

class SyncHandlerRegistry
{
    /** @var array<string, SyncHandlerInterface> */
    private array $handlers = [];

    /** @param iterable<SyncHandlerInterface> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if (isset($this->handlers[$handler->type()])) {
                throw new InvalidArgumentException("Sync Type [{$handler->type()}] 已注册多个 Handler。");
            }

            $this->handlers[$handler->type()] = $handler;
        }
    }

    public function forType(string $type): ?SyncHandlerInterface
    {
        return $this->handlers[$type] ?? null;
    }

    public function supports(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
