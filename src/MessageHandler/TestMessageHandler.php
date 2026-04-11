<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\MessageHandler;

use App\Message\TestMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        $pid = pcntl_fork();
        if (0 === $pid) {
            $sum = 0;
            for ($i = 0; $i < 100000; ++$i) {
                $sum += $i;
            }
            file_put_contents('/tmp/messenger_fork_'.getmypid(), "msg={$message->id} sum=$sum\n");
            usleep(100);
            exit(0);
        }

        // Parent: fire and forget, don't wait
        file_put_contents('/tmp/messenger_parent_'.getmypid(), "handled msg={$message->id} child=$pid\n", \FILE_APPEND);
    }
}
