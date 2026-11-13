<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Local ISO 3166-1 table: country names in English, Spanish and the country's own
 * language(s), matched case- and accent-insensitively.
 */
final class Countries
{
    /** alpha-2 => [alpha-3, names...] */
    private const TABLE = [
        // European Union
        'AT' => ['AUT', 'Austria', 'Österreich'],
        'BE' => ['BEL', 'Belgium', 'Bélgica', 'België', 'Belgique', 'Belgien'],
        'BG' => ['BGR', 'Bulgaria', 'България'],
        'HR' => ['HRV', 'Croatia', 'Croacia', 'Hrvatska'],
        'CY' => ['CYP', 'Cyprus', 'Chipre', 'Κύπρος', 'Kıbrıs'],
        'CZ' => ['CZE', 'Czechia', 'Czech Republic', 'Chequia', 'República Checa', 'Česko', 'Česká republika'],
        'DK' => ['DNK', 'Denmark', 'Dinamarca', 'Danmark'],
        'EE' => ['EST', 'Estonia', 'Eesti'],
        'FI' => ['FIN', 'Finland', 'Finlandia', 'Suomi'],
        'FR' => ['FRA', 'France', 'Francia', 'République française'],
        'DE' => ['DEU', 'Germany', 'Alemania', 'Deutschland', 'Bundesrepublik Deutschland'],
        'GR' => ['GRC', 'Greece', 'Grecia', 'Ελλάδα', 'Ελλάς', 'Hellas'],
        'HU' => ['HUN', 'Hungary', 'Hungría', 'Magyarország'],
        'IE' => ['IRL', 'Ireland', 'Republic of Ireland', 'Irlanda', 'Éire'],
        'IT' => ['ITA', 'Italy', 'Italia'],
        'LV' => ['LVA', 'Latvia', 'Letonia', 'Latvija'],
        'LT' => ['LTU', 'Lithuania', 'Lituania', 'Lietuva'],
        'LU' => ['LUX', 'Luxembourg', 'Luxemburgo', 'Luxemburg', 'Lëtzebuerg'],
        'MT' => ['MLT', 'Malta'],
        'NL' => ['NLD', 'Netherlands', 'The Netherlands', 'Holland', 'Países Bajos', 'Holanda', 'Nederland'],
        'PL' => ['POL', 'Poland', 'Polonia', 'Polska'],
        'PT' => ['PRT', 'Portugal'],
        'RO' => ['ROU', 'Romania', 'Rumania', 'Rumanía', 'România'],
        'SK' => ['SVK', 'Slovakia', 'Eslovaquia', 'Slovensko'],
        'SI' => ['SVN', 'Slovenia', 'Eslovenia', 'Slovenija'],
        'ES' => ['ESP', 'Spain', 'España', 'Espanya', 'Espainia', 'Reino de España'],
        'SE' => ['SWE', 'Sweden', 'Suecia', 'Sverige'],
        // Rest of the EEA, Switzerland, UK, Andorra, Turkey
        'IS' => ['ISL', 'Iceland', 'Islandia', 'Ísland'],
        'LI' => ['LIE', 'Liechtenstein'],
        'NO' => ['NOR', 'Norway', 'Noruega', 'Norge', 'Noreg'],
        'CH' => ['CHE', 'Switzerland', 'Suiza', 'Schweiz', 'Suisse', 'Svizzera', 'Svizra'],
        'GB' => ['GBR', 'United Kingdom', 'UK', 'Great Britain', 'Britain', 'England', 'Scotland', 'Wales', 'Northern Ireland', 'Reino Unido', 'Gran Bretaña', 'Inglaterra', 'Escocia', 'Gales'],
        'AD' => ['AND', 'Andorra', 'Principat d\'Andorra'],
        'TR' => ['TUR', 'Turkey', 'Türkiye', 'Turquía'],
        // North America
        'US' => ['USA', 'United States', 'United States of America', 'USA', 'U.S.A.', 'U.S.', 'Estados Unidos', 'EE. UU.', 'EEUU'],
        'CA' => ['CAN', 'Canada', 'Canadá'],
        'MX' => ['MEX', 'Mexico', 'México', 'Estados Unidos Mexicanos'],
        // Latin America and the Caribbean
        'AR' => ['ARG', 'Argentina'],
        'BZ' => ['BLZ', 'Belize', 'Belice'],
        'BO' => ['BOL', 'Bolivia'],
        'BR' => ['BRA', 'Brazil', 'Brasil'],
        'CL' => ['CHL', 'Chile'],
        'CO' => ['COL', 'Colombia'],
        'CR' => ['CRI', 'Costa Rica'],
        'CU' => ['CUB', 'Cuba'],
        'DO' => ['DOM', 'Dominican Republic', 'República Dominicana'],
        'EC' => ['ECU', 'Ecuador'],
        'SV' => ['SLV', 'El Salvador'],
        'GT' => ['GTM', 'Guatemala'],
        'GY' => ['GUY', 'Guyana'],
        'HT' => ['HTI', 'Haiti', 'Haití', 'Haïti', 'Ayiti'],
        'HN' => ['HND', 'Honduras'],
        'NI' => ['NIC', 'Nicaragua'],
        'PA' => ['PAN', 'Panama', 'Panamá'],
        'PY' => ['PRY', 'Paraguay', 'Paraguái'],
        'PE' => ['PER', 'Peru', 'Perú'],
        'PR' => ['PRI', 'Puerto Rico'],
        'SR' => ['SUR', 'Suriname', 'Surinam'],
        'UY' => ['URY', 'Uruguay'],
        'VE' => ['VEN', 'Venezuela'],
        // Asia-Pacific and Africa
        'AU' => ['AUS', 'Australia'],
        'NZ' => ['NZL', 'New Zealand', 'Nueva Zelanda', 'Aotearoa'],
        'JP' => ['JPN', 'Japan', 'Japón', '日本', 'Nippon', 'Nihon'],
        'CN' => ['CHN', 'China', 'People\'s Republic of China', '中国', '中國'],
        'IN' => ['IND', 'India', 'भारत', 'Bharat'],
        'MA' => ['MAR', 'Morocco', 'Marruecos', 'Maroc', 'المغرب'],
    ];

