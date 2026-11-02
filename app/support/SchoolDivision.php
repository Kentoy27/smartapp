<?php

namespace App\Support;

use App\Models\User;

/**
 * The one vocabulary for school levels, sublevels and financial divisions.
 *
 * A school LEVEL is what kind of school an account is assigned to
 * (ELEMENTARY or HIGH_SCHOOL). A SUBLEVEL narrows a High School to Junior
 * High School or Senior High School. A DIVISION is the financial unit a row
 * belongs to: Elementary, Junior High or Senior High. Elementary and the two
 * high-school divisions are the same three values everywhere in the database,
 * so no query has to translate one naming into another.
 */
final class SchoolDivision
{
    public const ELEMENTARY = 'ELEMENTARY';

    public const JUNIOR_HIGH = 'JUNIOR_HIGH';

    public const SENIOR_HIGH = 'SENIOR_HIGH';

    public const LEVEL_ELEMENTARY = 'ELEMENTARY';

    public const LEVEL_HIGH_SCHOOL = 'HIGH_SCHOOL';

    public const SUBLEVEL_NONE = 'NONE';

    /**
     * Every financial division, in the order the interface lists them.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::ELEMENTARY, self::JUNIOR_HIGH, self::SENIOR_HIGH];
    }

    /**
     * The school levels an administrator may assign.
     *
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return [self::LEVEL_ELEMENTARY, self::LEVEL_HIGH_SCHOOL];
    }

    /**
     * The school sublevels an administrator may assign.
     *
     * @return array<int, string>
     */
    public static function sublevels(): array
    {
        return [self::SUBLEVEL_NONE, self::JUNIOR_HIGH, self::SENIOR_HIGH];
    }

    /**
     * The divisions a High School can own financial records for.
     *
     * @return array<int, string>
     */
    public static function highSchoolDivisions(): array
    {
        return [self::JUNIOR_HIGH, self::SENIOR_HIGH];
    }

    /**
     * The readable name of each division.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ELEMENTARY => 'Elementary',
            self::JUNIOR_HIGH => 'Junior High School',
            self::SENIOR_HIGH => 'Senior High School',
        ];
    }

    /** The readable name of one division ("Elementary"). */
    public static function label(?string $division): string
    {
        return self::labels()[$division ?? ''] ?? 'Elementary';
    }

    /**
     * The short name used as a navigation suffix ("Junior High", "Senior
     * High"). Elementary carries no suffix, so the existing navigation is
     * unchanged for it.
     */
    public static function shortLabel(?string $division): string
    {
        return match ($division) {
            self::JUNIOR_HIGH => 'Junior High',
            self::SENIOR_HIGH => 'Senior High',
            default => '',
        };
    }

    /**
     * The navigation suffix appended to a High School's financial module
     * labels: " — Junior High", " — Senior High", or "" for Elementary.
     */
    public static function sidebarSuffix(?string $division): string
    {
        $short = self::shortLabel($division);

        return $short === '' ? '' : ' — '.$short;
    }

    /** The readable name of a school level ("High School"). */
    public static function levelLabel(?string $level): string
    {
        return match ($level) {
            self::LEVEL_HIGH_SCHOOL => 'High School',
            default => 'Elementary',
        };
    }

    /** The readable name of a school sublevel ("Junior High School"). */
    public static function sublevelLabel(?string $sublevel): string
    {
        return match ($sublevel) {
            self::JUNIOR_HIGH => 'Junior High School',
            self::SENIOR_HIGH => 'Senior High School',
            default => 'None',
        };
    }

    public static function isValidDivision(?string $division): bool
    {
        return in_array($division, self::all(), true);
    }

    /**
     * The division a written value names — "Senior High", "SHS",
     * "senior_high", "Senior High School" — or null when it names none.
     *
     * The match ignores case, spaces, hyphens, underscores and dots, so a
     * spreadsheet's own spelling does not have to be the database's. Null is
     * a real answer: the caller decides whether a value that names no
     * division is an error or a row that simply did not carry one.
     */
    public static function parse(?string $value): ?string
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $value))) ?? '';

        return match ($normalized) {
            'elementary', 'elementaryschool', 'elem', 'es' => self::ELEMENTARY,
            'juniorhigh', 'juniorhighschool', 'junior', 'jhs' => self::JUNIOR_HIGH,
            'seniorhigh', 'seniorhighschool', 'senior', 'shs' => self::SENIOR_HIGH,
            default => null,
        };
    }

    /**
     * The spellings a written division may use, for a hint or an error
     * message. Kept beside parse() so the two can never drift apart.
     */
    public static function acceptedSpellings(): string
    {
        return 'Elementary (or Elem/ES), Junior High School (or JHS), Senior High School (or SHS)';
    }

    public static function isValidLevel(?string $level): bool
    {
        return in_array($level, self::levels(), true);
    }

    public static function isValidSublevel(?string $sublevel): bool
    {
        return in_array($sublevel, self::sublevels(), true);
    }

    /**
     * The division an account acts in unless it has explicitly switched to
     * another authorized one.
     *
     * A High School account's sublevel is its default division. An account
     * whose access list names exactly one division defaults to it. Everything
     * else — including every Elementary account and the legacy rows that
     * predate the classification columns — is Elementary.
     */
    public static function divisionFor(?string $level, ?string $sublevel, mixed $access = null): string
    {
        if (in_array($sublevel, self::highSchoolDivisions(), true)) {
            return $sublevel;
        }

        $valid = self::validAccessList($access);

        if ($level === self::LEVEL_HIGH_SCHOOL) {
            $high = array_values(array_intersect($valid, self::highSchoolDivisions()));

            if ($high !== []) {
                return $high[0];
            }
        }

        if (count($valid) === 1) {
            return $valid[0];
        }

        return self::ELEMENTARY;
    }

    /**
     * The divisions a school account may read, derived from its
     * classification and its explicit access list.
     *
     * Elementary always has exactly one division. A High School's access list
     * is narrowed to the two high-school divisions; an empty or malformed list
     * falls back to the account's sublevel. The caller (a User) is responsible
     * for the division-wide overseer roles, which are not tied to a school.
     *
     * @return array<int, string>
     */
    public static function authorizedFor(?string $level, ?string $sublevel, mixed $access): array
    {
        if ($level !== self::LEVEL_HIGH_SCHOOL) {
            return [self::ELEMENTARY];
        }

        $high = array_values(array_intersect(self::validAccessList($access), self::highSchoolDivisions()));

        if ($high !== []) {
            return $high;
        }

        return in_array($sublevel, self::highSchoolDivisions(), true) ? [$sublevel] : [];
    }

    /**
     * The divisions an account with this stored access value names. Used to
     * guarantee that a crafted payload cannot introduce a division the
     * database does not know.
     *
     * @return array<int, string>
     */
    public static function validAccessList(mixed $access): array
    {
        if (is_string($access)) {
            $decoded = json_decode($access, true);
            $access = is_array($decoded) ? $decoded : [$access];
        }

        if (! is_array($access)) {
            return [];
        }

        $valid = array_values(array_unique(array_filter(
            $access,
            fn ($division): bool => is_string($division) && self::isValidDivision($division),
        )));

        // Keep the canonical interface order.
        return array_values(array_intersect(self::all(), $valid));
    }
}
