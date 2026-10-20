<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DecisionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionEngineTest extends TestCase
{
    private DecisionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DecisionEngine([
            'approve_max' => 60.0,
            'review_max' => 85.0,
            'mileage_review_max_km' => 400000,
            'max_age_years' => 20,
        ]);
    }

    #[DataProvider('ltvValues')]
    public function testDecidesByLtv(float $ltv, string $expected): void
    {
        self::assertSame($expected, $this->engine->decide(5, $ltv));
    }

    /** @return array<string,array{float,string}> */
    public static function ltvValues(): array
    {
        return [
            'низкий LTV' => [28.5, DecisionEngine::APPROVE],
            'середина зелёной зоны' => [45.0, DecisionEngine::APPROVE],
            'серая зона' => [72.3, DecisionEngine::REVIEW],
            'верхняя граница серой зоны' => [85.0, DecisionEngine::REVIEW],
            'сразу за верхней границей' => [85.01, DecisionEngine::REJECT],
            'высокий LTV' => [120.0, DecisionEngine::REJECT],
        ];
    }

    #[DataProvider('mileageBoundaries')]
    public function testDecidesByMileageWhenLtvIsGreen(int $mileage, string $expected): void
    {
        self::assertSame($expected, $this->engine->decide(5, 45.0, $mileage));
    }

    /** @return array<string,array{int,string}> */
    public static function mileageBoundaries(): array
    {
        return [
            'пробег под порогом' => [399999, DecisionEngine::APPROVE],
            'ровно на пороге' => [400000, DecisionEngine::APPROVE],
            'сразу за порогом' => [400001, DecisionEngine::REVIEW],
        ];
    }

    #[DataProvider('ageBoundaries')]
    public function testRejectsVehicleOlderThanMaxAgeRegardlessOfLtv(int $age, float $ltv, string $expected): void
    {
        self::assertSame($expected, $this->engine->decide($age, $ltv));
    }

    /** @return array<string,array{int,float,string}> */
    public static function ageBoundaries(): array
    {
        return [
            'ровно на пороге, зелёный LTV' => [20, 30.0, DecisionEngine::APPROVE],
            'сразу за порогом, зелёный LTV' => [21, 30.0, DecisionEngine::REJECT],
            'далеко за порогом, зелёный LTV' => [40, 1.0, DecisionEngine::REJECT],
            'сразу за порогом, серая зона LTV' => [21, 72.0, DecisionEngine::REJECT],
            'сразу за порогом, идеальный LTV' => [21, 0.0, DecisionEngine::REJECT],
        ];
    }
}
