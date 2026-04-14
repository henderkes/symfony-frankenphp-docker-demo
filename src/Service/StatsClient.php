<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Henderkes\ParallelFork\ForkAwareInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A simple stats service that holds a raw PDO connection.
 * Implements ForkAwareInterface so the bundle automatically reconnects
 * PDO in forked children.
 */
class StatsClient implements ForkAwareInterface
{
    private ?\PDO $pdo = null;
    /** @var list<\PDO> Prevents GC from closing inherited fds in child processes @phpstan-ignore property.onlyWritten */
    private array $abandonedConnections = [];
    private string $pdoDsn;
    private ?string $user;
    private ?string $pass;

    public function __construct(#[Autowire('%env(resolve:DATABASE_URL)%')] string $dsn)
    {
        $parsed = parse_url($dsn);
        $db = ltrim($parsed['path'] ?? '', '/');
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 5432;
        $this->user = isset($parsed['user']) ? urldecode($parsed['user']) : null;
        $this->pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
        $this->pdoDsn = "pgsql:host={$host};port={$port};dbname={$db}";

        $this->connect();
    }

    public function atFork(): void
    {
        // Stash the inherited PDO so PHP's GC doesn't close the underlying
        // socket (the parent still uses it). This only runs in the child
        // process, which exits shortly after — no permanent leak.
        if (null !== $this->pdo) {
            $this->abandonedConnections[] = $this->pdo;
            $this->pdo = null;
        }
        $this->connect();
    }

    public function getConnectionId(): int
    {
        return (int) $this->pdo->query('SELECT pg_backend_pid()')->fetchColumn();
    }

    public function getPostCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM public.symfony_demo_post')->fetchColumn();
    }

    private function connect(): void
    {
        $this->pdo = new \PDO($this->pdoDsn, $this->user, $this->pass);
    }
}
