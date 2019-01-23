<?php declare(strict_types=1);

namespace WyriHaximus\Recoil;

interface QueueCallerPoolInterface extends QueueCallerInterface
{
    /**
     * Keys:
     *   - callers: Total callers count
     *   - busy:    Busy callers count
     *   - waiting: Waiting callers count.
     *
     * @return iterable
     */
    public function info(): iterable;
}
