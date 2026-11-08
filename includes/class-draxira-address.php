<?php
/**
 * Address generator for dummy WooCommerce orders
 *
 * Produces billing / shipping address arrays that fit WooCommerce's fields.
 * Postcodes follow the selected country (and state where a mapping is known)
 * and, when WooCommerce is loaded, are checked with WC_Validation::is_postcode().
 *
 * @package Draxira
 * @subpackage Includes
 * @since 1.0.7
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Draxira_Address
 *
 * @since 1.0.7
 */
class Draxira_Address
{
    /**
     * Faker locale per country (only used when that locale's provider exists).
     */
    private static $locales = [
        'US' => 'en_US', 'GB' => 'en_GB', 'IN' => 'en_IN', 'CA' => 'en_CA', 'AU' => 'en_AU',
        'NZ' => 'en_NZ', 'IE' => 'en_IE', 'ZA' => 'en_ZA', 'DE' => 'de_DE', 'AT' => 'de_AT',
        'CH' => 'de_CH', 'FR' => 'fr_FR', 'BE' => 'nl_BE', 'NL' => 'nl_NL', 'ES' => 'es_ES',
        'MX' => 'es_MX', 'AR' => 'es_AR', 'IT' => 'it_IT', 'PT' => 'pt_PT', 'BR' => 'pt_BR',
        'SE' => 'sv_SE', 'NO' => 'nb_NO', 'DK' => 'da_DK', 'FI' => 'fi_FI', 'PL' => 'pl_PL',
    ];

    /**
     * US state => [min, max] first three ZIP digits.
     */
    private static $us_zip = [
        'AL' => [350, 369], 'AK' => [995, 999], 'AZ' => [850, 865], 'AR' => [716, 729], 'CA' => [900, 961],
        'CO' => [800, 816], 'CT' => [60, 69], 'DE' => [197, 199], 'DC' => [200, 205], 'FL' => [320, 349],
        'GA' => [300, 319], 'HI' => [967, 968], 'ID' => [832, 838], 'IL' => [600, 629], 'IN' => [460, 479],
        'IA' => [500, 528], 'KS' => [660, 679], 'KY' => [400, 427], 'LA' => [700, 714], 'ME' => [39, 49],
        'MD' => [206, 219], 'MA' => [10, 27], 'MI' => [480, 499], 'MN' => [550, 567], 'MS' => [386, 397],
        'MO' => [630, 658], 'MT' => [590, 599], 'NE' => [680, 693], 'NV' => [889, 898], 'NH' => [30, 38],
        'NJ' => [70, 89], 'NM' => [870, 884], 'NY' => [100, 149], 'NC' => [270, 289], 'ND' => [580, 588],
        'OH' => [430, 458], 'OK' => [730, 749], 'OR' => [970, 979], 'PA' => [150, 196], 'RI' => [28, 29],
        'SC' => [290, 299], 'SD' => [570, 577], 'TN' => [370, 385], 'TX' => [750, 799], 'UT' => [840, 847],
        'VT' => [50, 59], 'VA' => [220, 246], 'WA' => [980, 994], 'WV' => [247, 268], 'WI' => [530, 549],
        'WY' => [820, 831],
    ];

    /**
     * Indian state code => [min, max] first two PIN digits (WooCommerce uses a few legacy codes, both are listed).
     */
    private static $in_pin = [
        'DL' => [11, 11], 'HR' => [12, 13], 'PB' => [14, 15], 'CH' => [16, 16], 'HP' => [17, 17],
        'JK' => [18, 19], 'UP' => [20, 28], 'UK' => [24, 26], 'UT' => [24, 26], 'RJ' => [30, 34],
        'GJ' => [36, 39], 'DD' => [36, 36], 'DN' => [39, 39], 'MH' => [40, 44], 'GA' => [40, 40],
        'MP' => [45, 48], 'CT' => [49, 49], 'TS' => [50, 50], 'TG' => [50, 50], 'AP' => [51, 53],
        'KA' => [56, 59], 'TN' => [60, 64], 'PY' => [60, 60], 'KL' => [67, 69], 'LD' => [68, 68],
        'WB' => [70, 74], 'AN' => [74, 74], 'OR' => [75, 77], 'OD' => [75, 77], 'AS' => [78, 78],
        'AR' => [79, 79], 'MN' => [79, 79], 'ML' => [79, 79], 'MZ' => [79, 79], 'NL' => [79, 79],
        'TR' => [79, 79], 'SK' => [73, 73], 'BR' => [80, 85], 'JH' => [81, 83],
    ];

    /**
     * Australian state => [min, max] postcode.
     */
    private static $au_post = [
        'NSW' => [2000, 2599], 'ACT' => [2600, 2618], 'VIC' => [3000, 3999], 'QLD' => [4000, 4999],
        'SA' => [5000, 5799], 'WA' => [6000, 6797], 'TAS' => [7000, 7799], 'NT' => [800, 899],
    ];

