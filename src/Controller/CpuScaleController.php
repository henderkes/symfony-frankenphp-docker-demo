<?php

namespace App\Controller;

use Henderkes\Fork\Future;
use Henderkes\Fork\Runtime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pure CPU-bound workload for measuring parallel scaling.
 *
 * Each stream runs a tight integer loop whose working set is one register,
 * so there's no shared memory / L3 contention — only CPU time. Used to
 * establish the hardware ceiling for this box independent of the Symfony
 * request pipeline or memory-bound workloads.
 */
final class CpuScaleController extends AbstractController
{
    #[Route('/cpu/{num}/{iters}', name: 'cpu_scale', defaults: ['num' => 0, 'iters' => '500M'], requirements: ['num' => '\d+', 'iters' => '\d+[kMB]?'], methods: ['GET'])]
    public function scale(int $num, string $iters, Runtime $runtime): Response
    {
        $itersInput = $iters;
        $mul = ['k' => 1_000, 'M' => 1_000_000, 'B' => 1_000_000_000];
        $suffix = $iters[-1] ?? '';
        $iters = isset($mul[$suffix]) ? ((int) substr($iters, 0, -1)) * $mul[$suffix] : (int) $iters;

        $t0 = hrtime(true);
        $ms = static fn (int|float $t) => round(($t - $t0) / 1e6, 2);

        $streams = max(1, $num + 1);
        $per = intdiv($iters, $streams);

        // LCG, register-only, no memory touches. Reports start/end hrtime
        // relative to $t0 so the parent can plot the child on the same axis.
        $work = static function (int $n, int|float $t0): array {
            $start = hrtime(true);
            $x = 0;
            for ($i = 0; $i < $n; ++$i) {
                $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            }
            $end = hrtime(true);

            return [
                'result' => $x,
                'start_ms' => round(($start - $t0) / 1e6, 2),
                'end_ms' => round(($end - $t0) / 1e6, 2),
            ];
        };

        /** @var array<int, array<string, mixed>> $timings */
        $timings = [];

        $futures = [];
        for ($i = 1; $i <= $num; ++$i) {
            $tCall = hrtime(true);
            $futures[$i] = $runtime->run($work, [$per, $t0]);
            $timings[$i] = [
                'spawn_ms' => round((hrtime(true) - $tCall) / 1e6, 3),
                'spawned_at_ms' => $ms(hrtime(true)),
            ];
        }

        $timings[0] = [
            'spawn_ms' => 0,
            'spawned_at_ms' => $ms(hrtime(true)),
        ];
        $mainStart = hrtime(true);
        $mainRes = $work($per, $t0);
        $mainEnd = hrtime(true);
        $timings[0]['start_ms'] = $mainRes['start_ms'];
        $timings[0]['end_ms'] = $mainRes['end_ms'];
        $timings[0]['reap_start_ms'] = $mainRes['end_ms'];
        $timings[0]['reap_end_ms'] = $mainRes['end_ms'];

        $tReap = hrtime(true);
        $results = $futures === [] ? [] : Future::await(...array_values($futures));
        $indices = array_keys($futures);
        foreach ($indices as $k => $i) {
            $res = $results[$k];
            $drained = $futures[$i]->drainedAt() ?? hrtime(true);
            $timings[$i]['reap_start_ms'] = round(($tReap - $t0) / 1e6, 2);
            $timings[$i]['reap_end_ms'] = round(($drained - $t0) / 1e6, 2);
            $timings[$i]['start_ms'] = $res['start_ms'];
            $timings[$i]['end_ms'] = $res['end_ms'];
        }
        ksort($timings);

        $elapsed = round((hrtime(true) - $t0) / 1e6, 2);
        // Sum of all stream work durations = "ideal single-thread time"
        // if the workload were run serially. Speedup = ideal / elapsed.
        $totalWork = 0.0;
        foreach ($timings as $t) {
            $totalWork += $t['end_ms'] - $t['start_ms'];
        }
        $speedup = $elapsed > 0 ? round($totalWork / $elapsed, 2) : 0;
        $efficiency = $streams > 0 ? round(($speedup / $streams) * 100, 1) : 0;

        return $this->render('cpu/scale.html.twig', [
            'num_workers' => $num,
            'streams' => $streams,
            'iters_total' => $iters,
            'iters_input' => $itersInput,
            'iters_per_stream' => $per,
            'elapsed_ms' => $elapsed,
            'total_work_ms' => round($totalWork, 2),
            'speedup' => $speedup,
            'efficiency_pct' => $efficiency,
            'main_work_ms' => round(($mainEnd - $mainStart) / 1e6, 2),
            'timings' => $timings,
        ]);
    }
}
