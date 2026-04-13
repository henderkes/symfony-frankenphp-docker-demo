<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

use function Symfony\Component\String\u;

final class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->loadUsers($manager);
        $this->loadTags($manager);
        $this->loadPosts($manager);
    }

    private function loadUsers(ObjectManager $manager): void
    {
        foreach ($this->getUserData() as [$fullname, $username, $password, $email, $roles]) {
            $user = new User();
            $user->setFullName($fullname);
            $user->setUsername($username);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setEmail($email);
            $user->setRoles($roles);

            $manager->persist($user);

            $this->addReference($username, $user);
        }

        $manager->flush();
    }

    private function loadTags(ObjectManager $manager): void
    {
        foreach ($this->getTagData() as $name) {
            $tag = new Tag($name);

            $manager->persist($tag);

            $this->addReference('tag-'.$name, $tag);
        }

        $manager->flush();
    }

    private function loadPosts(ObjectManager $manager): void
    {
        $phrases = $this->getPhrases();
        $adjectives = $this->getAdjectives();
        $nouns = $this->getNouns();
        $verbs = $this->getVerbs();
        $users = ['jane_admin', 'tom_admin', 'john_user', 'alice_editor', 'bob_writer',
            'carol_author', 'dave_contrib', 'eve_reviewer', 'frank_admin', 'grace_writer'];
        $contentTemplates = $this->getContentTemplates();

        $batchSize = 50;

        for ($i = 0; $i < 1200; ++$i) {
            // Generate varied titles
            if ($i < \count($phrases)) {
                $title = $phrases[$i];
            } else {
                $adj = $adjectives[$i % \count($adjectives)];
                $noun = $nouns[$i % \count($nouns)];
                $verb = $verbs[$i % \count($verbs)];
                $num = $i + 1;
                $patterns = [
                    "$adj $noun: a deep dive into modern practices (#$num)",
                    "How to $verb your $noun effectively (#$num)",
                    "The $adj guide to $noun management (#$num)",
                    "Why every developer should $verb $noun (#$num)",
                    "$noun and $adj systems: lessons learned (#$num)",
                    "Understanding $adj $noun in production (#$num)",
                    "Building $adj $noun with best practices (#$num)",
                    "$adj approaches to $verb $noun (#$num)",
                ];
                $title = $patterns[$i % \count($patterns)];
            }

            $post = new Post();
            $post->setTitle($title);
            $post->setSlug($this->slugger->slug($title)->lower());
            $post->setSummary($this->getRandomText());
            $post->setContent($contentTemplates[$i % \count($contentTemplates)]);
            $post->setPublishedAt(
                (new \DateTimeImmutable('now - '.$i.'hours'))
                    ->setTime(random_int(0, 23), random_int(0, 59), random_int(0, 59))
            );

            // First post always jane_admin for tests
            $authorRef = 0 === $i ? 'jane_admin' : $users[array_rand($users)];
            $post->setAuthor($this->getReference($authorRef, User::class));
            $post->addTag(...$this->getRandomTags());

            // Vary comment count: some posts have many, some have none
            $commentCount = match (true) {
                0 === $i % 10 => random_int(8, 15),  // every 10th post: lots of comments
                0 === $i % 3 => 0,                     // every 3rd post: no comments
                default => random_int(1, 5),
            };

            for ($c = 0; $c < $commentCount; ++$c) {
                $comment = new Comment();
                $comment->setAuthor($this->getReference($users[array_rand($users)], User::class));
                $comment->setContent($this->getRandomText(random_int(100, 512)));
                $comment->setPublishedAt(new \DateTimeImmutable('now - '.$i.'hours + '.$c.'minutes'));
                $post->addComment($comment);
            }

            $manager->persist($post);

            if (($i + 1) % $batchSize === 0) {
                $manager->flush();
                $manager->clear();

                // Re-fetch tag and user references after clear
                foreach ($this->getTagData() as $name) {
                    if (!$this->hasReference('tag-'.$name, Tag::class)) {
                        $tag = $manager->getRepository(Tag::class)->findOneBy(['name' => $name]);
                        if ($tag) {
                            $this->addReference('tag-'.$name, $tag);
                        }
                    }
                }
                foreach ($this->getUserData() as [$fullname, $username]) {
                    if (!$this->hasReference($username, User::class)) {
                        $user = $manager->getRepository(User::class)->findOneBy(['username' => $username]);
                        if ($user) {
                            $this->addReference($username, $user);
                        }
                    }
                }
            }
        }

        $manager->flush();
    }

    /**
     * @return array<array{string, string, string, string, array<string>}>
     */
    private function getUserData(): array
    {
        return [
            ['Jane Doe', 'jane_admin', 'kitten', 'jane_admin@symfony.com', [User::ROLE_ADMIN]],
            ['Tom Doe', 'tom_admin', 'kitten', 'tom_admin@symfony.com', [User::ROLE_ADMIN]],
            ['John Doe', 'john_user', 'kitten', 'john_user@symfony.com', [User::ROLE_USER]],
            ['Alice Smith', 'alice_editor', 'kitten', 'alice@symfony.com', [User::ROLE_ADMIN]],
            ['Bob Johnson', 'bob_writer', 'kitten', 'bob@symfony.com', [User::ROLE_USER]],
            ['Carol Williams', 'carol_author', 'kitten', 'carol@symfony.com', [User::ROLE_USER]],
            ['Dave Brown', 'dave_contrib', 'kitten', 'dave@symfony.com', [User::ROLE_USER]],
            ['Eve Davis', 'eve_reviewer', 'kitten', 'eve@symfony.com', [User::ROLE_ADMIN]],
            ['Frank Miller', 'frank_admin', 'kitten', 'frank@symfony.com', [User::ROLE_ADMIN]],
            ['Grace Wilson', 'grace_writer', 'kitten', 'grace@symfony.com', [User::ROLE_USER]],
        ];
    }

    /**
     * @return string[]
     */
    private function getTagData(): array
    {
        return [
            'lorem', 'ipsum', 'consectetur', 'adipiscing', 'incididunt',
            'labore', 'voluptate', 'dolore', 'pariatur', 'symfony',
            'php', 'performance', 'architecture', 'testing', 'security',
            'database', 'api', 'frontend', 'devops', 'tutorial',
            'advanced', 'beginner', 'patterns', 'refactoring', 'concurrency',
        ];
    }

    /**
     * @return string[]
     */
    private function getPhrases(): array
    {
        return [
            'Lorem ipsum dolor sit amet consectetur adipiscing elit',
            'Pellentesque vitae velit ex',
            'Mauris dapibus risus quis suscipit vulputate',
            'Eros diam egestas libero eu vulputate risus',
            'In hac habitasse platea dictumst',
            'Morbi tempus commodo mattis',
            'Ut suscipit posuere justo at vulputate',
            'Ut eleifend mauris et risus ultrices egestas',
            'Aliquam sodales odio id eleifend tristique',
            'Urna nisl sollicitudin id varius orci quam id turpis',
            'Nulla porta lobortis ligula vel egestas',
            'Curabitur aliquam euismod dolor non ornare',
            'Sed varius a risus eget aliquam',
            'Nunc viverra elit ac laoreet suscipit',
            'Pellentesque et sapien pulvinar consectetur',
            'Ubi est barbatus nix',
            'Abnobas sunt hilotaes de placidus vita',
            'Ubi est audax amicitia',
            'Eposs sunt solems de superbus fortis',
            'Vae humani generis',
            'Diatrias tolerare tanquam noster caesium',
            'Teres talis saepe tractare de camerarius flavum sensorem',
            'Silva de secundus galatae demitto quadra',
            'Sunt accentores vitare salvus flavum parses',
            'Potus sensim ad ferox abnoba',
            'Sunt seculaes transferre talis camerarius fluctuies',
            'Era brevis ratione est',
            'Sunt torquises imitari velox mirabilis medicinaes',
            'Mineralis persuadere omnes finises desiderium',
            'Bassus fatalis classiss virtualiter transferre de flavum',
        ];
    }

    /** @return string[] */
    private function getAdjectives(): array
    {
        return [
            'scalable', 'resilient', 'async', 'distributed', 'reactive',
            'immutable', 'stateless', 'event-driven', 'composable', 'modular',
            'robust', 'efficient', 'concurrent', 'declarative', 'functional',
            'observable', 'portable', 'idempotent', 'fault-tolerant', 'high-performance',
        ];
    }

    /** @return string[] */
    private function getNouns(): array
    {
        return [
            'microservice', 'pipeline', 'middleware', 'serializer', 'container',
            'dispatcher', 'resolver', 'compiler', 'optimizer', 'scheduler',
            'validator', 'transformer', 'authenticator', 'normalizer', 'listener',
            'subscriber', 'provider', 'adapter', 'decorator', 'factory',
        ];
    }

    /** @return string[] */
    private function getVerbs(): array
    {
        return [
            'optimize', 'refactor', 'benchmark', 'parallelize', 'instrument',
            'deprecate', 'decouple', 'containerize', 'orchestrate', 'serialize',
            'normalize', 'hydrate', 'memoize', 'throttle', 'debounce',
            'aggregate', 'compose', 'dispatch', 'resolve', 'transform',
        ];
    }

    private function getRandomText(int $maxLength = 255): string
    {
        $phrases = $this->getPhrases();
        shuffle($phrases);

        do {
            $text = u('. ')->join($phrases)->append('.');
            array_pop($phrases);
        } while ($text->length() > $maxLength);

        return $text;
    }

    /** @return string[] */
    private function getContentTemplates(): array
    {
        return [
            <<<'MARKDOWN'
            Lorem ipsum dolor sit amet consectetur adipisicing elit, sed do eiusmod tempor
            incididunt ut labore et **dolore magna aliqua**: Duis aute irure dolor in
            reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.
            Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia
            deserunt mollit anim id est laborum.

              * Ut enim ad minim veniam
              * Quis nostrud exercitation *ullamco laboris*
              * Nisi ut aliquip ex ea commodo consequat

            Praesent id fermentum lorem. Ut est lorem, fringilla at accumsan nec, euismod at
            nunc. Aenean mattis sollicitudin mattis. Nullam pulvinar vestibulum bibendum.
            Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos
            himenaeos. Fusce nulla purus, gravida ac interdum ut, blandit eget ex. Duis a
            luctus dolor.

            Integer auctor massa maximus nulla scelerisque accumsan. *Aliquam ac malesuada*
            ex. Pellentesque tortor magna, vulputate eu vulputate ut, venenatis ac lectus.
            Praesent ut lacinia sem. Mauris a lectus eget felis mollis feugiat. Quisque
            efficitur, mi ut semper pulvinar, urna urna blandit massa, eget tincidunt augue
            nulla vitae est.

            Ut posuere aliquet tincidunt. Aliquam erat volutpat. **Class aptent taciti**
            sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Morbi
            arcu orci, gravida eget aliquam eu, suscipit et ante. Morbi vulputate metus vel
            ipsum finibus, ut dapibus massa feugiat. Vestibulum vel lobortis libero. Sed
            tincidunt tellus et viverra scelerisque. Pellentesque tincidunt cursus felis.
            Sed in egestas erat.

            Aliquam pulvinar interdum massa, vel ullamcorper ante consectetur eu. Vestibulum
            lacinia ac enim vel placerat. Integer pulvinar magna nec dui malesuada, nec
            congue nisl dictum. Donec mollis nisl tortor, at congue erat consequat a. Nam
            tempus elit porta, blandit elit vel, viverra lorem. Sed sit amet tellus
            tincidunt, faucibus nisl in, aliquet libero.
            MARKDOWN,
            <<<'MARKDOWN'
            ## Understanding the Problem

            When building modern web applications, **performance** is not just a feature — it's
            a requirement. Users expect sub-second response times, and search engines penalize
            slow pages. This post explores practical techniques for optimization.

            ### Key Metrics

            1. Time to First Byte (TTFB)
            2. Largest Contentful Paint (LCP)
            3. Cumulative Layout Shift (CLS)

            > "Premature optimization is the root of all evil" — Donald Knuth

            However, *measured* optimization based on profiling data is the root of all
            performance gains. Let's look at real-world examples.

            ### Database Optimization

            ```sql
            -- Before: full table scan
            SELECT * FROM posts WHERE status = 'published' ORDER BY created_at DESC;

            -- After: indexed query with pagination
            SELECT id, title, summary FROM posts
            WHERE status = 'published'
            ORDER BY created_at DESC
            LIMIT 20 OFFSET 0;
            ```

            The difference? From 850ms to 12ms on a table with 500K rows.

            ### Caching Strategy

            Use a **multi-layer cache**:
              * L1: In-memory (APCu) — microsecond access
              * L2: Redis — millisecond access
              * L3: Database — last resort

            Each layer has different TTLs and invalidation strategies.
            MARKDOWN,
            <<<'MARKDOWN'
            ## Architecture Decisions

            Every system is the sum of its architectural decisions. Some decisions are
            **reversible** (which framework to use), others are **irreversible** (which
            database engine to choose). Focus your deliberation accordingly.

            ### The Monolith-First Approach

            Start with a well-structured monolith. Extract services only when you have:

              * Clear bounded contexts
              * Independent scaling requirements
              * Team boundaries that align with service boundaries

            ### Event-Driven Communication

            Instead of synchronous HTTP calls between services:

            ```
            Service A → Message Bus → Service B
                                    → Service C
                                    → Service D
            ```

            Benefits:
              * **Decoupling**: Services don't need to know about each other
              * **Resilience**: Failed consumers retry independently
              * **Scalability**: Add consumers without changing producers

            ### Data Ownership

            Each service owns its data. No shared databases. If Service B needs data
            from Service A, it either:

            1. Subscribes to Service A's events and maintains a local projection
            2. Makes an API call (with caching and circuit breakers)
            3. Uses a shared read model (CQRS pattern)
            MARKDOWN,
            <<<'MARKDOWN'
            ## Testing in Production

            Yes, you read that right. Testing *in production* — not instead of staging,
            but in addition to it. Here's why and how.

            ### Why Staging Lies

            Staging environments differ from production in subtle but critical ways:

              * Different data volumes (10K rows vs 10M rows)
              * Different traffic patterns (no real users)
              * Different infrastructure (smaller instances)
              * Different integrations (sandbox APIs)

            ### Canary Deployments

            Route a small percentage of traffic to the new version:

            ```yaml
            # nginx.conf
            upstream backend {
                server app-v1:8080 weight=95;
                server app-v2:8080 weight=5;
            }
            ```

            Monitor error rates, latency percentiles, and business metrics. If anything
            degrades, roll back automatically.

            ### Feature Flags

            Decouple **deployment** from **release**:

              * Deploy code to 100% of servers
              * Enable feature for 1% of users
              * Gradually increase to 5%, 25%, 100%
              * Kill switch: disable instantly without redeployment

            ### Observability

            You can't test what you can't see. Invest in:

            1. **Structured logging** (JSON, correlation IDs)
            2. **Distributed tracing** (OpenTelemetry)
            3. **Custom metrics** (business KPIs, not just CPU/memory)
            4. **Alerting** (on symptoms, not causes)
            MARKDOWN,
            <<<'MARKDOWN'
            ## Concurrency Patterns in PHP

            PHP's traditional request-per-process model is simple but limiting. Modern
            PHP offers several concurrency approaches.

            ### Fork-Based Parallelism

            Using `pcntl_fork()`, a parent process creates child processes that inherit
            its full state via OS copy-on-write:

            ```php
            $futures = [];
            foreach ($chunks as $chunk) {
                $futures[] = run(function () use ($chunk) {
                    return processChunk($chunk);
                });
            }

            $results = array_map(fn ($f) => $f->value(), $futures);
            ```

            **Advantages**: Full state inheritance, true parallelism, no serialization
            overhead for captured variables.

            **Challenges**: Connection management (database, Redis, HTTP clients must
            be reset in child processes), memory overhead per process.

            ### Async I/O

            For I/O-bound workloads, async libraries like ReactPHP or AMPHP multiplex
            operations on a single thread:

            ```php
            $promises = [];
            foreach ($urls as $url) {
                $promises[] = $httpClient->request('GET', $url);
            }
            $responses = await(all($promises));
            ```

            **Advantages**: Low memory footprint, excellent for HTTP calls and database
            queries.

            **Challenges**: Callback complexity, limited CPU parallelism, ecosystem
            compatibility.

            ### Choosing the Right Model

            | Workload | Best Approach |
            |----------|--------------|
            | CPU-bound computation | Fork (pcntl) |
            | Many HTTP API calls | Async I/O |
            | Mixed CPU + I/O | Fork with async per child |
            | Real-time streaming | Event loop (ReactPHP) |
            MARKDOWN,
        ];
    }

    /**
     * @return array<Tag>
     *
     * @throws \Exception
     */
    private function getRandomTags(): array
    {
        $tagNames = $this->getTagData();
        shuffle($tagNames);
        $selectedTags = \array_slice($tagNames, 0, random_int(2, 5));

        return array_map(
            fn ($tagName) => $this->getReference('tag-'.$tagName, Tag::class),
            $selectedTags
        );
    }
}