    /** @var array<string,string>|null normalised name => alpha-2 */
    private static ?array $index = null;

    /** ISO alpha-2 code for a country name or code, or null when unknown. */
    public static function codeFor(string $name): ?string
    {
        $key = self::normalise($name);
        if ($key === '') {
            return null;
        }

        $code = self::index()[$key] ?? null;
        if ($code !== null) {
            return $code;
        }

        $trimmed = trim($name);
        if (preg_match('/^[A-Za-z]{2}$/', $trimmed)) {
            return strtoupper($trimmed);
        }

        return null;
    }

    /** ISO alpha-3 code for an alpha-2 code in the table, or null. */
    public static function alpha3(string $alpha2): ?string
    {
        return self::TABLE[strtoupper(trim($alpha2))][0] ?? null;
    }

    /** ISO alpha-2 code for an alpha-3 code in the table, or null. */
    public static function fromAlpha3(string $alpha3): ?string
    {
        $alpha3 = strtoupper(trim($alpha3));
        foreach (self::TABLE as $code => $row) {
            if ($row[0] === $alpha3) {
                return $code;
            }
        }

        return null;
    }

    /** Lower case, no accents, punctuation turned into single spaces. */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        } else {
            $text = strtr($text, [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
                'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
                'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
                'č' => 'c', 'š' => 's', 'ž' => 'z',
            ]);
        }
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return str_starts_with($text, 'the ') ? substr($text, 4) : $text;
    }

    private static function index(): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::TABLE as $code => $row) {
                foreach (array_slice($row, 1) as $name) {
                    self::$index[self::normalise($name)] = $code;
                }
            }
        }

        return self::$index;
    }
}
