<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Henderkes\Fork\Runtime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/parallel')]
class ParallelController extends AbstractController
{
    #[Route('/test', name: 'parallel_test')]
    public function index(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $tagsBefore = \count($em->getRepository(Tag::class)->findAll());

        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        // 1. Parallel DB READS — before(child:) handler reconnects the Doctrine
        //    connection in each child process
        $fPosts = $runtime->run(static function () use ($em) {
            return array_map(
                static fn ($p) => ['id' => $p->getId(), 'title' => $p->getTitle()],
                $em->getRepository(Post::class)->findAll()
            );
        });

        $fUsers = $runtime->run(static function () use ($em) {
            return array_map(
                static fn ($u) => ['id' => $u->getId(), 'username' => $u->getUsername()],
                $em->getRepository(User::class)->findAll()
            );
        });

        // 2. Parallel LAZY LOADING — access relations from forked children
        //    Post->getAuthor() is ManyToOne (lazy), Post->getComments() is OneToMany,
        //    Post->getTags() is ManyToMany. All trigger separate DB queries.
        $fLazy = $runtime->run(static function () use ($em) {
            $post = $em->getRepository(Post::class)->find(1);
            if (!$post) {
                return ['error' => 'post not found'];
            }

            // These trigger lazy loading — separate queries in the forked child
            $author = $post->getAuthor();
            $comments = $post->getComments();
            $tags = $post->getTags();

            return [
                'post_title' => $post->getTitle(),
                'author_username' => $author->getUsername(),
                'comment_count' => \count($comments),
                'first_comment' => $comments->first() ? $comments->first()->getContent() : null,
                'tag_names' => array_map(static fn ($t) => $t->getName(), $tags->toArray()),
            ];
        });

        // 3. Parallel DB WRITE — insert a new tag from a forked child
        $fWrite = $runtime->run(static function () use ($em) {
            $tag = new Tag('parallel-'.getmypid().'-'.time());
            $em->persist($tag);
            $em->flush();

            return ['id' => $tag->getId(), 'name' => $tag->getName()];
        });

        // 3. Parallel FILE WRITE
        $fFile = $runtime->run(static function () {
            $path = '/tmp/frankenphp_parallel_file_test.txt';
            $content = 'Written by child pid='.getmypid().' at '.date('c');
            file_put_contents($path, $content);

            return ['path' => $path, 'size' => \strlen($content)];
        });

        // 4. Parallel CPU work
        $fFib = $runtime->run(static function () {
            $fib = static function (int $n) use (&$fib): int {
                return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
            };

            return $fib(35);
        });

        // Collect results
        $posts = $fPosts->value();
        $users = $fUsers->value();
        $lazyResult = $fLazy->value();
        $written = $fWrite->value();
        $fileResult = $fFile->value();
        $fib = $fFib->value();

        // Verify: parent can see the child's DB write
        $em->clear();
        $tagsAfter = \count($em->getRepository(Tag::class)->findAll());

        // Verify: parent can read the child's file
        $fileContent = file_get_contents('/tmp/frankenphp_parallel_file_test.txt');

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'reads' => [
                'posts' => \count($posts),
                'users' => \count($users),
            ],
            'lazy_loading' => $lazyResult,
            'db_write' => [
                'child_wrote' => $written,
                'tags_before' => $tagsBefore,
                'tags_after' => $tagsAfter,
                'parent_sees_write' => $tagsAfter > $tagsBefore,
            ],
            'file_write' => [
                'child_result' => $fileResult,
                'parent_reads' => $fileContent,
                'parent_sees_file' => str_contains($fileContent, 'Written by child'),
            ],
            'cpu' => [
                'fib35' => $fib,
            ],
        ]);
    }

    /**
     * Runtime: explicit lifecycle management, multiple runtimes, before(child:) hooks.
     */
    #[Route('/runtime', name: 'parallel_runtime')]
    public function runtimeTest(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);

        $hookFile = tempnam('/tmp', 'parallel_hook_');

        // Two independent runtimes running concurrently
        $rt1 = new Runtime();
        $rt1->before(name: 'doctrine', child: self::doctrineReset($em));
        $rt1->before(name: 'hook-file', child: static function () use ($hookFile) {
            file_put_contents($hookFile, 'before ran in pid='.getmypid());
        });

        $rt2 = new Runtime();
        $rt2->before(name: 'doctrine', child: self::doctrineReset($em));
        $rt2->before(name: 'hook-file', child: static function () use ($hookFile) {
            file_put_contents($hookFile, 'before ran in pid='.getmypid());
        });

        $f1 = $rt1->run(static function () use ($em) {
            $count = \count($em->getRepository(Post::class)->findAll());

            return ['runtime' => 1, 'pid' => getmypid(), 'post_count' => $count];
        });

        $f2 = $rt2->run(static function () use ($em) {
            $count = \count($em->getRepository(User::class)->findAll());

            return ['runtime' => 2, 'pid' => getmypid(), 'user_count' => $count];
        });

        // Multiple tasks on the same runtime
        $f3 = $rt1->run(static function (int $n) {
            return ['runtime' => 1, 'pid' => getmypid(), 'task' => 'square', 'result' => $n * $n];
        }, [42]);

        $results = [
            'runtime1_task1' => $f1->value(),
            'runtime2_task1' => $f2->value(),
            'runtime1_task2' => $f3->value(),
        ];

        $hookContent = file_exists($hookFile) ? file_get_contents($hookFile) : 'not written';
        @unlink($hookFile);

        $rt1->close();
        $rt2->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Explicit Runtime lifecycle: two runtimes, before(child:) hook, multiple tasks per runtime',
            'parent_pid' => getmypid(),
            'results' => $results,
            'at_fork_hook' => $hookContent,
        ]);
    }

    /**
     * Future: cancel, done() polling, error propagation from children.
     */
    #[Route('/futures', name: 'parallel_futures')]
    public function futures(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();

        // 1. done() polling — non-blocking check
        $fast = $runtime->run(static function () {
            return 'instant';
        });
        usleep(50_000); // give it time to finish
        $doneResult = [
            'done_before_value' => $fast->done(),
            'value' => $fast->value(),
            'done_after_value' => $fast->done(),
        ];

        // 2. Cancel a long-running task
        $slow = $runtime->run(static function () {
            sleep(10);

            return 'should not reach here';
        });
        usleep(20_000);
        $cancelSuccess = $slow->cancel();
        $cancelResult = [
            'cancel_returned' => $cancelSuccess,
            'cancelled' => $slow->cancelled(),
        ];

        // 3. Exception propagation from child
        $failing = $runtime->run(static function () {
            throw new \RuntimeException('Intentional child error');
        });
        $errorResult = ['propagated' => false, 'message' => null];
        try {
            $failing->value();
        } catch (\Throwable $e) {
            $errorResult = [
                'propagated' => true,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ];
        }

        // 4. Passing arguments to run()
        $withArgs = $runtime->run(static function (string $greeting, int $count) {
            return str_repeat($greeting.' ', $count);
        }, ['hello', 3]);
        $argsResult = trim($withArgs->value());

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Future API: done() polling, cancel(), error propagation, arguments',
            'done_polling' => $doneResult,
            'cancel' => $cancelResult,
            'error_propagation' => $errorResult,
            'arguments' => $argsResult,
        ]);
    }

    /**
     * Fan-out/fan-in: parallel map over a dataset using CPU detection.
     */
    #[Route('/fan-out', name: 'parallel_fan_out')]
    public function fanOut(): JsonResponse
    {
        $start = microtime(true);
        $numCpus = (int) shell_exec('nproc 2>/dev/null') ?: 4;

        $data = range(1, 120);
        $chunks = array_chunk($data, (int) ceil(\count($data) / $numCpus));

        $runtime = new Runtime();
        $futures = [];
        foreach ($chunks as $i => $chunk) {
            $futures[$i] = $runtime->run(static function (array $numbers) {
                $results = [];
                foreach ($numbers as $n) {
                    $results[] = [
                        'n' => $n,
                        'is_prime' => self::isPrime($n),
                        'pid' => getmypid(),
                    ];
                }

                return $results;
            }, [$chunk]);
        }

        // Fan-in: merge results
        $allResults = [];
        $pidDistribution = [];
        foreach ($futures as $f) {
            foreach ($f->value() as $item) {
                $allResults[] = $item;
                $pidDistribution[$item['pid']] = ($pidDistribution[$item['pid']] ?? 0) + 1;
            }
        }

        $primes = array_column(array_filter($allResults, static fn ($r) => $r['is_prime']), 'n');
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => "Fan-out/fan-in: split 120 items across $numCpus workers (detected CPUs)",
            'cpu_count' => $numCpus,
            'chunks' => \count($chunks),
            'total_processed' => \count($allResults),
            'primes_found' => $primes,
            'pid_distribution' => $pidDistribution,
        ]);
    }

    /**
     * Test that before(child:) handlers properly reset the Doctrine connection,
     * allowing children to query independently.
     */
    #[Route('/at-fork', name: 'parallel_at_fork')]
    public function atFork(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        // Warm up the parent connection
        $parentPosts = $em->getRepository(Post::class)->findAll();
        $parentPostCount = \count($parentPosts);

        // Fork multiple children that all use the same EM — the before(child:)
        // handler reconnects Doctrine in each child
        $futures = [];
        for ($i = 0; $i < 4; ++$i) {
            $futures["child_$i"] = $runtime->run(static function () use ($em, $i) {
                $posts = $em->getRepository(Post::class)->findAll();
                $users = $em->getRepository(User::class)->findAll();

                // Write from child to prove the connection is fully functional
                $tag = new Tag("atfork-test-$i-".getmypid());
                $em->persist($tag);
                $em->flush();

                return [
                    'child' => $i,
                    'pid' => getmypid(),
                    'post_count' => \count($posts),
                    'user_count' => \count($users),
                    'wrote_tag' => $tag->getName(),
                ];
            });
        }

        $childResults = [];
        foreach ($futures as $name => $f) {
            $childResults[$name] = $f->value();
        }

        // Parent EM still works after all children used it
        $em->clear();
        $parentPostsAfter = \count($em->getRepository(Post::class)->findAll());
        $tagsWritten = $em->getRepository(Tag::class)->findBy([], ['id' => 'DESC'], 4);
        $tagNames = array_map(static fn ($t) => $t->getName(), $tagsWritten);

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'before(child:) handlers: Doctrine reset in forked children',
            'parent_pid' => getmypid(),
            'parent_post_count_before' => $parentPostCount,
            'parent_post_count_after' => $parentPostsAfter,
            'parent_still_works' => $parentPostsAfter === $parentPostCount,
            'children' => $childResults,
            'tags_visible_to_parent' => $tagNames,
        ]);
    }

    /**
     * Test user-registered before(child:) handler for a non-autowired singleton.
     *
     * Demonstrates: an object that is NOT a Symfony service (not in the container,
     * not autowired) but holds state that must be reset after fork. The user
     * registers their own before(child:) handler to deal with it.
     */
    #[Route('/at-fork-custom', name: 'parallel_at_fork_custom')]
    public function atForkCustom(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        // A non-autowired object that simulates a connection pool or external client.
        $apiClient = new class {
            private string $connectionId;
            private int $requestCount = 0;

            public function __construct()
            {
                $this->connectionId = 'conn-'.getmypid().'-'.bin2hex(random_bytes(4));
            }

            public function reset(): void
            {
                $this->connectionId = 'conn-'.getmypid().'-'.bin2hex(random_bytes(4));
                $this->requestCount = 0;
            }

            /** @return array<string, mixed> */
            public function request(string $endpoint): array
            {
                ++$this->requestCount;

                return [
                    'connectionId' => $this->connectionId,
                    'requestCount' => $this->requestCount,
                    'pid' => getmypid(),
                    'endpoint' => $endpoint,
                ];
            }

            public function getConnectionId(): string
            {
                return $this->connectionId;
            }
        };

        $parentConnectionId = $apiClient->getConnectionId();

        // User registers a named before(child:) handler for this non-autowired object
        $runtime->before(name: 'api-client', child: static function () use ($apiClient) {
            $apiClient->reset();
        });

        // Each child should get a fresh connection after the before(child:) handler runs
        $futures = [];
        for ($i = 0; $i < 3; ++$i) {
            $futures["worker_$i"] = $runtime->run(static function () use ($apiClient, $em, $i) {
                // Use the custom client — should have been reset by before(child:)
                $r1 = $apiClient->request("/api/items/$i");
                $r2 = $apiClient->request("/api/details/$i");

                // Also use Doctrine — reset by the doctrine before(child:) handler
                $postCount = \count($em->getRepository(Post::class)->findAll());

                return [
                    'child' => $i,
                    'connection_id' => $apiClient->getConnectionId(),
                    'request1' => $r1,
                    'request2' => $r2,
                    'post_count' => $postCount,
                ];
            });
        }

        $childResults = [];
        $childConnectionIds = [];
        foreach ($futures as $name => $f) {
            $result = $f->value();
            $childResults[$name] = $result;
            $childConnectionIds[] = $result['connection_id'];
        }

        // Verify: parent's connection ID unchanged, each child got a unique one
        $allUnique = \count(array_unique($childConnectionIds)) === \count($childConnectionIds);
        $noneMatchParent = !\in_array($parentConnectionId, $childConnectionIds, true);

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Named before(child:) handler for non-autowired singleton',
            'parent_connection_id' => $parentConnectionId,
            'parent_connection_unchanged' => $apiClient->getConnectionId() === $parentConnectionId,
            'child_connection_ids_unique' => $allUnique,
            'no_child_matches_parent' => $noneMatchParent,
            'children' => $childResults,
        ]);
    }

    /**
     * Test multiple before(child:) handlers working together: doctrine handler
     * plus user handler (custom client), verifying execution order and independence.
     */
    #[Route('/at-fork-multi', name: 'parallel_at_fork_multi')]
    public function atForkMulti(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        // Track handler execution order via a temp file
        $orderFile = tempnam('/tmp', 'atfork_order_');

        // A non-autowired cache-like singleton
        $cache = new class {
            /** @var array<string, mixed> */
            private array $store = [];

            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function clear(): void
            {
                $this->store = [];
            }

            public function count(): int
            {
                return \count($this->store);
            }
        };

        // Populate cache in parent
        $cache->set('user:1', 'Alice');
        $cache->set('user:2', 'Bob');
        $cache->set('config:theme', 'dark');
        $parentCacheCount = $cache->count();

        // User registers a named handler for cache reset + order tracking
        $runtime->before(name: 'app-cache', child: static function () use ($cache, $orderFile) {
            $cache->clear();
            file_put_contents($orderFile, 'user_handler:'.getmypid());
        });

        // Fork children that use both Doctrine (framework handler) and cache (user handler)
        $futures = [];
        for ($i = 0; $i < 3; ++$i) {
            $futures["child_$i"] = $runtime->run(static function () use ($em, $cache, $i) {
                // Cache should be empty after before(child:) clear
                $cacheEmpty = 0 === $cache->count();

                // Populate fresh child cache
                $cache->set("child:$i", "value-$i");

                // Use Doctrine — connection reset by doctrine handler
                $posts = $em->getRepository(Post::class)->findBy([], null, 3);
                $postTitles = array_map(static fn ($p) => $p->getTitle(), $posts);

                return [
                    'child' => $i,
                    'pid' => getmypid(),
                    'cache_was_empty_after_fork' => $cacheEmpty,
                    'child_cache_count' => $cache->count(),
                    'post_titles' => $postTitles,
                ];
            });
        }

        $childResults = [];
        foreach ($futures as $name => $f) {
            $childResults[$name] = $f->value();
        }

        // Parent cache unchanged
        $parentCacheAfter = $cache->count();

        $orderContent = file_exists($orderFile) ? file_get_contents($orderFile) : 'not written';
        @unlink($orderFile);

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Multiple before(child:) handlers: doctrine + user (cache clear)',
            'parent_cache_before' => $parentCacheCount,
            'parent_cache_after' => $parentCacheAfter,
            'parent_cache_unchanged' => $parentCacheAfter === $parentCacheCount,
            'handler_execution_proof' => $orderContent,
            'children' => $childResults,
        ]);
    }

    /**
     * Override a handler with a custom one.
     *
     * Here we register a 'doctrine' handler that wraps the default
     * and also logs, then verify it runs.
     */
    #[Route('/at-fork-override', name: 'parallel_at_fork_override')]
    public function atForkOverride(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $logFile = tempnam('/tmp', 'atfork_override_');

        // Register a custom 'doctrine' handler that wraps the default and also logs
        $defaultHandler = self::doctrineReset($em);
        $runtime->before(name: 'doctrine', child: static function () use ($defaultHandler, $logFile) {
            file_put_contents($logFile, 'custom-doctrine-handler:'.getmypid());
            $defaultHandler();
        });

        // Warm up connection
        $em->getRepository(Post::class)->findAll();

        $future = $runtime->run(static function () use ($em) {
            return [
                'pid' => getmypid(),
                'post_count' => \count($em->getRepository(Post::class)->findAll()),
            ];
        });

        $result = $future->value();
        $logContent = file_exists($logFile) ? file_get_contents($logFile) : 'not written';
        @unlink($logFile);

        // Restore the default handler so other routes aren't affected
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Override: replaced default doctrine handler with custom logging one',
            'child_result' => $result,
            'custom_handler_ran' => str_contains($logContent, 'custom-doctrine-handler'),
            'handler_log' => $logContent,
        ]);
    }

    /**
     * Remove a named handler entirely and verify it no longer runs.
     */
    #[Route('/at-fork-remove', name: 'parallel_at_fork_remove')]
    public function atForkRemove(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));

        // Register a named handler then immediately remove it
        $removedFile = tempnam('/tmp', 'atfork_removed_');
        @unlink($removedFile);
        $runtime->before(name: 'will-be-removed', child: static function () use ($removedFile) {
            file_put_contents($removedFile, 'should-not-exist');
        });
        $runtime->removeBefore('will-be-removed');

        // Also register one that stays, to prove removal is targeted
        $keptFile = tempnam('/tmp', 'atfork_kept_');
        @unlink($keptFile);
        $runtime->before(name: 'will-be-kept', child: static function () use ($keptFile) {
            file_put_contents($keptFile, 'kept-handler:'.getmypid());
        });

        $future = $runtime->run(static function () use ($em) {
            return [
                'pid' => getmypid(),
                'post_count' => \count($em->getRepository(Post::class)->findAll()),
            ];
        });

        $result = $future->value();
        $removedRan = file_exists($removedFile);
        $keptContent = file_exists($keptFile) ? file_get_contents($keptFile) : 'not written';

        @unlink($removedFile);
        @unlink($keptFile);
        $runtime->removeBefore('will-be-kept');
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'removeBefore: removed handler does not run, kept handler does',
            'child_result' => $result,
            'removed_handler_ran' => $removedRan,
            'kept_handler_ran' => str_contains($keptContent, 'kept-handler'),
            'kept_handler_log' => $keptContent,
        ]);
    }

    /**
     * HttpClient in forked children. The before(child:) handler resets
     * the inherited curl_multi/curl_share handles so each child gets its
     * own connection pool.
     *
     * We warm up the parent's connections first to prove the reset works
     * even when curl handles already exist.
     */
    #[Route('/at-fork-http', name: 'parallel_at_fork_http')]
    public function atForkHttp(HttpClientInterface $httpClient, EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $runtime->before(name: 'doctrine', child: self::doctrineReset($em));
        $runtime->before(name: 'http-client', child: self::httpClientReset($httpClient));

        // Warm up: parent makes a request so curl handles exist before fork
        $warmup = $httpClient->request('GET', 'https://httpbin.org/get');
        $parentStatus = $warmup->getStatusCode();

        // Fork children that each make their own HTTP request
        $urls = [
            'https://httpbin.org/get?child=0',
            'https://httpbin.org/get?child=1',
            'https://httpbin.org/get?child=2',
        ];

        $futures = [];
        foreach ($urls as $i => $url) {
            $futures["child_$i"] = $runtime->run(static function () use ($httpClient, $em, $url, $i) {
                // HTTP request from forked child — curl handles were reset by before(child:)
                $response = $httpClient->request('GET', $url);
                $status = $response->getStatusCode();
                $body = $response->toArray();

                // Also use Doctrine to prove both handlers work together
                $postCount = \count($em->getRepository(Post::class)->findAll());

                return [
                    'child' => $i,
                    'pid' => getmypid(),
                    'http_status' => $status,
                    'http_url' => $body['url'] ?? $url,
                    'post_count' => $postCount,
                ];
            });
        }

        $childResults = [];
        foreach ($futures as $name => $f) {
            $childResults[$name] = $f->value();
        }

        // Parent's HttpClient still works after forks
        $afterFork = $httpClient->request('GET', 'https://httpbin.org/get?parent=after');
        $parentAfterStatus = $afterFork->getStatusCode();

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'HttpClient in forked children with curl handle reset',
            'parent_warmup_status' => $parentStatus,
            'parent_after_fork_status' => $parentAfterStatus,
            'parent_still_works' => 200 === $parentAfterStatus,
            'children' => $childResults,
        ]);
    }

    /**
     * Demonstrates a user-registered RuntimeFactory before handler.
     * StatsClient holds a raw PDO connection; the factory's 'stats-client'
     * handler calls reconnect() in each child so they don't share the
     * parent's connection.
     */
    #[Route('/stats-fork', name: 'parallel_stats_fork')]
    public function statsFork(Runtime $runtime, \App\Service\StatsClient $stats): JsonResponse
    {
        $parentConnId = $stats->getConnectionId();
        $parentCount = $stats->getPostCount();

        $futures = [];
        for ($i = 0; $i < 3; ++$i) {
            $futures["child_$i"] = $runtime->run(static function () use ($stats, $i) {
                return [
                    'child' => $i,
                    'pid' => getmypid(),
                    'connection_id' => $stats->getConnectionId(),
                    'post_count' => $stats->getPostCount(),
                ];
            });
        }

        $childResults = [];
        $childConnIds = [];
        foreach ($futures as $name => $f) {
            $result = $f->value();
            $childResults[$name] = $result;
            $childConnIds[] = $result['connection_id'];
        }

        // Verify each child got its own connection
        $allUnique = \count(array_unique($childConnIds)) === \count($childConnIds);
        $noneMatchParent = !\in_array($parentConnId, $childConnIds, true);

        // Parent's connection still works
        $parentAfterCount = $stats->getPostCount();

        $runtime->close();

        return $this->json([
            'description' => 'User-registered before handler: StatsClient PDO reconnect',
            'parent_connection_id' => $parentConnId,
            'parent_post_count' => $parentCount,
            'parent_still_works' => $parentAfterCount === $parentCount,
            'child_connections_unique' => $allUnique,
            'no_child_matches_parent' => $noneMatchParent,
            'children' => $childResults,
        ]);
    }

    /**
     * Demo helper: returns a before(child:) closure that null-outs the
     * Doctrine connection handle in the child so the next query
     * reconnects lazily. This is the kind of hook a real app would
     * typically consume via the Symfony ForkBundle's auto-wiring — we
     * do it by hand here to exercise Runtime::before() directly.
     */
    private static function doctrineReset(EntityManagerInterface $em): \Closure
    {
        /** @var list<object> $stash */
        static $stash = [];

        return static function () use ($em, &$stash): void {
            if (! Runtime::inChild()) {
                return;
            }

            $conn = $em->getConnection();
            $ref = new \ReflectionClass($conn);

            $prop = null;
            foreach (['_conn', 'connection'] as $name) {
                if ($ref->hasProperty($name)) {
                    $prop = $ref->getProperty($name);
                    break;
                }
            }
            if (null === $prop) {
                return;
            }

            $old = $prop->getValue($conn);
            if (\is_object($old)) {
                $stash[] = $old;
                $prop->setValue($conn, null);
            }
        };
    }

    /**
     * Demo helper: resets the curl_multi/curl_share handles on a
     * Symfony HttpClient so every forked child owns its own pool.
     */
    private static function httpClientReset(\Symfony\Contracts\HttpClient\HttpClientInterface $client): \Closure
    {
        /** @var list<object> $stash */
        static $stash = [];

        return static function () use ($client, &$stash): void {
            if (! Runtime::inChild()) {
                return;
            }
            try {
                $cursor = $client;
                for ($d = 0; $d < 10; ++$d) {
                    $ref = new \ReflectionClass($cursor);
                    if ($ref->hasProperty('multi')) {
                        $multi = $ref->getProperty('multi')->getValue($cursor);
                        if (\is_object($multi)) {
                            $multiRef = new \ReflectionClass($multi);
                            foreach (['handle', 'share'] as $f) {
                                if ($multiRef->hasProperty($f) && isset($multi->$f)) {
                                    $h = $multi->$f;
                                    unset($multi->$f);
                                    if (\is_object($h)) {
                                        $stash[] = $h;
                                    }
                                }
                            }
                        }

                        return;
                    }
                    if (! $ref->hasProperty('client')) {
                        return;
                    }
                    $inner = $ref->getProperty('client')->getValue($cursor);
                    if (! \is_object($inner)) {
                        return;
                    }
                    $cursor = $inner;
                }
            } catch (\Throwable) {
            }
        };
    }

    private static function isPrime(int $n): bool
    {
        if ($n < 2) {
            return false;
        }
        if ($n < 4) {
            return true;
        }
        if (0 === $n % 2 || 0 === $n % 3) {
            return false;
        }
        for ($i = 5; $i * $i <= $n; $i += 6) {
            if (0 === $n % $i || 0 === $n % ($i + 2)) {
                return false;
            }
        }

        return true;
    }
}