    /**
     * Canadian province => possible first letters.
     */
    private static $ca_first = [
        'ON' => 'KLMNP', 'QC' => 'GHJ', 'BC' => 'V', 'AB' => 'T', 'MB' => 'R', 'SK' => 'S',
        'NS' => 'B', 'NB' => 'E', 'NL' => 'A', 'PE' => 'C', 'NT' => 'X', 'NU' => 'X', 'YT' => 'Y',
    ];

    private static $first_names = ['Aarav', 'Emma', 'Liam', 'Priya', 'Noah', 'Sofia', 'Arjun', 'Olivia', 'Ethan', 'Ananya', 'Lucas', 'Mia', 'Rohan', 'Isabella', 'Daniel', 'Meera'];
    private static $last_names = ['Sharma', 'Wilson', 'Johnson', 'Das', 'Brown', 'Garcia', 'Roy', 'Smith', 'Miller', 'Banerjee', 'Davis', 'Martin', 'Patel', 'Clark', 'Lopez', 'Ghosh'];
    private static $streets = ['Maple', 'Oak', 'Cedar', 'Park', 'Lake', 'Hill', 'River', 'Sunset', 'Church', 'Station', 'Garden', 'Market'];
    private static $street_types = ['Street', 'Road', 'Avenue', 'Lane', 'Drive', 'Way'];
    private static $cities = ['Springfield', 'Riverton', 'Lakeside', 'Fairview', 'Brookfield', 'Georgetown', 'Clinton', 'Salem'];

    /**
     * Pick a random element of a list.
     *
     * @param array $list List.
     * @return mixed
     */
    private static function pick(array $list)
    {
        return $list[wp_rand(0, count($list) - 1)];
    }

