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
     */
    public function fetch(int $pageNum, ?string $tagName): array
    {
        $qb = $this->em->getRepository(Post::class)->createQueryBuilder('p')
            ->addSelect('a', 't')
            ->innerJoin('p.author', 'a')
            ->leftJoin('p.tags', 't')
            ->where('p.publishedAt <= :now')
            ->orderBy('p.publishedAt', 'DESC')
            ->setParameter('now', new \DateTimeImmutable());

        if ($tagName) {
            $tagEntity = $this->em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
            if ($tagEntity) {
                $qb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
            }
        }

        return array_map(static fn ($post) => [
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'summary' => $post->getSummary(),
            'publishedAt' => $post->getPublishedAt(),
            'authorFullName' => $post->getAuthor()->getFullName(),
            'tags' => array_map(static fn ($t) => $t->getName(), $post->getTags()->toArray()),
        ], iterator_to_array((new Paginator($qb))->paginate($pageNum)->getResults()));
    }

    /**
     * Render post data to HTML.
     */
    public function render(array $posts, ?string $activeTag): string
    {
        return $this->twig->render('blog/_posts_list.html.twig', [
            'posts' => $posts,
            'activeTag' => $activeTag,
        ]);
    }
}
