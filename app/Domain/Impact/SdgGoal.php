<?php

declare(strict_types=1);

namespace App\Domain\Impact;

enum SdgGoal: int
{
    case NoPoverty             = 1;
    case ZeroHunger            = 2;
    case GoodHealth            = 3;
    case QualityEducation      = 4;
    case GenderEquality        = 5;
    case CleanWater            = 6;
    case AffordableEnergy      = 7;
    case DecentWork            = 8;
    case Industry              = 9;
    case ReducedInequalities   = 10;
    case SustainableCities     = 11;
    case ResponsibleConsumption = 12;
    case ClimateAction         = 13;
    case LifeBelowWater        = 14;
    case LifeOnLand            = 15;
    case Peace                 = 16;
    case Partnerships          = 17;

    public function label(): string
    {
        return match ($this) {
            self::NoPoverty              => 'SDG 1 — No Poverty',
            self::ZeroHunger             => 'SDG 2 — Zero Hunger',
            self::GoodHealth             => 'SDG 3 — Good Health and Well-Being',
            self::QualityEducation       => 'SDG 4 — Quality Education',
            self::GenderEquality         => 'SDG 5 — Gender Equality',
            self::CleanWater             => 'SDG 6 — Clean Water and Sanitation',
            self::AffordableEnergy       => 'SDG 7 — Affordable and Clean Energy',
            self::DecentWork             => 'SDG 8 — Decent Work and Economic Growth',
            self::Industry               => 'SDG 9 — Industry, Innovation and Infrastructure',
            self::ReducedInequalities    => 'SDG 10 — Reduced Inequalities',
            self::SustainableCities      => 'SDG 11 — Sustainable Cities and Communities',
            self::ResponsibleConsumption => 'SDG 12 — Responsible Consumption and Production',
            self::ClimateAction          => 'SDG 13 — Climate Action',
            self::LifeBelowWater         => 'SDG 14 — Life Below Water',
            self::LifeOnLand             => 'SDG 15 — Life on Land',
            self::Peace                  => 'SDG 16 — Peace, Justice and Strong Institutions',
            self::Partnerships           => 'SDG 17 — Partnerships for the Goals',
        };
    }
}
