<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Settings\InfoProviderSystem\TMESettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class TMEClient
{
    public const BASE_URI = 'https://api.tme.eu';

    public function __construct(
        private readonly HttpClientInterface $tmeClient,
        private readonly TMESettings $settings,
        #[Autowire(service: 'info_provider.cache')]
        private readonly CacheInterface $cache
    ) {

    }

    /*
     * Make a request to the TME API.
     * Transparently handles session token generation and renewal.
     * @return bool true if the client is usable
     */
    public function makeRequest(string $endpoint, array $parameters): ResponseInterface
    {
        $session_token = $this->getSessionToken();

        return $this->tmeClient->request('GET', $this->getUrlForEndpoint($endpoint), [
            'headers' => [
                'Authorization' => 'Bearer ' . $session_token
            ],
            'query' => $parameters
        ]);
    }

    /*
     * Checks if the current settings allow the client to be used.
     * @return bool true if the client is usable
     */
    public function isUsable(): bool
    {
        return !($this->settings->apiToken === null || $this->settings->apiSecret === null);
    }

    private function getUrlForEndpoint(string $endpoint): string
    {
        return self::BASE_URI . '/' . ltrim($endpoint, '/');
    }

    /*
     * Gets the session token from the local cache,  or requests a new one if it is nonexistent
     * or expired.
     * @return string valid session token
     */
    private function getSessionToken(): string
    {
        return $this->cache->get($this->getTokenCacheKey(), function (ItemInterface $item): string {

            // Request new session from the API
            $response = $this->tmeClient->request('POST', $this->getUrlForEndpoint('/auth/token'), [
                'auth_basic' => [$this->settings->apiToken, $this->settings->apiSecret],
                'body' => ['grant_type' => 'client_credentials'],
            ]);

            $data = $response->toArray();

            // Subtract 30s as a safety buffer
            $item->expiresAfter($data['expires_in'] - 30);

            return $data['access_token'];
        });
    }

    private function getTokenCacheKey(): string
    {
        return 'tme_api_v2_token_' . hash('xxh3', $this->settings->apiToken . $this->settings->apiSecret);
    }
}
