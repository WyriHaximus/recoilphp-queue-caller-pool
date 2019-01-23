<?php declare(strict_types=1);

namespace WyriHaximus\Tests\Recoil;

use ApiClients\Tools\TestUtilities\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;
use React\Promise\Promise;
use function React\Promise\resolve;
use Recoil\React\ReactKernel;
use Rx\Subject\Subject;
use function WyriHaximus\React\futurePromise;
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

        $kernel->execute(function () use ($caller, &$finished, $loop) {
            $calls = [];
            $deferreds = [];
            $stream = new Subject();
            $state = $caller->call($stream);
            self::assertSame(State::WAITING, $state->getState());
            self::assertSame([
                'callers' => 1,
                'busy' => 1,
                'waiting' => 0,
            ], \iterator_to_array($caller->info()));

            for ($i = 'a'; $i !== 'zz'; $i++) {
                $deferreds[$i] = new Deferred();
                $calls[$i] = new Call(function ($promise, $return) {
                    yield $promise;

                    return $return;
                }, $deferreds[$i]->promise(), $i);
                $stream->onNext($calls[$i]);
                self::assertTrue(\in_array($state->getState(), [State::WAITING, State::BUSY], true), 'set up: ' . $i);
                yield futurePromise($loop);
            }
            self::assertSame([
                'callers' => 54,
                'busy' => 54,
                'waiting' => 0,
            ], \iterator_to_array($caller->info()));

            $keys = \array_keys($deferreds);
            foreach ($keys as $i) {
                $call = $calls[$i];
                $deferred = $deferreds[$i];
                $j = yield resolve(new Promise(function ($resolve, $reject) use ($call, $deferred, $i): void {
                    $call->wait($resolve, $reject);
                    $deferred->resolve($i);
                }));
                self::assertSame($i, $j);

                unset($deferreds[$i]);
                self::assertTrue(\in_array($state->getState(), [State::WAITING, State::BUSY], true), 'tear down: ' . $i);
            }
            self::assertSame([
                'callers' => 1,
                'busy' => 1,
                'waiting' => 0,
            ], \iterator_to_array($caller->info()));

            self::assertSame(State::WAITING, $state->getState());
            self::assertCount(0, $deferreds);

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);
        //self::assertSame(0, \gc_collect_cycles());
    }
}
