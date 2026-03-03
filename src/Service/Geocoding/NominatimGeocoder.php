<?php

namespace App\Service\Geocoding;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class NominatimGeocoder
{
    public function __construct(
        private HttpClientInterface $client,
        private string $userAgent = 'wikiformation/1.0 (contact@wikiformation.fr)'
    ) {}

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $response = $this->client->request('GET', 'https://nominatim.openstreetmap.org/search', [
            'query' => [
                'format' => 'json',
                'q'      => $address,
                'limit'  => 1,
            ],
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
        ]);

        $data = $response->toArray(false);

        if (empty($data[0])) {
            return null;
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];
        
    }
}
