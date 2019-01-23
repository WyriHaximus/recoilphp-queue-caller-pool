<?php declare(strict_types=1);

namespace WyriHaximus\Recoil;

use Recoil\Kernel;
use Rx\ObservableInterface;
use Rx\Subject\Subject;

final class InfiniteCaller implements QueueCallerInterface
{
    /** @var Kernel */
    private $kernel;

    /** @var State */
    private $state;

    /** @var QueueCallerInterface[] */
    private $callers = [];

    /** @var State[] */
    private $callerState = [];

    /** @var Subject */
    private $callerStream = [];

    /**
     * @param Kernel $kernel
     */
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;

        $this->state = new State();
        $this->state->onNext(State::WAITING);
        $this->setUpCaller();
    }

    public function call(ObservableInterface $observable): State
    {
        $observable->subscribe(function (Call $call): void {
            $this->delegateCall($call);
        });

        return $this->state;
    }

    private function delegateCall(Call $call): void
    {
        foreach ($this->callerState as $qcHash => $state) {
            if ($state->getState() === State::BUSY && $state->getState() !== State::DONE) {
                continue;
            }

            $this->callerStream[$qcHash]->onNext($call);

            return;
        }

        $this->state->onNext(State::BUSY);
        $this->setUpCaller();
        $this->delegateCall($call);
    }

    private function setUpCaller(): void
    {
        $stream = new Subject();
        $caller = new FiniteCaller($this->kernel, 13);
        $qcHash = \spl_object_hash($caller);

        $this->callers[$qcHash] = $caller;
        $this->callerStream[$qcHash] = $stream;
        $this->callerState[$qcHash] = $caller->call($stream);
        $this->callerState[$qcHash]->filter(function (int $state) {
            return $state === State::WAITING;
        })->subscribe(function () use ($qcHash): void {
            $waitingCallers = \array_filter($this->callerState, function (State $state) {
                return $state->getState() === State::WAITING;
            });
            $waitingCallersCount = \count($waitingCallers);
            if ($waitingCallersCount === \count($this->callers)) {
                $this->state->onNext(State::WAITING);
            }

            \reset($waitingCallers);
            for ($i = 1; $i < $waitingCallersCount; $i++) {
                $this->tearDownCaller(\key($waitingCallers));
                \next($waitingCallers);
            }
        });
    }

    private function tearDownCaller(string $qcHash): void
    {
        unset(
            $this->callers[$qcHash],
            $this->callerStream[$qcHash],
            $this->callerState[$qcHash]
        );
    }
}
