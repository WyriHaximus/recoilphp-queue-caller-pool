<?php declare(strict_types=1);

namespace WyriHaximus\Recoil;

use Recoil\Kernel;
use Rx\ObservableInterface;
use Rx\Subject\Subject;

final class FiniteCaller implements QueueCallerPoolInterface
{
    /** @var State */
    private $state;

    /** @var QueueCallerInterface[] */
    private $caller = [];

    /** @var State[] */
    private $callerState = [];

    /** @var Subject */
    private $callerStream = [];

    /** @var array */
    private $queue = [];

    /** @var int */
    private $callersBusy = 0;

    /** @var int */
    private $callersCount = 0;

    /**
     * @param Kernel $kernel
     * @param int    $size
     */
    public function __construct(Kernel $kernel, int $size)
    {
        $this->callersCount = $size;
        $this->state = new State();
        for ($i = 0; $i < $size; $i++) {
            $subject = new Subject();
            $caller = new QueueCaller($kernel);
            $hash = \spl_object_hash($caller);
            $this->caller[$hash] = $caller;
            $this->callerStream[$hash] = $subject;
            $this->callerState[$hash] = $caller->call($subject);
            $this->callerState[$hash]->filter(function () {
                return \count($this->queue) > 0;
            })->filter(function (int $state) {
                return $state === State::WAITING;
            })->subscribe(function () use ($hash): void {
                $this->callerStream[$hash]->onNext(\array_shift($this->queue));
            });
            $this->callerState[$hash]->subscribe(function () use ($hash): void {
                $this->callersBusy = \count(\array_filter($this->callerState, function (State $state) {
                    return $state->getState() === State::BUSY;
                }));
                if ($this->callersBusy === $this->callersCount) {
                    $this->state->onNext(State::BUSY);

                    return;
                }

                $this->state->onNext(State::WAITING);
            });
        }
        $this->state->onNext(State::STARTED);
    }

    public function call(ObservableInterface $observable): State
    {
        $observable->subscribe(function (Call $call): void {
            $this->delegateCall($call);
        });

        return $this->state;
    }

    public function info(): iterable
    {
        yield 'callers' => $this->callersCount;
        yield 'busy'    => $this->callersBusy;
        yield 'waiting' => $this->callersCount - $this->callersBusy;
    }

    private function delegateCall(Call $call): void
    {
        foreach ($this->callerState as $qcHash => $state) {
            if ($state->getState() !== State::WAITING) {
                continue;
            }

            $this->callerStream[$qcHash]->onNext($call);

            return;
        }

        $this->state->onNext(State::BUSY);
        $this->queue[] = $call;
    }
}
