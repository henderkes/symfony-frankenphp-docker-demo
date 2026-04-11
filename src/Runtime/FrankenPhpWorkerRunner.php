<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Runtime;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

class FrankenPhpWorkerRunner implements RunnerInterface
{
    public function __construct(
        private HttpKernelInterface $kernel,
        private int $loopMax,
    ) {
    }

    public function run(): int
    {
        // Prevent worker script termination when a client connection is interrupted
        ignore_user_abort(true);

        $server = array_filter($_SERVER, static fn (string $key) => !str_starts_with($key, 'HTTP_'), \ARRAY_FILTER_USE_KEY);
        $server['APP_RUNTIME_MODE'] = 'web=1&worker=1';

        $handler = function () use ($server, &$sfRequest, &$sfResponse): void {
            // Clean up previous request: collect garbage, release freed memory
            // back to the OS, then reset peak so the profiler shows this request only.
            gc_collect_cycles();
            gc_mem_caches();
            memory_reset_peak_usage();

            // Connect to the Xdebug client if it's available
            if (\extension_loaded('xdebug') && \function_exists('xdebug_connect_to_client')) {
                xdebug_connect_to_client();
            }

            // Merge the environment variables coming from DotEnv with the ones tied to the current request
            $_SERVER += $server;

            $sfRequest = Request::createFromGlobals();
            $sfResponse = $this->kernel->handle($sfRequest);

            $sfResponse->send();
        };

        $loops = 0;
        do {
            $ret = frankenphp_handle_request($handler);

            if ($this->kernel instanceof TerminableInterface && $sfRequest && $sfResponse) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }
        } while ($ret && (0 >= $this->loopMax || ++$loops < $this->loopMax));

        return 0;
    }
}
