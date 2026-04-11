<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Henderkes\ParallelFork\Channel;
use Henderkes\ParallelFork\Events;
use Henderkes\ParallelFork\Runtime;
use Henderkes\ParallelFork\Sync;
use function Henderkes\ParallelFork\run;
use function Henderkes\ParallelFork\count as cpuCount;

#[Route('/parallel')]
class ParallelController extends AbstractController
{
    #[Route('/test', name: 'parallel_test')]
    public function index(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $tagsBefore = count($em->getRepository(Tag::class)->findAll());

        // 1. Parallel DB READS
        $fPosts = run(function () use ($em) {
            return array_map(
                fn($p) => ['id' => $p->getId(), 'title' => $p->getTitle()],
                $em->getRepository(Post::class)->findAll()
            );
        });

        $fUsers = run(function () use ($em) {
            return array_map(
                fn($u) => ['id' => $u->getId(), 'username' => $u->getUsername()],
                $em->getRepository(User::class)->findAll()
            );
        });

        // 2. Parallel LAZY LOADING — access relations from forked children
        //    Post->getAuthor() is ManyToOne (lazy), Post->getComments() is OneToMany,
        //    Post->getTags() is ManyToMany. All trigger separate DB queries.
        $fLazy = run(function () use ($em) {
            $post = $em->getRepository(Post::class)->find(1);
            if (!$post) return ['error' => 'post not found'];

            // These trigger lazy loading — separate queries in the forked child
            $author = $post->getAuthor();
            $comments = $post->getComments();
            $tags = $post->getTags();

            return [
                'post_title' => $post->getTitle(),
                'author_username' => $author->getUsername(),
                'comment_count' => count($comments),
                'first_comment' => $comments->first() ? $comments->first()->getContent() : null,
                'tag_names' => array_map(fn($t) => $t->getName(), $tags->toArray()),
            ];
        });

        // 3. Parallel DB WRITE — insert a new tag from a forked child
        $fWrite = run(function () use ($em) {
            $tag = new Tag('parallel-' . getmypid() . '-' . time());
            $em->persist($tag);
            $em->flush();
            return ['id' => $tag->getId(), 'name' => $tag->getName()];
        });

        // 3. Parallel FILE WRITE
        $fFile = run(function () {
            $path = '/tmp/frankenphp_parallel_file_test.txt';
            $content = 'Written by child pid=' . getmypid() . ' at ' . date('c');
            file_put_contents($path, $content);
            return ['path' => $path, 'size' => strlen($content)];
        });

        // 4. Parallel CPU work
        $fFib = run(function () {
            $fib = function (int $n) use (&$fib): int {
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
        $tagsAfter = count($em->getRepository(Tag::class)->findAll());

        // Verify: parent can read the child's file
        $fileContent = file_get_contents('/tmp/frankenphp_parallel_file_test.txt');

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'reads' => [
                'posts' => count($posts),
                'users' => count($users),
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
     * Channels: parent sends work items, children process and return results.
     * Each worker gets its own command/result channel pair (anonymous, CoW-inherited).
     */
    #[Route('/channels', name: 'parallel_channels')]
    public function channels(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();

        $inputs = ['hello', 'world', 'parallel'];
        $futures = [];
        $resultChannels = [];

        // Each worker gets its own channel pair
        foreach ($inputs as $i => $input) {
            $cmd = new Channel();
            $res = new Channel();
            $resultChannels[$i] = $res;

            $futures[$i] = $runtime->run(function () use ($cmd, $res) {
                $task = $cmd->recv();
                $res->send([
                    'pid' => getmypid(),
                    'input' => $task,
                    'result' => strtoupper($task),
                ]);
                return true;
            });

            $cmd->send($input);
        }

        // Collect results
        $collected = [];
        foreach ($resultChannels as $res) {
            $collected[] = $res->recv();
            $res->close();
        }

        foreach ($futures as $f) {
            $f->value();
        }

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Named channels: parent dispatches work, children process it',
            'results' => $collected,
        ]);
    }

    /**
     * Anonymous channels: bidirectional parent↔child communication via CoW-inherited channels.
     */
    #[Route('/channels-anon', name: 'parallel_channels_anon')]
    public function channelsAnonymous(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();

        $ch = new Channel();

        // Child sends a sequence of messages, then a sentinel
        $future = $runtime->run(function () use ($ch) {
            $primes = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29];
            foreach ($primes as $p) {
                $ch->send(['prime' => $p, 'squared' => $p * $p, 'pid' => getmypid()]);
            }
            $ch->send('DONE');
            return count($primes);
        });

        // Parent receives until sentinel
        $received = [];
        while (true) {
            $msg = $ch->recv();
            if ($msg === 'DONE') break;
            $received[] = $msg;
        }

        $childCount = $future->value();
        $ch->close();
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Anonymous channel: child streams prime squares to parent',
            'child_sent' => $childCount,
            'parent_received' => count($received),
            'data' => $received,
        ]);
    }

