<?php declare(strict_types=1);

namespace WyriHaximus\Recoil;

use Recoil\Kernel;
use Rx\DisposableInterface;
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

    /** @var string[] */
    private $readyCallers = [];

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
    }

    public function call(ObservableInterface $observable): State
    {
        try {
            $observable->subscribe(function (Call $call): void {
                try {
                    $this->delegateCall($call);
                } catch (\Throwable $et) {
                    echo (string)$et;
                }
            });
        } catch (\Throwable $et) {
            echo (string)$et;
        }

        return $this->state;
    }

    private function delegateCall(Call $call): void
    {
        /*if (count($this->readyCallers) > 0) {
            $qcHash = array_pop($this->readyCallers);
            $this->callerStream[$qcHash]->onNext($call);

            return;
        }*/

        $stream = new Subject();
        $caller = new QueueCaller($this->kernel);
        $qcHash = \spl_object_hash($caller);

        $this->callers[$qcHash] = $caller;
        $this->callerStream[$qcHash] = $stream;
        $this->callerState[$qcHash] = $caller->call($stream);
        /** @var DisposableInterface $disposeable */
        $disposeable = $this->callerState[$qcHash]->filter(function (int $state) {
            return $state === State::WAITING;
        })->subscribe(function () use (&$disposeable, $stream, $call): void {
            $stream->onNext($call);
            $disposeable->dispose();
        });
    }
}
