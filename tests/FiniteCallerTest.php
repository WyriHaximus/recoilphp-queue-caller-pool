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

final class FiniteCallerTest extends TestCase
{
    public function testConcurrencyOfOne()
    {
        $finished = false;
        $loop = Factory::create();
        $kernel = ReactKernel::create($loop);
        $kernel->setExceptionHandler(function ($error) {
            echo (string)$error;
        });
        $caller = new FiniteCaller($kernel, 1);

        $kernel->execute(function () use ($caller, &$finished) {
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
            yield new Promise(function ($resolve, $reject) use (&$calls) {
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

            $deferreds['b']->resolve(123);
            yield new Promise(function ($resolve, $reject) use (&$calls) {
                $calls['b']->wait($resolve, $reject);
            });
            self::assertSame(State::BUSY, $state->getState());

            $deferreds['c']->resolve(123);
            yield new Promise(function ($resolve, $reject) use (&$calls) {
                $calls['c']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);
    }

    public function testConcurrencyOfFive()
    {
        $finished = false;
        $loop = Factory::create();
        $kernel = ReactKernel::create($loop);
        $kernel->setExceptionHandler(function ($error) {
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
            yield new Promise(function ($resolve, $reject) use (&$calls) {
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
            yield new Promise(function ($resolve, $reject) use (&$calls) {
                $calls['b']->wait($resolve, $reject);
            });
            self::assertSame(State::WAITING, $state->getState());

            $finished = true;
        });

        $loop->run();

        self::assertTrue($finished);
    }
}
