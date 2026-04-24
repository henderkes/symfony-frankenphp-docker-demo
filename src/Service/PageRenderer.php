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

use App\Entity\Post;
use App\Entity\Tag;
use App\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class PageRenderer
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    /**
     * Fetch a page of posts as plain arrays (serializable, fork-safe).
     *
     * @return list<array{title: string, slug: string, summary: string, publishedAt: \DateTimeImmutable, authorFullName: string, tags: list<string>}>
     */
    public function fetch(int $pageNum, ?string $tagName): array
    {
        return $this->fetchChunk([$pageNum], $tagName)[$pageNum] ?? [];
    }

    /**
     * Count distinct posts matching the filter (used to derive lastPage
     * without hydrating the whole result set).
     *
     * @return array{0: int, 1: int} [numResults, lastPage]
     */
    public function countPages(?string $tagName): array
    {
        $qb = $this->em->getRepository(Post::class)->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->where('p.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable());

        if ($tagName) {
            $tagEntity = $this->em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
            if ($tagEntity) {
                $qb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
            }
        }

        $n = (int) $qb->getQuery()->getSingleScalarResult();

        return [$n, max(1, (int) ceil($n / Paginator::PAGE_SIZE))];
    }

    /**
     * Fetch N contiguous or non-contiguous pages in one round-trip and
     * return a map of page → posts. Uses the Doctrine ORM pagination
     * idiom: first window the distinct post ids with LIMIT/OFFSET, then
     * hydrate those ids with joins. Prevents to-many joins from clipping
     * the window, which setFirstResult()/setMaxResults() alone would do.
     *
     * @param list<int> $pageNums
     *
     * @return array<int, list<array{title: string, slug: string, summary: string, publishedAt: \DateTimeImmutable, authorFullName: string, tags: list<string>}>>
     */
    public function fetchChunk(array $pageNums, ?string $tagName): array
    {
        if ([] === $pageNums) {
            return [];
        }
        sort($pageNums);
        $first = $pageNums[0];
        $last = $pageNums[\count($pageNums) - 1];
        $pageSize = Paginator::PAGE_SIZE;
        $offset = ($first - 1) * $pageSize;
        $limit = ($last - $first + 1) * $pageSize;

        $repo = $this->em->getRepository(Post::class);

        $idQb = $repo->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.publishedAt <= :now')
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setParameter('now', new \DateTimeImmutable())
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($tagName) {
            $tagEntity = $this->em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
            if ($tagEntity) {
                $idQb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
            }
        }

        $ids = array_column($idQb->getQuery()->getArrayResult(), 'id');
        if ([] === $ids) {
            $out = [];
            foreach ($pageNums as $p) {
                $out[$p] = [];
            }

            return $out;
        }

        $posts = $repo->createQueryBuilder('p')
            ->addSelect('a', 't')
            ->innerJoin('p.author', 'a')
            ->leftJoin('p.tags', 't')
            ->where('p.id IN (:ids)')
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $rows = array_map($this->toArray(...), $posts);

        $out = [];
        foreach ($pageNums as $p) {
            $start = ($p - $first) * $pageSize;
            $out[$p] = \array_slice($rows, $start, $pageSize);
        }

        return $out;
    }

    /**
     * @return array{title: string, slug: string, summary: string, publishedAt: \DateTimeImmutable, authorFullName: string, tags: list<string>}
     */
    private function toArray(Post $post): array
    {
        return [
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'summary' => $post->getSummary(),
            'publishedAt' => $post->getPublishedAt(),
            'authorFullName' => $post->getAuthor()->getFullName(),
            'tags' => array_map(static fn (Tag $t) => $t->getName(), $post->getTags()->toArray()),
        ];
    }

    /**
     * Render post data to HTML.
     *
     * @param list<array<string, mixed>> $posts
     */
    public function render(array $posts, ?string $activeTag): string
    {
        return $this->twig->render('blog/_posts_list.html.twig', [
            'posts' => $posts,
            'activeTag' => $activeTag,
        ]);
    }
}
