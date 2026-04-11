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

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use App\Event\CommentCreatedEvent;
use App\Form\CommentType;
use App\Pagination\Paginator;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

use function Henderkes\ParallelFork\run;

/**
 * Controller used to manage blog contents in the public part of the site.
 *
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
#[Route('/blog')]
final class BlogController extends AbstractController
{
    /**
     * NOTE: For standard formats, Symfony will also automatically choose the best
     * Content-Type header for the response.
     *
     * See https://symfony.com/doc/current/routing.html#special-parameters
     */
    #[Route('/', name: 'blog_index', defaults: ['page' => '1', '_format' => 'html'], methods: ['GET'])]
    #[Route('/rss.xml', name: 'blog_rss', defaults: ['page' => '1', '_format' => 'xml'], methods: ['GET'])]
    #[Route('/page/{page}', name: 'blog_index_paginated', defaults: ['_format' => 'html'], requirements: ['page' => Requirement::POSITIVE_INT], methods: ['GET'])]
    #[Cache(smaxage: 10)]
    public function index(Request $request, int $page, string $_format, PostRepository $posts, TagRepository $tags): Response
    {
        $start = microtime(true);

        $tag = null;

        if ($request->query->has('tag')) {
            $tag = $tags->findOneBy(['name' => $request->query->get('tag')]);
        }

        $latestPosts = $posts->findLatest($page, $tag);

        return $this->render('blog/index.'.$_format.'.twig', [
            'paginator' => $latestPosts,
            'tagName' => $tag?->getName(),
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
        ]);
    }

    #[Route('/all', name: 'blog_all', defaults: ['num' => '0'], methods: ['GET'])]
    #[Route('/all/{num}', name: 'blog_all_fork', requirements: ['num' => '\d+'], methods: ['GET'])]
    #[Cache(smaxage: 10)]
    public function all(Request $request, int $num, PostRepository $posts, TagRepository $tags, Environment $twig, EntityManagerInterface $em): Response
    {
        $start = microtime(true);

        $tag = null;
        $activeTag = $request->query->get('tag');

        if ($activeTag) {
            $tag = $tags->findOneBy(['name' => $activeTag]);
        }

        $firstPage = $posts->findLatest(1, $tag);
        $totalPages = $firstPage->getLastPage();
        $numResults = $firstPage->getNumResults();

        $tagName = $tag?->getName();
        $numWorkers = max(0, $num);
        $pages = [];

        // Split pages across main process + n workers (n forks total)
        $allPages = range(1, $totalPages);
        $chunks = array_chunk($allPages, (int) ceil($totalPages / ($numWorkers + 1)));

        // Inline render closure — captures $em and $twig directly (not nested)
        // so the library's connection scanner finds and reconnects them.
        $renderChunk = static function (array $pageNums, ?string $tagName, ?string $activeTag) use ($em, $twig): array {
            $result = [];
            foreach ($pageNums as $pageNum) {
                $repo = $em->getRepository(Post::class);

                $qb = $repo->createQueryBuilder('p')
                    ->addSelect('a', 't')
                    ->innerJoin('p.author', 'a')
                    ->leftJoin('p.tags', 't')
                    ->where('p.publishedAt <= :now')
                    ->orderBy('p.publishedAt', 'DESC')
                    ->setParameter('now', new \DateTimeImmutable());

                if ($tagName) {
                    $tagEntity = $em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
                    if ($tagEntity) {
                        $qb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
                    }
                }

                $paginator = (new Paginator($qb))->paginate($pageNum);

                $result[$pageNum] = $twig->render('blog/_posts_list.html.twig', [
                    'posts' => iterator_to_array($paginator->getResults()),
                    'activeTag' => $activeTag,
                ]);
            }

            return $result;
        };

        // Fork n workers, each renders its chunk sequentially
        $futures = [];
        for ($i = 1; $i <= $numWorkers && $i < \count($chunks); ++$i) {
            $futures[] = run($renderChunk, [$chunks[$i], $tagName, $activeTag]);
        }

        // Main process renders its chunk
        $pages = $renderChunk($chunks[0], $tagName, $activeTag);

        // Collect forked results
        foreach ($futures as $future) {
            foreach ($future->value() as $p => $html) {
                $pages[$p] = $html;
            }
        }

        ksort($pages);
        $elapsedMs = round((microtime(true) - $start) * 1000, 1);

        return $this->render('blog/index_parallel.html.twig', [
            'pages' => $pages,
            'currentPage' => 1,
            'totalPages' => $totalPages,
            'numResults' => $numResults,
            'elapsed_ms' => $elapsedMs,
            'mode' => $numWorkers > 0 ? "parallel ($numWorkers threads)" : 'single',
            'tagName' => $activeTag,
        ]);
    }

    #[Route('/parallel', name: 'blog_ext_parallel', defaults: ['num' => '0'], methods: ['GET'])]
    #[Route('/parallel/{num}', name: 'blog_ext_parallel_num', requirements: ['num' => '\d+'], methods: ['GET'])]
    #[Cache(smaxage: 10)]
    public function extParallel(Request $request, int $num, PostRepository $posts, TagRepository $tags, Environment $twig, EntityManagerInterface $em): Response
    {
        if (!\extension_loaded('parallel')) {
            return $this->json(['error' => 'ext-parallel not available, use php-zts'], 500);
        }

        $start = microtime(true);

        $tag = null;
        $activeTag = $request->query->get('tag');

        if ($activeTag) {
            $tag = $tags->findOneBy(['name' => $activeTag]);
        }

        $firstPage = $posts->findLatest(1, $tag);
        $totalPages = $firstPage->getLastPage();
        $numResults = $firstPage->getNumResults();

        $numWorkers = max(0, $num);
        $tagName = $tag?->getName();

        $renderPage = $this->makeRenderPage($em, $twig);

        // Each thread boots its own kernel and gets PageRenderer
        // from the parallel.services locator (no public services needed).
        $threadRenderChunk = static function (array $pageNums, ?string $tagName, ?string $activeTag): array {
            $kernel = \App\Kernel::bootForParallel();
            $renderer = $kernel->getContainer()->get('parallel.services')->get(\App\Service\PageRenderer::class);

            $result = [];
            foreach ($pageNums as $p) {
                $result[$p] = $renderer->render($p, $tagName, $activeTag);
            }

            $kernel->shutdown();

            return $result;
        };

        $bootstrapPath = $this->getParameter('kernel.project_dir').'/parallel_bootstrap.php';

        // Split pages across main thread + n workers
        $allPages = range(1, $totalPages);
        $chunks = array_chunk($allPages, (int) ceil($totalPages / ($numWorkers + 1)));

        // Spawn n threads, each boots a kernel and renders its chunk
        $futures = [];
        for ($i = 1; $i <= $numWorkers && $i < \count($chunks); ++$i) {
            $runtime = new \parallel\Runtime($bootstrapPath);
            $futures[] = $runtime->run($threadRenderChunk, [
                $chunks[$i], $tagName, $activeTag,
            ]);
        }

        // Main thread renders its chunk
        $pages = [];
        foreach ($chunks[0] as $p) {
            $pages[$p] = $renderPage($p, $tagName, $activeTag);
        }

        // Collect thread results
        foreach ($futures as $future) {
            foreach ($future->value() as $p => $html) {
                $pages[$p] = $html;
            }
        }

        ksort($pages);
        $elapsedMs = round((microtime(true) - $start) * 1000, 1);

        return $this->render('blog/index_parallel.html.twig', [
            'pages' => $pages,
            'currentPage' => 1,
            'totalPages' => $totalPages,
            'numResults' => $numResults,
            'elapsed_ms' => $elapsedMs,
            'mode' => $numWorkers > 0 ? "ext-parallel ($numWorkers threads)" : 'ext-parallel (single)',
            'tagName' => $activeTag,
        ]);
    }

    private function makeRenderPage(EntityManagerInterface $em, Environment $twig): \Closure
    {
        return static function (int $pageNum, ?string $tagName, ?string $activeTag) use ($em, $twig): string {
            $repo = $em->getRepository(Post::class);

            $qb = $repo->createQueryBuilder('p')
                ->addSelect('a', 't')
                ->innerJoin('p.author', 'a')
                ->leftJoin('p.tags', 't')
                ->where('p.publishedAt <= :now')
                ->orderBy('p.publishedAt', 'DESC')
                ->setParameter('now', new \DateTimeImmutable());

            if ($tagName) {
                $tagEntity = $em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
                if ($tagEntity) {
                    $qb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
                }
            }

            $paginator = (new Paginator($qb))->paginate($pageNum);

            return $twig->render('blog/_posts_list.html.twig', [
                'posts' => iterator_to_array($paginator->getResults()),
                'activeTag' => $activeTag,
            ]);
        };
    }

    /**
     * NOTE: when the controller argument is a Doctrine entity, Symfony makes an
     * automatic database query to fetch it based on the value of the route parameters.
     * The '{slug:post}' configuration tells Symfony to use the 'slug' route
     * parameter in the database query that fetches the entity of the $post argument.
     * This is mostly useful when the route has multiple parameters and the controller
     * also has multiple arguments.
     * See https://symfony.com/doc/current/doctrine.html#automatically-fetching-objects-entityvalueresolver.
     */
    #[Route('/posts/{slug:post}', name: 'blog_post', requirements: ['slug' => Requirement::ASCII_SLUG], methods: ['GET'])]
    public function postShow(Post $post): Response
    {
        // Symfony's 'dump()' function is an improved version of PHP's 'var_dump()' but
        // it's not available in the 'prod' environment to prevent leaking sensitive information.
        // It can be used both in PHP files and Twig templates, but it requires to
        // have enabled the DebugBundle. Uncomment the following line to see it in action:
        //
        // dump($post, $this->getUser(), new \DateTime());
        //
        // The result will be displayed either in the Symfony Profiler or in the stream output.
        // See https://symfony.com/doc/current/profiler.html
        // See https://symfony.com/doc/current/templates.html#the-dump-twig-utilities
        //
        // You can also leverage Symfony's 'dd()' function that dumps and
        // stops the execution

        return $this->render('blog/post_show.html.twig', ['post' => $post]);
    }

    /**
     * NOTE: The #[MapEntity] mapping is required because the route parameter
     * (postSlug) doesn't match any of the Doctrine entity properties (slug).
     *
     * See https://symfony.com/doc/current/doctrine.html#doctrine-entity-value-resolver
     */
    #[Route('/comment/{postSlug}/new', name: 'comment_new', requirements: ['postSlug' => Requirement::ASCII_SLUG], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function commentNew(
        #[CurrentUser] User $user,
        Request $request,
        #[MapEntity(mapping: ['postSlug' => 'slug'])] Post $post,
        EventDispatcherInterface $eventDispatcher,
        EntityManagerInterface $entityManager,
    ): Response {
        $comment = new Comment();
        $comment->setAuthor($user);
        $post->addComment($comment);

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($comment);
            $entityManager->flush();

            // When an event is dispatched, Symfony notifies it to all the listeners
            // and subscribers registered to it. Listeners can modify the information
            // passed in the event and they can even modify the execution flow, so
            // there's no guarantee that the rest of this controller will be executed.
            // See https://symfony.com/doc/current/components/event_dispatcher.html
            //
            // If you prefer to process comments asynchronously (e.g. to perform some
            // heavy tasks on them) you can use the Symfony Messenger component.
            // See https://symfony.com/doc/current/messenger.html
            $eventDispatcher->dispatch(new CommentCreatedEvent($comment));

            return $this->redirectToRoute('blog_post', ['slug' => $post->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('blog/comment_form_error.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    /**
     * This controller is called directly via the render() function in the
     * blog/post_show.html.twig template. That's why it's not needed to define
     * a route name for it.
     */
    public function commentForm(Post $post): Response
    {
        $form = $this->createForm(CommentType::class);

        return $this->render('blog/_comment_form.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    #[Route('/search', name: 'blog_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        return $this->render('blog/search.html.twig', ['query' => (string) $request->query->get('q', '')]);
    }
}
