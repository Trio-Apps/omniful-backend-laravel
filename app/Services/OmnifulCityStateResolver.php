<?php

namespace App\Services;

class OmnifulCityStateResolver
{
    /**
     * @var array<string, array{city_name:string,state_name:string,country_name:string}>
     */
    private static array $lookup = [];

    private static bool $loaded = false;

    /**
     * @return array{city_name:string,state_name:?string,country_name:?string}
     */
    public function resolve(?string $city, ?string $country = null): array
    {
        $this->load();

        $city = trim((string) $city);
        $country = trim((string) $country);

        if ($city === '') {
            return [
                'city_name' => '',
                'state_name' => null,
                'country_name' => $country !== '' ? $country : null,
            ];
        }

        $key = $this->key($city);
        $match = self::$lookup[$key] ?? null;

        if ($match === null) {
            return [
                'city_name' => $city,
                'state_name' => null,
                'country_name' => $country !== '' ? $country : null,
            ];
        }

        return [
            'city_name' => $match['city_name'],
            'state_name' => $match['state_name'],
            'country_name' => $match['country_name'] !== '' ? $match['country_name'] : ($country !== '' ? $country : null),
        ];
    }

    private function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $path = resource_path('data/omniful-sa-city-state.csv');
        if (!is_file($path)) {
            self::$loaded = true;

            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            self::$loaded = true;

            return;
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            self::$loaded = true;

            return;
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) < count($header)) {
                continue;
            }

            $record = array_combine($header, array_slice($row, 0, count($header)));
            if (!is_array($record)) {
                continue;
            }

            $cityName = trim((string) ($record['CITY NAME'] ?? ''));
            $stateName = trim((string) ($record['STATE NAME'] ?? ''));
            $countryName = trim((string) ($record['COUNTRY NAME'] ?? ''));

            if ($cityName === '') {
                continue;
            }

            $this->registerKey($cityName, $cityName, $stateName, $countryName);

            $synonyms = trim((string) ($record['SYNONYMS'] ?? ''));
            if ($synonyms === '') {
                continue;
            }

            foreach (explode(',', $synonyms) as $synonym) {
                $synonym = trim($synonym);
                if ($synonym === '') {
                    continue;
                }

                $this->registerKey($synonym, $cityName, $stateName, $countryName);
            }
        }

        fclose($handle);
        self::$loaded = true;
    }

    private function registerKey(string $value, string $cityName, string $stateName, string $countryName): void
    {
        $key = $this->key($value);
        if ($key === '' || isset(self::$lookup[$key])) {
            return;
        }

        self::$lookup[$key] = [
            'city_name' => $cityName,
            'state_name' => $stateName,
            'country_name' => $countryName,
        ];
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        return $value;
    }
}
