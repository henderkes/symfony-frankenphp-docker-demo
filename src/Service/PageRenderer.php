<?php

namespace App\Service;

use App\Entity\Post;
use App\Entity\Tag;
use App\Pagination\Paginator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class PageRenderer
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function render(int $pageNum, ?string $tagName, ?string $activeTag): string
    {
        $repo = $this->em->getRepository(Post::class);

        $qb = $repo->createQueryBuilder('p')
            ->addSelect('a', 't')
            ->innerJoin('p.author', 'a')
            ->leftJoin('p.tags', 't')
            ->where('p.publishedAt <= :now')
            ->orderBy('p.publishedAt', 'DESC')
            ->setParameter('now', new DateTimeImmutable());

        if ($tagName) {
            $tagEntity = $this->em->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
            if ($tagEntity) {
                $qb->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tagEntity);
            }
        }

        $paginator = (new Paginator($qb))->paginate($pageNum);

        $html = $this->twig->render('blog/_posts_list.html.twig', [
            'posts' => iterator_to_array($paginator->getResults()),
            'activeTag' => $activeTag,
        ]);

        $this->em->clear();

        return $html;
    }
}
