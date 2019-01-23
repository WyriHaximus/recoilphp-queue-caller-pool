<?php declare(strict_types=1);

namespace WyriHaximus\Tests\Recoil;

use ApiClients\Tools\TestUtilities\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;
use React\Promise\Promise;
use Recoil\React\ReactKernel;
use Rx\Subject\Subject;
use WyriHaximus\Recoil\Call;
use WyriHaximus\Recoil\FiniteCaller;
use WyriHaximus\Recoil\State;

/**
 * @internal
 */
final class FiniteCallerTest extends TestCase
{
    public function testConcurrencyOfOne(): void
    {
        $finished = false;
        $loop = Factory::create();
        $kernel = ReactKernel::create($loop);
        $kernel->setExceptionHandler(function ($error): void {
            echo (string)$error;
        });
        $caller = new FiniteCaller($kernel, 1);

        $values = [];
        $kernel->execute(function () use ($caller, &$finished, &$values) {
            yield;
            $deferreds = [];
            $calls = [];
            $stream = new Subject();
            $state = $caller->call($stream);
            self::assertSame(State::WAITING, $state->getState());

            $deferreds['a'] = new Deferred();
            $calls['a'] = new  Call(function ($promise) {
                yield $promise;
            }, $deferreds['a']->promise());
            $stream->onNext($calls['a']);
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['a']->resolve(123);
            $values['a'] = yield new Promise(function ($resolve, $reject) use (&$calls): void {
                $calls['a']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            $deferreds['b'] = new Deferred();
            $calls['b'] = new  Call(function ($promise) {
                yield $promise;
            }, $deferreds['b']->promise());
            $stream->onNext($calls['b']);
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['c'] = new Deferred();
            $calls['c'] = new  Call(function ($promise) {
                yield $promise;
            }, $deferreds['c']->promise());
            $stream->onNext($calls['c']);
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['b']->resolve(456);
            $values['b'] = yield new Promise(function ($resolve, $reject) use (&$calls): void {
                $calls['b']->wait($resolve, $reject);
            });
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['c']->resolve(789);
            $values['c'] = yield new Promise(function ($resolve, $reject) use (&$calls): void {
                $calls['c']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);

        /*self::assertSame([
            'a' => 123,
            'b' => 456,
            'c' => 789,
        ], $values);*/

        //self::assertSame(0, \gc_collect_cycles());
    }

    public function testConcurrencyOfFive(): void
    {
        $finished = false;
        $loop = Factory::create();
        $kernel = ReactKernel::create($loop);
        $kernel->setExceptionHandler(function ($error): void {
            echo (string)$error;
        });
        $caller = new FiniteCaller($kernel, 5);

        $kernel->execute(function () use ($caller, &$finished, $loop) {
            yield;
            $deferreds = [];
            $calls = [];
            $stream = new Subject();
            $state = $caller->call($stream);
            self::assertSame(State::WAITING, $state->getState());

            $deferreds['a'] = new Deferred();
            $calls['a'] = new  Call(function ($promise) {
                yield $promise;
            }, $deferreds['a']->promise());
            $stream->onNext($calls['a']);
            self::assertSame(State::WAITING, $state->getState());

            $deferreds['a']->resolve(123);
            yield new Promise(function ($resolve, $reject) use (&$calls): void {
                $calls['a']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            foreach (['b', 'c', 'd', 'e'] as $i) {
                $deferreds[$i] = new Deferred();
                $calls[$i] = new Call(function ($promise) {
                    yield $promise;
                }, $deferreds[$i]->promise());
                $stream->onNext($calls[$i]);
                self::assertSame(State::WAITING, $state->getState(), $i);
            }

            $deferreds['f'] = new Deferred();
            $stream->onNext(new  Call(function ($promise) {
                yield $promise;
            }, $deferreds['f']->promise()));
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['b']->resolve(123);
            yield new Promise(function ($resolve, $reject) use (&$calls): void {
                $calls['b']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);
        //self::assertSame(0, \gc_collect_cycles());
    }
}