    /**
     * Events: poll multiple futures as they complete (out-of-order collection).
     */
    #[Route('/events', name: 'parallel_events')]
    public function events(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $events = new Events();

        $tasks = [
            'slow'    => 200_000,  // 200ms
            'medium'  => 100_000,  // 100ms
            'fast'    => 10_000,   // 10ms
            'instant' => 0,
        ];

        foreach ($tasks as $name => $sleepUs) {
            $future = $runtime->run(function (int $sleep, string $label) {
                usleep($sleep);
                return ['task' => $label, 'pid' => getmypid(), 'slept_us' => $sleep];
            }, [$sleepUs, $name]);
            $events->addFuture($name, $future);
        }

        // Collect in completion order
        $completionOrder = [];
        foreach ($events as $event) {
            $completionOrder[] = [
                'name' => $event->source,
                'type' => $event->type,
                'value' => $event->value,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Events polling: tasks collected in completion order, not submission order',
            'submission_order' => array_keys($tasks),
            'completion_order' => array_column($completionOrder, 'name'),
            'events' => $completionOrder,
        ]);
    }

    /**
     * Events with error handling: demonstrates polling futures that may succeed or fail.
     */
    #[Route('/events-mixed', name: 'parallel_events_mixed')]
    public function eventsMixed(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $events = new Events();

        // Mix of succeeding and failing tasks
        $f1 = $runtime->run(function () {
            return ['status' => 'ok', 'value' => 'task1 done', 'pid' => getmypid()];
        });
        $events->addFuture('success_1', $f1);

        $f2 = $runtime->run(function () {
            usleep(50_000);
            throw new \RuntimeException('task2 intentional failure');
        });
        $events->addFuture('will_fail', $f2);

        $f3 = $runtime->run(function () {
            usleep(20_000);
            return ['status' => 'ok', 'value' => 'task3 done', 'pid' => getmypid()];
        });
        $events->addFuture('success_2', $f3);

        $f4 = $runtime->run(function () {
            usleep(10_000);
            return ['status' => 'ok', 'value' => 'task4 done', 'pid' => getmypid()];
        });
        // Cancel this one before it completes
        $events->addFuture('will_cancel', $f4);

        $collected = [];
        foreach ($events as $event) {
            $collected[] = [
                'source' => $event->source,
                'type' => $event->type,
                'value' => $event->value,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }

        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Events: polling a mix of succeeding and failing futures',
            'events' => $collected,
        ]);
    }

    /**
     * Sync: shared memory counter incremented by multiple children atomically.
     */
    #[Route('/sync', name: 'parallel_sync')]
    public function sync(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $counter = new Sync(0);
        $workerCount = 4;
        $incrementsPerWorker = 100;

        $futures = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $futures["worker_$i"] = $runtime->run(
                function (int $increments) use ($counter) {
                    $local = 0;
                    for ($j = 0; $j < $increments; $j++) {
                        $counter(function () use ($counter) {
                            $counter->set($counter->get() + 1);
                        });
                        $local++;
                    }
                    return ['pid' => getmypid(), 'incremented' => $local];
                },
                [$incrementsPerWorker]
            );
        }

        $workerResults = [];
        foreach ($futures as $name => $f) {
            $workerResults[$name] = $f->value();
        }

        $finalCount = $counter->get();
        $expected = $workerCount * $incrementsPerWorker;
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => "$workerCount workers each increment a shared counter $incrementsPerWorker times with mutex",
            'expected' => $expected,
            'actual' => $finalCount,
            'correct' => $finalCount === $expected,
            'workers' => $workerResults,
        ]);
    }

    /**
     * Sync wait/notify: producer-consumer pattern with shared memory signalling.
     */
    #[Route('/sync-notify', name: 'parallel_sync_notify')]
    public function syncNotify(): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();
        $signal = new Sync(0);

        // Child waits for parent's signal before proceeding
        $future = $runtime->run(function () use ($signal) {
            $before = microtime(true);
            $signal->wait();
            $waited_ms = round((microtime(true) - $before) * 1000, 1);
            $value = $signal->get();
            return [
                'pid' => getmypid(),
                'received_value' => $value,
                'waited_ms' => $waited_ms,
            ];
        });

        // Parent does some work, then signals the child
        usleep(50_000); // 50ms delay
        $signal->set(42);
        $signal->notify();

        $result = $future->value();
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Sync wait/notify: child blocks until parent signals with a value',
            'child_result' => $result,
        ]);
    }

    /**
     * Runtime: explicit lifecycle management, multiple runtimes, afterFork hooks.
     */
    #[Route('/runtime', name: 'parallel_runtime')]
    public function runtimeTest(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);

        $hookFile = tempnam('/tmp', 'parallel_hook_');
        Runtime::afterFork(function () use ($hookFile) {
            file_put_contents($hookFile, 'afterFork ran in pid=' . getmypid());
        });

        // Two independent runtimes running concurrently
        $rt1 = new Runtime();
        $rt2 = new Runtime();

        $f1 = $rt1->run(function () use ($em) {
            $count = count($em->getRepository(Post::class)->findAll());
            return ['runtime' => 1, 'pid' => getmypid(), 'post_count' => $count];
        });

        $f2 = $rt2->run(function () use ($em) {
            $count = count($em->getRepository(User::class)->findAll());
            return ['runtime' => 2, 'pid' => getmypid(), 'user_count' => $count];
        });

        // Multiple tasks on the same runtime
        $f3 = $rt1->run(function (int $n) {
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
            'description' => 'Explicit Runtime lifecycle: two runtimes, afterFork hook, multiple tasks per runtime',
            'parent_pid' => getmypid(),
            'results' => $results,
            'after_fork_hook' => $hookContent,
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
        $fast = $runtime->run(function () {
            return 'instant';
        });
        usleep(50_000); // give it time to finish
        $doneResult = [
            'done_before_value' => $fast->done(),
            'value' => $fast->value(),
            'done_after_value' => $fast->done(),
        ];

        // 2. Cancel a long-running task
        $slow = $runtime->run(function () {
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
        $failing = $runtime->run(function () {
            throw new \RuntimeException('Intentional child error');
        });
        $errorResult = ['propagated' => false, 'message' => null];
        try {
            $failing->value();
        } catch (\Throwable $e) {
            $errorResult = [
                'propagated' => true,
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ];
        }

        // 4. Passing arguments to run()
        $withArgs = $runtime->run(function (string $greeting, int $count) {
            return str_repeat($greeting . ' ', $count);
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
     * Fan-out/fan-in: parallel map over a dataset using cpuCount() for CPU detection.
     */
    #[Route('/fan-out', name: 'parallel_fan_out')]
    public function fanOut(): JsonResponse
    {
        $start = microtime(true);
        $numCpus = cpuCount();

        $data = range(1, 120);
        $chunks = array_chunk($data, (int) ceil(count($data) / $numCpus));

        $runtime = new Runtime();
        $futures = [];
        foreach ($chunks as $i => $chunk) {
            $futures[$i] = $runtime->run(function (array $numbers) {
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

        $primes = array_column(array_filter($allResults, fn($r) => $r['is_prime']), 'n');
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => "Fan-out/fan-in: split 120 items across $numCpus workers (detected CPUs)",
            'cpu_count' => $numCpus,
            'chunks' => count($chunks),
            'total_processed' => count($allResults),
            'primes_found' => $primes,
            'pid_distribution' => $pidDistribution,
        ]);
    }

    /**
     * Pipeline: chain of stages connected by channels, each stage in its own fork.
     */
    #[Route('/pipeline', name: 'parallel_pipeline')]
    public function pipeline(EntityManagerInterface $em): JsonResponse
    {
        $start = microtime(true);
        $runtime = new Runtime();

        // Stage 1 → Stage 2 → Stage 3, connected by channels
        $pipe1 = new Channel(); // stage1 → stage2
        $pipe2 = new Channel(); // stage2 → stage3
        $pipe3 = new Channel(); // stage3 → parent

        // Stage 1: fetch posts from DB, send titles downstream
        $f1 = $runtime->run(function () use ($em, $pipe1) {
            $posts = $em->getRepository(Post::class)->findBy([], ['id' => 'ASC'], 5);
            foreach ($posts as $post) {
                $pipe1->send(['id' => $post->getId(), 'title' => $post->getTitle()]);
            }
            $pipe1->send('END');
            return 'stage1: sent ' . count($posts) . ' posts';
        });

        // Stage 2: transform titles
        $f2 = $runtime->run(function () use ($pipe1, $pipe2) {
            while (true) {
                $msg = $pipe1->recv();
                if ($msg === 'END') {
                    $pipe2->send('END');
                    break;
                }
                $msg['title_upper'] = strtoupper($msg['title']);
                $msg['word_count'] = str_word_count($msg['title']);
                $msg['stage2_pid'] = getmypid();
                $pipe2->send($msg);
            }
            return 'stage2: transform complete';
        });

        // Stage 3: enrich with character analysis
        $f3 = $runtime->run(function () use ($pipe2, $pipe3) {
            while (true) {
                $msg = $pipe2->recv();
                if ($msg === 'END') {
                    $pipe3->send('END');
                    break;
                }
                $msg['char_count'] = strlen($msg['title']);
                $msg['vowel_count'] = preg_match_all('/[aeiou]/i', $msg['title']);
                $msg['stage3_pid'] = getmypid();
                $pipe3->send($msg);
            }
            return 'stage3: enrich complete';
        });

        // Parent collects final results
        $pipelineResults = [];
        while (true) {
            $msg = $pipe3->recv();
            if ($msg === 'END') break;
            $pipelineResults[] = $msg;
        }

        $stageReports = [
            'stage1' => $f1->value(),
            'stage2' => $f2->value(),
            'stage3' => $f3->value(),
        ];

        $pipe1->close();
        $pipe2->close();
        $pipe3->close();
        $runtime->close();

        return $this->json([
            'elapsed_s' => round(microtime(true) - $start, 4),
            'description' => 'Pipeline: 3 stages connected by channels (DB fetch → transform → enrich)',
            'stage_reports' => $stageReports,
            'pipeline_output' => $pipelineResults,
        ]);
    }

    private static function isPrime(int $n): bool
    {
        if ($n < 2) return false;
        if ($n < 4) return true;
        if ($n % 2 === 0 || $n % 3 === 0) return false;
        for ($i = 5; $i * $i <= $n; $i += 6) {
            if ($n % $i === 0 || $n % ($i + 2) === 0) return false;
        }
        return true;
    }
}