    /**
     * Random digits string.
     *
     * @param int $length Length.
     * @param bool $no_leading_zero Avoid a leading zero.
     * @return string
     */
    private static function digits($length, $no_leading_zero = false)
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) wp_rand(($i === 0 && $no_leading_zero) ? 1 : 0, 9);
        }
        return $out;
    }

    /**
     * Random letters string.
     *
     * @param int    $length Length.
     * @param string $alphabet Allowed letters.
     * @return string
     */
    private static function letters($length, $alphabet = 'ABCDEFGHJKLMNPRSTUVWXYZ')
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[wp_rand(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * Candidate postcode for a country/state (not yet validated).
     *
     * @param string $country Country code.
     * @param string $state   State code.
     * @return string|null Null when there is no country-specific format.
     */
    private static function candidate_postcode($country, $state)
    {
        switch ($country) {
            case 'US':
                if (isset(self::$us_zip[$state])) {
                    $range = self::$us_zip[$state];
                    return str_pad((string) wp_rand($range[0], $range[1]), 3, '0', STR_PAD_LEFT) . self::digits(2);
                }
                return self::digits(5, true);
            case 'IN':
                if (isset(self::$in_pin[$state])) {
                    $range = self::$in_pin[$state];
                    return (string) wp_rand($range[0], $range[1]) . self::digits(4);
                }
                return (string) wp_rand(11, 85) . self::digits(4);
            case 'GB':
                $areas = ['AB', 'BA', 'BS', 'CB', 'CF', 'DE', 'E', 'EC', 'G', 'L', 'LS', 'M', 'N', 'NW', 'OX', 'S', 'SE', 'SW', 'W'];
                return self::pick($areas) . wp_rand(1, 9) . ' ' . wp_rand(1, 9) . self::letters(2, 'ABDEFGHJLNPQRSTUWXYZ');
            case 'CA':
                $first = isset(self::$ca_first[$state]) ? self::$ca_first[$state] : 'KLMNPTVXY';
                $l = 'ABCEGHJKLMNPRSTVWXYZ';
                return $first[wp_rand(0, strlen($first) - 1)] . wp_rand(0, 9) . self::letters(1, $l) . ' ' . wp_rand(0, 9) . self::letters(1, $l) . wp_rand(0, 9);
            case 'AU':
                if (isset(self::$au_post[$state])) {
                    $range = self::$au_post[$state];
                    return str_pad((string) wp_rand($range[0], $range[1]), 4, '0', STR_PAD_LEFT);
                }
                return self::digits(4, true);
            case 'NL':
                return self::digits(4, true) . ' ' . self::letters(2, 'ABCDEFGHJKLMNPRSTUVWXZ');
            case 'BR':
                return self::digits(5, true) . '-' . self::digits(3);
            case 'JP':
                return self::digits(3, true) . '-' . self::digits(4);
            case 'PT':
                return self::digits(4, true) . '-' . self::digits(3);
            case 'PL':
                return self::digits(2, true) . '-' . self::digits(3);
            case 'SE':
                return self::digits(3, true) . ' ' . self::digits(2);
            case 'DE':
            case 'FR':
            case 'ES':
            case 'IT':
            case 'MX':
            case 'FI':
            case 'TR':
                return str_pad((string) wp_rand(1000, 98999), 5, '0', STR_PAD_LEFT);
            case 'AT':
            case 'BE':
            case 'CH':
            case 'DK':
            case 'NO':
            case 'NZ':
            case 'ZA':
            case 'HU':
            case 'LU':
                return self::digits(4, true);
            case 'CN':
            case 'RU':
            case 'SG':
                return self::digits(6, true);
            default:
                return null;
        }
    }

    /**
     * Generate a postcode that is valid for the country (and plausible for the state).
     *
     * @since 1.0.7
     * @param string $country Country code.
     * @param string $state   State/province code.
     * @return string
     */
    public static function postcode($country, $state = '')
    {
        $country = strtoupper((string) $country);
        $state = strtoupper((string) $state);
        $can_validate = class_exists('WC_Validation') && method_exists('WC_Validation', 'is_postcode');

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = self::candidate_postcode($country, $state);

            if (null === $candidate) {
                // Unknown country format: try common shapes until WooCommerce accepts one.
                $shapes = [
                    function () { return self::digits(5, true); },
                    function () { return self::digits(4, true); },
                    function () { return self::digits(6, true); },
                    function () { return self::digits(3, true) . ' ' . self::digits(2); },
                    function () { return self::letters(2) . wp_rand(1, 9) . ' ' . wp_rand(1, 9) . self::letters(2); },
                ];
                $candidate = call_user_func($shapes[$attempt % count($shapes)]);
            }

            if (!$can_validate || WC_Validation::is_postcode($candidate, $country)) {
                return $candidate;
            }
        }

        return self::digits(5, true);
    }

    /**
     * Generate a phone number with the country's calling code.
     *
     * @since 1.0.7
     * @param string $country Country code.
     * @return string
     */
    public static function phone($country)
    {
        $code = '';
        if (function_exists('WC') && WC() && isset(WC()->countries) && WC()->countries) {
            $calling = WC()->countries->get_country_calling_code($country);
            $code = is_array($calling) ? reset($calling) : (string) $calling;
        }

        switch ($country) {
            case 'IN':
                $local = (string) wp_rand(6, 9) . self::digits(9);
                break;
            case 'US':
            case 'CA':
                $local = (string) wp_rand(2, 9) . self::digits(2) . (string) wp_rand(2, 9) . self::digits(6);
                break;
            case 'GB':
                $local = '7' . self::digits(9);
                break;
            default:
                $local = self::digits(9, true);
        }

        return $code . $local;
    }

    /**
     * Generate one address.
     *
     * @since 1.0.7
     * @param string $country Country code (required).
     * @param string $state   State code ('' for none).
     * @return array WooCommerce-style address fields plus email.
     */
    public static function generate($country, $state = '')
    {
        $country = strtoupper((string) $country);
        $locale = isset(self::$locales[$country]) ? self::$locales[$country] : '';
        $faker = Draxira_Helpers::faker($locale);

        $first = Draxira_Helpers::fk($faker, 'firstName', [], self::pick(self::$first_names));
        $last = Draxira_Helpers::fk($faker, 'lastName', [], self::pick(self::$last_names));

        $street_fallback = wp_rand(10, 999) . ' ' . self::pick(self::$streets) . ' ' . self::pick(self::$street_types);
        $address_1 = Draxira_Helpers::fk($faker, 'streetAddress', [], $street_fallback);
        $address_2 = '';
        if (wp_rand(1, 100) <= 30) {
            $address_2 = Draxira_Helpers::fk($faker, 'secondaryAddress', [], 'Apt ' . wp_rand(1, 40));
        }

        $city = Draxira_Helpers::fk($faker, 'city', [], self::pick(self::$cities));

        // Countries that hide the postcode field in WooCommerce get none.
        $postcode = self::postcode($country, $state);
        if (function_exists('WC') && WC() && isset(WC()->countries) && WC()->countries) {
            $locale_info = WC()->countries->get_country_locale();
            if (isset($locale_info[$country]['postcode']['hidden']) && true === $locale_info[$country]['postcode']['hidden']) {
                $postcode = '';
            }
        }

        $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $first . $last));
        $email = Draxira_Helpers::fk($faker, 'safeEmail', [], ($slug ? $slug : 'customer') . wp_rand(10, 999) . '@example.com');

        return [
            'first_name' => (string) $first,
            'last_name' => (string) $last,
            'company' => (wp_rand(1, 100) <= 15) ? (string) Draxira_Helpers::fk($faker, 'company', [], 'Acme Corp') : '',
            'address_1' => (string) $address_1,
            'address_2' => (string) $address_2,
            'city' => (string) $city,
            'state' => (string) $state,
            'postcode' => (string) $postcode,
            'country' => $country,
            'phone' => self::phone($country),
            'email' => (string) $email,
        ];
    }
}
