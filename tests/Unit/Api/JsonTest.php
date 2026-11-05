<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Api\Json;

final class JsonTest extends TestCase
{
    public function test_string_accepts_non_empty_strings_and_integers(): void
    {
        $data = ['text' => 'abc', 'int' => 42, 'empty' => '', 'float' => 1.5, 'null' => null, 'list' => ['a']];

        self::assertSame('abc', Json::string($data, 'text'));
        self::assertSame('42', Json::string($data, 'int'));
        self::assertNull(Json::string($data, 'empty'));
        self::assertNull(Json::string($data, 'float'));
        self::assertNull(Json::string($data, 'null'));
        self::assertNull(Json::string($data, 'list'));
        self::assertNull(Json::string($data, 'missing'));
    }

    public function test_int_accepts_integers_and_digit_strings(): void
    {
        $data = ['int' => 3000000001, 'digits' => '42', 'zero' => 0, 'negative' => '-1', 'float' => 1.0, 'empty' => '', 'text' => '4a'];

        self::assertSame(3000000001, Json::int($data, 'int'));
        self::assertSame(42, Json::int($data, 'digits'));
        self::assertSame(0, Json::int($data, 'zero'));
        self::assertNull(Json::int($data, 'negative'));
        self::assertNull(Json::int($data, 'float'));
        self::assertNull(Json::int($data, 'empty'));
        self::assertNull(Json::int($data, 'text'));
        self::assertNull(Json::int($data, 'missing'));
    }
}
