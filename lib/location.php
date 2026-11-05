<?php
// Turns the many different ways a location can be written into one short format: "City, Country".
//   "San Francisco, CA • New York, NY • United States"  ->  "San Francisco, United States"
//   "Bangalore, Karnataka"                              ->  "Bangalore, India"
//   "London, UK"                                        ->  "London, United Kingdom"
//   "Remote UK"                                         ->  "United Kingdom"
//   "Europe" / "North America"                          ->  kept as they are (no city to show)
// Only the FIRST place is kept. If we can't work out the country, only the city is shown.

// Words that mean a country (lowercase), and the name we show for them.
function loc_countries(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = ['us' => 'United States', 'usa' => 'United States', 'u.s.' => 'United States', 'u.s.a.' => 'United States',
          'united states' => 'United States', 'united states of america' => 'United States',
          'uk' => 'United Kingdom', 'u.k.' => 'United Kingdom', 'gb' => 'United Kingdom', 'great britain' => 'United Kingdom',
          'england' => 'United Kingdom', 'scotland' => 'United Kingdom', 'wales' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
          'uae' => 'United Arab Emirates'];
    foreach (['India', 'Canada', 'Germany', 'France', 'Spain', 'Italy', 'Netherlands', 'Ireland', 'Portugal', 'Sweden', 'Denmark', 'Norway',
              'Finland', 'Poland', 'Czech Republic', 'Austria', 'Switzerland', 'Belgium', 'Australia', 'New Zealand', 'Singapore', 'Japan',
              'South Korea', 'China', 'Hong Kong', 'Taiwan', 'Indonesia', 'Philippines', 'Thailand', 'Vietnam', 'Malaysia', 'Israel', 'Brazil',
              'Mexico', 'Argentina', 'Colombia', 'Chile', 'Nigeria', 'Kenya', 'South Africa', 'Egypt', 'Turkey', 'Pakistan', 'Bangladesh',
              'Sri Lanka', 'Nepal', 'United Arab Emirates'] as $c) $m[strtolower($c)] = $c;
    return $m;
}

// Places that are bigger than a city: shown as they are.
function loc_regions(): array
{
    return ['europe' => 'Europe', 'north america' => 'North America', 'south america' => 'South America', 'asia' => 'Asia',
            'apac' => 'APAC', 'emea' => 'EMEA', 'latam' => 'LATAM', 'global' => 'Global', 'worldwide' => 'Worldwide', 'africa' => 'Africa', 'oceania' => 'Oceania'];
}

// States / provinces (full names and short codes, lowercase) -> country.
function loc_states(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (['alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia', 'hawaii', 'idaho',
              'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland', 'massachusetts', 'michigan', 'minnesota',
              'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey', 'new mexico', 'new york', 'north carolina',
              'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina', 'south dakota', 'tennessee', 'texas',
              'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming', 'district of columbia',
              'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'fl', 'ga', 'hi', 'id', 'il', 'in', 'ia', 'ks', 'ky', 'la', 'me', 'md', 'ma', 'mi', 'mn',
              'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj', 'nm', 'ny', 'nc', 'nd', 'oh', 'ok', 'or', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'vt',
              'va', 'wa', 'wv', 'wi', 'wy', 'dc'] as $s) $m[$s] = 'United States';
    foreach (['karnataka', 'maharashtra', 'uttar pradesh', 'delhi', 'haryana', 'tamil nadu', 'telangana', 'gujarat', 'west bengal', 'kerala',
              'rajasthan', 'punjab', 'andhra pradesh', 'madhya pradesh', 'odisha', 'bihar', 'chandigarh', 'goa'] as $s) $m[$s] = 'India';
    foreach (['ontario', 'quebec', 'british columbia', 'alberta', 'manitoba', 'nova scotia', 'on', 'bc', 'qc', 'ab'] as $s) $m[$s] = 'Canada';
    return $m;
}

