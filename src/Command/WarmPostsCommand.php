<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Repository\PostRepository;
use App\Service\PageRenderer;
use Henderkes\Fork\Runtime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fan-out page pre-rendering across forked workers — exercises henderkes/fork
 * in a plain PHP CLI process (no FrankenPHP/Go runtime) to confirm the same
 * code path works identically in both SAPIs.
 *
 * Usage:
 *   bin/console app:warm-posts
 *   bin/console app:warm-posts --workers=9
 *   bin/console app:warm-posts --workers=9 --reps=3
 */
#[AsCommand(name: 'app:warm-posts', description: 'Render every blog page through the fork library and time it.')]
final class WarmPostsCommand extends Command
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PageRenderer $renderer,
        private readonly Runtime $runtime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Fork workers (0 = serial)', '8')
            ->addOption('reps', 'r', InputOption::VALUE_REQUIRED, 'Repeat runs for median', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workers = max(0, (int) $input->getOption('workers'));
        $reps = max(1, (int) $input->getOption('reps'));

        $totalPages = $this->posts->findLatest(1)->getLastPage();
        $allPages = range(1, $totalPages);
        $chunks = array_chunk($allPages, max(1, (int) ceil(\count($allPages) / ($workers + 1))));

        $renderer = $this->renderer;
        $task = static function (array $pageNums) use ($renderer): array {
            $out = [];
            foreach ($pageNums as $p) {
                $out[$p] = \strlen($renderer->render($renderer->fetch($p, null), null));
            }

            return $out;
        };

        $io->section("warming $totalPages pages via ".\count($chunks).' chunks ('.$workers.' workers)');

        $totals = [];
        $pages = [];
        for ($rep = 0; $rep < $reps; ++$rep) {
            $t0 = hrtime(true);
            $futures = [];
            for ($i = 1; $i < \count($chunks) && $i <= $workers; ++$i) {
                $futures[] = $this->runtime->run($task, [$chunks[$i]]);
            }
            $pages = $task($chunks[0]);
            foreach ($futures as $f) {
                $pages += $f->value();
            }
            ksort($pages);
            $totals[] = (hrtime(true) - $t0) / 1e6;
        }

        sort($totals);
        $median = $totals[(int) (\count($totals) / 2)];
        $sumBytes = array_sum($pages);

        $io->definitionList(
            ['pages rendered' => \count($pages)],
            ['total HTML bytes' => number_format($sumBytes)],
            ['median wall ms' => number_format($median, 1)],
            ['all runs ms' => implode(', ', array_map(static fn ($t) => number_format($t, 1), $totals))],
            ['chunks used' => \count($chunks)],
            ['workers spawned' => min($workers, \count($chunks) - 1)],
        );

        return Command::SUCCESS;
    }
}
