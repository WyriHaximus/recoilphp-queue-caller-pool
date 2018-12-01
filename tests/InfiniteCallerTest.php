<?php declare(strict_types=1);

namespace WyriHaximus\Tests\Recoil;

use ApiClients\Tools\TestUtilities\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;
use React\Promise\Promise;
use Recoil\React\ReactKernel;
use Rx\Subject\Subject;
use WyriHaximus\Recoil\Call;
use WyriHaximus\Recoil\InfiniteCaller;
use WyriHaximus\Recoil\State;

/**
 * @internal
 */
final class InfiniteCallerTest extends TestCase
{
    public function testInfiniteConcurrency(): void
    {
        $finished = false;
        $loop = Factory::create();
        $kernel = ReactKernel::create($loop);
        $kernel->setExceptionHandler(function ($error): void {
            echo (string)$error;
        });
        $caller = new InfiniteCaller($kernel);

        $deferreds = [];
        $kernel->execute(function () use ($caller, &$finished, &$deferreds, $loop) {
            $calls = [];
            $stream = new Subject();
            $state = $caller->call($stream);
            self::assertSame(State::WAITING, $state->getState());

            for ($i = 'a'; $i !== 'xxx'; $i++) {
                $deferreds[$i] = new Deferred();
                $calls[$i] = new Call(function ($promise) {
                    yield $promise;
                }, $deferreds[$i]->promise());
                $stream->onNext($calls[$i]);
                self::assertSame(State::WAITING, $state->getState(), $i);
            }

            foreach ($deferreds as $i => $_) {
                $call = $calls[$i];
                $deferred = $deferreds[$i];
                $deferred->resolve(123);
                yield new Promise(function ($resolve, $reject) use (&$call): void {
                    $call->wait($resolve, $reject);
                });
                unset($deferreds[$i]);
                self::assertSame(State::WAITING, $state->getState(), (string)$i);
            }

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);
    }
}
