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

use Henderkes\Fork\Runtime;
use Henderkes\Fork\Symfony\ForkAwareInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A simple stats service that holds a raw PDO connection.
 * Implements ForkAwareInterface so the bundle automatically reconnects
 * PDO in forked children.
 */
class StatsClient implements ForkAwareInterface
{
    private ?\PDO $pdo = null;
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

    public function configure(Runtime $runtime): Runtime
    {
        return $runtime->before(child: function (): void {
            Runtime::abandon($this->pdo);
            $this->pdo = null;
            $this->connect();
        });
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