// Well-known cities (lowercase) -> country, for when the location is just a city name.
function loc_cities(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $groups = [
        'India' => ['bengaluru', 'bangalore', 'mumbai', 'delhi', 'new delhi', 'gurgaon', 'gurugram', 'noida', 'hyderabad', 'pune', 'chennai', 'kolkata',
                    'ahmedabad', 'jaipur', 'kochi', 'chandigarh', 'indore', 'thiruvananthapuram', 'coimbatore', 'mohali', 'vadodara', 'lucknow', 'nagpur'],
        'United States' => ['new york', 'san francisco', 'san francisco bay area', 'los angeles', 'seattle', 'austin', 'boston', 'chicago', 'denver',
                    'atlanta', 'miami', 'dallas', 'houston', 'san diego', 'san jose', 'portland', 'washington', 'brooklyn', 'palo alto', 'mountain view',
                    'menlo park', 'redwood city', 'detroit', 'pittsburgh', 'philadelphia', 'minneapolis', 'salt lake city', 'raleigh', 'nashville'],
        'United Kingdom' => ['london', 'manchester', 'edinburgh', 'bristol', 'cambridge', 'oxford', 'glasgow'],
        'Germany' => ['berlin', 'munich', 'hamburg', 'cologne', 'frankfurt'], 'France' => ['paris', 'lyon'], 'Netherlands' => ['amsterdam', 'rotterdam'],
        'Ireland' => ['dublin'], 'Spain' => ['barcelona', 'madrid'], 'Portugal' => ['lisbon', 'porto'], 'Sweden' => ['stockholm'],
        'Denmark' => ['copenhagen'], 'Norway' => ['oslo'], 'Finland' => ['helsinki'], 'Poland' => ['warsaw', 'krakow'], 'Czech Republic' => ['prague'],
        'Austria' => ['vienna'], 'Switzerland' => ['zurich', 'geneva'], 'Italy' => ['milan', 'rome'],
        'Indonesia' => ['jakarta'], 'Japan' => ['tokyo'], 'South Korea' => ['seoul'], 'China' => ['beijing', 'shanghai', 'shenzhen'],
        'Australia' => ['sydney', 'melbourne'], 'New Zealand' => ['auckland'], 'Philippines' => ['manila'], 'Thailand' => ['bangkok'],
        'Malaysia' => ['kuala lumpur'], 'Vietnam' => ['ho chi minh city', 'hanoi'], 'United Arab Emirates' => ['dubai', 'abu dhabi'], 'Israel' => ['tel aviv'],
        'Canada' => ['toronto', 'vancouver', 'montreal', 'ottawa'], 'Mexico' => ['mexico city'], 'Brazil' => ['sao paulo', 'são paulo', 'rio de janeiro'],
        'Argentina' => ['buenos aires'], 'Colombia' => ['bogota', 'bogotá'], 'Nigeria' => ['lagos'], 'Kenya' => ['nairobi'], 'South Africa' => ['cape town', 'johannesburg'],
    ];
    $m = [];
    foreach ($groups as $country => $cities) foreach ($cities as $c) $m[$c] = $country;
    return $m;
}

function format_location(string $raw): string
{
    $s = trim($raw);
    if ($s === '') return '';

    // 1. Several places in one line: keep only the first.
    $s = preg_split('/\s*(?:;|\x{2022}|\||\/|\s+or\s+)\s*/u', $s)[0];
    // 2. Drop notes in brackets, like "(HQ)".
    $s = trim(preg_replace('/\s*\([^)]*\)/u', '', $s));
    // 3. Drop the word "Remote" (and the hyphen or comma next to it): "US-Remote", "Remote UK", "Remote, San Francisco, CA, US".
    $s = trim(preg_replace('/[\s,\-]*\bremote\b[\s,\-]*/iu', ' ', $s), " ,-");
    if ($s === '') return 'Remote';

    $parts = array_values(array_filter(array_map('trim', explode(',', $s)), fn($p) => $p !== ''));
    if (!$parts) return 'Remote';
    $countries = loc_countries();
    $first = mb_strtolower($parts[0]);

    // The first part is already a country or a big region: that is the whole answer ("United States", "Europe").
    if (isset($countries[$first])) return $countries[$first];
    if (isset(loc_regions()[$first])) return loc_regions()[$first];

    // Otherwise the first part is the city. Tidy it ("Bengaluru-VTP" -> "Bengaluru", "bengaluru" -> "Bengaluru").
    $city = preg_replace('/^([^\s\-]+)-[A-Z]{2,5}$/u', '$1', $parts[0]);
    if ($city === mb_strtolower($city)) $city = mb_convert_case($city, MB_CASE_TITLE);

    // Find the country: from a later part ("India", "UK", a state such as "CA" or "Karnataka"), else from the city itself.
    $country = null;
    foreach (array_slice($parts, 1) as $p) {
        $k = mb_strtolower($p);
        if (isset($countries[$k])) { $country = $countries[$k]; break; }
        if (isset(loc_states()[$k])) { $country = loc_states()[$k]; break; }
    }
    $country = $country ?? (loc_cities()[mb_strtolower($city)] ?? null);

    return ($country && mb_strtolower($country) !== mb_strtolower($city)) ? "$city, $country" : $city;
}
