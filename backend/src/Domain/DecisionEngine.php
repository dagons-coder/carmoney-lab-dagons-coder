<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Решение по заявке на основании возраста авто, LTV и пробега.
 *
 *   - возраст авто > max_age_years               -> reject (независимо от LTV)
 *   - LTV <= approve_max                         -> approve
 *   - LTV <= review_max                          -> review
 *   - LTV >  review_max                          -> reject
 *
 * Пробег свыше mileage_review_max_km понижает approve до review; ветки
 * review и reject по LTV пробегом не меняются.
 */
final class DecisionEngine
{
    public const APPROVE = 'approve';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    private float $approveMax;
    private float $reviewMax;
    private ?int $mileageReviewMaxKm;
    private int $maxAgeYears;

    /**
     * @param array{approve_max:float,review_max:float,mileage_review_max_km?:int,max_age_years:int} $thresholds
     */
    public function __construct(array $thresholds)
    {
        $this->approveMax = $thresholds['approve_max'];
        $this->reviewMax = $thresholds['review_max'];
        $this->mileageReviewMaxKm = $thresholds['mileage_review_max_km'] ?? null;
        $this->maxAgeYears = $thresholds['max_age_years'];
    }

    public function decide(int $vehicleAge, float $ltv, int $mileage = 0): string
    {
        if ($vehicleAge > $this->maxAgeYears) {
            return self::REJECT;
        }

        if ($ltv < $this->approveMax) {
            if ($this->mileageReviewMaxKm !== null && $mileage > $this->mileageReviewMaxKm) {
                return self::REVIEW;
            }

            return self::APPROVE;
        }

        if ($ltv <= $this->reviewMax) {
            return self::REVIEW;
        }

        return self::REJECT;
    }
}
