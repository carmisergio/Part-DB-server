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

namespace App\Tests\Services\InfoProviderSystem\Providers;

use App\Entity\Parts\ManufacturingStatus;
use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Services\InfoProviderSystem\Providers\TMEClient;
use App\Services\InfoProviderSystem\Providers\TMEProvider;
use App\Settings\InfoProviderSystem\TMESettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TMEProviderTest extends TestCase
{
    private TMESettings $settings;
    private TMEProvider $provider;
    private MockHttpClient $httpClient;


    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $this->settings = SettingsTestHelper::createSettingsDummy(TMESettings::class);
        $this->settings->apiToken = 'test_token';
        $this->settings->apiSecret = 'test_secret';
        $this->settings->currency = 'EUR';
        $this->settings->country = 'DE';
        $cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();

        // Pre-populate the cache with a fake token so that tests don't make an HTTP auth request
        $cacheKey = 'tme_api_v2_token_' . hash('xxh3', $this->settings->apiToken . $this->settings->apiSecret);
        $item = $cache->getItem($cacheKey);
        $item->set('fake_test_token');
        $cache->save($item);

        $this->provider = new TMEProvider(new TMEClient($this->httpClient, $this->settings, $cache), $this->settings);
    }

    private function etqp3mProducts(): MockResponse
    {
        return $this->mockProductList([[
            'Symbol'                 => 'ETQP3M6R8KVP',
            'OriginalSymbol'         => 'ETQP3M6R8KVP',
            'Producer'               => 'PANASONIC',
            'Description'            => 'Inductor: wire; SMD; 6.8uH; 2.9A; R: 65.7mΩ; ±20%; ETQP3M; 5.5x5x3mm',
            'Category'               => 'Inductors',
            'Photo'                  => '//ce8dc832c.cloudimg.io/v7/_cdn_/9E/27/A0/00/0/684777_1.jpg',
            'ProductStatusList'      => [],
            'ProductInformationPage' => '//www.tme.eu/en/details/etqp3m6r8kvp/inductors/panasonic/',
            'Weight'                 => 0.44,
            'WeightUnit'             => 'g',
        ]]);
    }

    private function etqp3mFiles(): MockResponse
    {
        return $this->mockFilesList([[
            'Symbol' => 'ETQP3M6R8KVP',
            'Files'  => [
                'AdditionalPhotoList' => [],
                'DocumentList'        => [
                    ['DocumentUrl' => '//www.tme.eu/Document/50a845881f09d8a2248350946e11df38/AGL0000C63.pdf'],
                    ['DocumentUrl' => '//www.tme.eu/Document/8480690a42fa577214e35e33d3fc8d77/ETQP3M100KVN-LNK.txt'],
                ],
            ],
        ]]);
    }

    private function etqp3mParameters(): MockResponse
    {
        return $this->mockParametersList([[
            'Symbol'        => 'ETQP3M6R8KVP',
            'ParameterList' => [
                ['ParameterId' => 566, 'ParameterName' => 'Inductance',        'ParameterValue' => '6.8µH'],
                ['ParameterId' => 370, 'ParameterName' => 'Operating current', 'ParameterValue' => '2.9A'],
                ['ParameterId' => 39,  'ParameterName' => 'Tolerance',         'ParameterValue' => '±20%'],
            ],
        ]]);
    }

    private function etqp3mPrices(): MockResponse
    {
        return $this->mockPrices('EUR', 'NET', [[
            'Symbol'    => 'ETQP3M6R8KVP',
            'PriceList' => [
                ['Amount' => 1,  'PriceValue' => 0.589],
                ['Amount' => 5,  'PriceValue' => 0.429],
                ['Amount' => 10, 'PriceValue' => 0.399],
            ],
        ]]);
    }

    // --- Tests ---

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('description', $info);
        $this->assertArrayHasKey('url', $info);
        $this->assertEquals('TME', $info['name']);
        $this->assertEquals('https://tme.eu/', $info['url']);
    }

    public function testGetProviderKey(): void
    {
        $this->assertSame('tme', $this->provider->getProviderKey());
    }

    public function testIsActiveWithCredentials(): void
    {
        $this->assertTrue($this->provider->isActive());
    }

    public function testIsActiveWithoutCredentials(): void
    {
        $this->settings->apiToken = null;
        $cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $provider = new TMEProvider(new TMEClient($this->httpClient, $this->settings, $cache), $this->settings);
        $this->assertFalse($provider->isActive());
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        $this->assertIsArray($capabilities);
        $this->assertContains(ProviderCapabilities::BASIC, $capabilities);
        $this->assertContains(ProviderCapabilities::PICTURE, $capabilities);
        $this->assertContains(ProviderCapabilities::DATASHEET, $capabilities);
        $this->assertContains(ProviderCapabilities::PRICE, $capabilities);
        $this->assertContains(ProviderCapabilities::FOOTPRINT, $capabilities);
    }

    public function testGetHandledDomains(): void
    {
        $this->assertContains('tme.eu', $this->provider->getHandledDomains());
    }

    public function testGetIDFromURL(): void
    {
        $this->assertSame('fi321_se', $this->provider->getIDFromURL('https://www.tme.eu/de/details/fi321_se/kuhler/alutronic/'));
        $this->assertSame('smd0603-5k1-1%25', $this->provider->getIDFromURL('https://www.tme.eu/en/details/smd0603-5k1-1%25/smd-resistors/royalohm/0603saf5101t5e/'));
        $this->assertNull($this->provider->getIDFromURL('https://www.tme.eu/en/'));
    }

    public function testSearchByKeyword(): void
    {

        $this->httpClient->setResponseFactory([function (string $method, string $url, array $options): MockResponse {

            // Check request parameters
            $exp_url = 'https://api.tme.eu/products/search?country=DE&scope[0]=products&phrase=SMD0603-5K1-1%25&limit=100';
            $this->assertSame('GET', $method);
            $this->assertSame($exp_url, $url);
            $this->assertContains('Authorization: Bearer fake_test_token', $options['headers'] ?? []);

            // Construct response
            return new MockResponse(json_encode([
                'status' => 'OK',
                'data'   => [
                    'products' => [
                        'elements' => [
                            [
                                'product_status' => [],
                                'symbol' => 'SMD0603-5K1-1%',
                                'ean' => '978020137962',
                                'category' => [
                                    'name' => 'SMD resistors'
                                ],
                                'manufacturer_symbols' => ['0603SAF5101T5E', 'another_symbol'],
                                'manufacturer' => [
                                    'name' => 'ROYALOHM'
                                ],
                                'description' => 'Resistor: thick film; SMD; 0603; 5.1kΩ; 0.1W; ±1%; 50V; -55÷155°C',
                                'assets' => [
                                    'primary_photo' => [
                                        'prime' => '//images.somecdn.net/somepath/smd0603_primary_prime.jpg'

                                    ]
                                ]
                            ],
                            // Fake product specifically created to test nullable fields
                            [
                                'product_status' => ['INVALID'],
                                'symbol' => 'FAKE_PRODUCT',
                                'description' => 'High-Quality bleeding edge fake product'
                            ]
                        ]
                    ]
                ]
            ]));
        }]);

        $results = $this->provider->searchByKeyword('SMD0603-5K1-1%');

        $this->assertIsArray($results);
        $this->assertCount(2, $results);

        $this->assertInstanceOf(SearchResultDTO::class, $results[0]);
        $this->assertSame('tme', $results[0]->provider_key);
        $this->assertSame('SMD0603-5K1-1%', $results[0]->provider_id);
        $this->assertSame('0603SAF5101T5E', $results[0]->name);
        $this->assertSame('Resistor: thick film; SMD; 0603; 5.1kΩ; 0.1W; ±1%; 50V; -55÷155°C', $results[0]->description);
        $this->assertSame('SMD resistors', $results[0]->category);
        $this->assertSame('ROYALOHM', $results[0]->manufacturer);
        $this->assertSame('0603SAF5101T5E', $results[0]->mpn);
        $this->assertSame('https://images.somecdn.net/somepath/smd0603_primary_prime.jpg', $results[0]->preview_image_url);
        $this->assertSame(ManufacturingStatus::ACTIVE, $results[0]->manufacturing_status);
        $this->assertSame('https://www.tme.eu/details/SMD0603-5K1-1%25',$results[0]->provider_url);
        $this->assertSame('978020137962', $results[0]->gtin);

        $this->assertInstanceOf(SearchResultDTO::class, $results[1]);
        $this->assertSame('tme', $results[0]->provider_key);
        $this->assertSame('FAKE_PRODUCT', $results[1]->provider_id);
        $this->assertSame('FAKE_PRODUCT', $results[1]->name);
        $this->assertSame('High-Quality bleeding edge fake product', $results[1]->description);
        $this->assertNull($results[1]->category);
        $this->assertNull($results[1]->manufacturer);
        $this->assertNull($results[1]->mpn);
        $this->assertNull($results[1]->preview_image_url);
        $this->assertSame(ManufacturingStatus::DISCONTINUED, $results[1]->manufacturing_status);
        $this->assertSame('https://www.tme.eu/details/FAKE_PRODUCT',$results[1]->provider_url);
        $this->assertNull($results[1]->gtin);
    }

    public function testGetPartDetails(): void
    {

        $this->httpClient->setResponseFactory([
            // Request 1: get product info
            function (string $method, string $url, array $options): MockResponse {
                // Check request parameters
                $exp_url = 'https://api.tme.eu/products?country=DE&symbols[0]=SMD0603-5K1-1%25';
                $this->assertSame('GET', $method);
                $this->assertSame($exp_url, $url);
                $this->assertContains('Authorization: Bearer fake_test_token', $options['headers'] ?? []);

                // Construct response
                return new MockResponse(json_encode([
                    'status' => 'OK',
                    'data'   => [
                        'elements' => [
                            [
                                'product_status' => [],
                                'symbol' => 'SMD0603-5K1-1%',
                                'ean' => '978020137962',
                                'category' => [
                                    'name' => 'SMD resistors'
                                ],
                                'manufacturer_symbols' => ['0603SAF5101T5E', 'another_symbol'],
                                'manufacturer' => [
                                    'name' => 'ROYALOHM'
                                ],
                                'assets' => [
                                    'primary_photo' => [
                                        'prime' => '//images.somecdn.net/somepath/smd0603_primary_prime.jpg',
                                        'high_resolution' => '//images.somecdn.net/somepath/smd0603_primary_high_resolution.jpg'
                                    ]
                                ],
                                'description' => 'Resistor: thick film; SMD; 0603; 5.1kΩ; 0.1W; ±1%; 50V; -55÷155°C',
                                'weight' => [
                                    'value' => 10,
                                    'unit' => 'g',
                                ]
                            ]
                        ]
                    ]
                ]));
            },
            // Request 2: get files
            function (string $method, string $url, array $options): MockResponse {
                // Check request parameters
                $exp_url = 'https://api.tme.eu/products/files?country=DE&symbols[0]=SMD0603-5K1-1%25';
                $this->assertSame('GET', $method);
                $this->assertSame($exp_url, $url);
                $this->assertContains('Authorization: Bearer fake_test_token', $options['headers'] ?? []);

                // Construct response
                return new MockResponse(json_encode([
                    'status' => 'OK',
                    'data'   => [
                        'elements' => [
                            [
                                'symbol' => 'SMD0603-5K1-1%',
                                'assets' => [
                                    'primary_photo' => [
                                        'prime' => '//images.somecdn.net/somepath/smd0603_primary_prime.jpg',
                                        'high_resolution' => '//images.somecdn.net/somepath/smd0603_primary_high_resolution.jpg',
                                    ],
                                    'additional' => [
                                        'elements' => [
                                            [
                                                'prime' => '//images.somecdn.net/somepath/smd0603_additional1_prime.jpg',
                                                'high_resolution' => '//images.somecdn.net/somepath/smd0603_additional1_high_resolution.jpg'
                                            ],
                                            [
                                                'prime' => '//images.somecdn.net/somepath/smd0603_additional2_prime.jpg',
                                            ],
                                        ]
                                    ],
                                ],
                                'documents' => [
                                    'elements' => [
                                        [
                                            'url' => '//documents.somecdn.net/somepath/smd0603_datasheet.pdf',
                                            'type' => 'DTE',
                                            'file_name' => 'smd0603.pdf'
                                        ],
                                        // Document of the wrong type, check it doesn't get included
                                        [
                                            'url' => '//documents.somecdn.net/somepath/some_firmware.bin',
                                            'type' => 'SFT',
                                            'file_name' => 'firmware.pdf'
                                        ],
                                        [
                                            'url' => '//documents.somecdn.net/somepath/smd0603_safety.pdf',
                                            'type' => 'KCH',
                                            'file_name' => 'smd0603_safety.pdf'
                                        ],
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]));
            },
            // Request 3: get parameters
            function (string $method, string $url, array $options): MockResponse {
                // Check request parameters
                $exp_url = 'https://api.tme.eu/products/parameters?country=DE&symbols[0]=SMD0603-5K1-1%25';
                $this->assertSame('GET', $method);
                $this->assertSame($exp_url, $url);
                $this->assertContains('Authorization: Bearer fake_test_token', $options['headers'] ?? []);

                // Construct response
                return new MockResponse(json_encode([
                    'status' => 'OK',
                    'data'   => [
                        'elements' => [
                            [
                                'symbol' => 'SMD0603-5K1-1%',
                                'parameters' => [
                                    'elements' => [
                                        [
                                            'id' => 35,
                                            'name' => 'Case',
                                            'values' => [
                                                [
                                                    'value' => '0603',
                                                ]
                                            ]
                                        ],
                                        [
                                            'id' => 34,
                                            'name' => 'Resistance',
                                            'values' => [
                                                [
                                                    'value' => '5.1k\u03a9',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]));
            },
            // Request 4: get product pricing information
            function (string $method, string $url, array $options): MockResponse {
                // Check request parameters
                $exp_url = 'https://api.tme.eu/products/data?country=DE&currency=EUR&scope[0]=prices&symbols[0]=SMD0603-5K1-1%25';
                $this->assertSame('GET', $method);
                $this->assertSame($exp_url, $url);
                $this->assertContains('Authorization: Bearer fake_test_token', $options['headers'] ?? []);

                // Construct response
                return new MockResponse(json_encode([
                    'status' => 'OK',
                    'data'   => [
                        'elements' => [
                            [
                                'symbol' => 'SMD0603-5K1-1%',
                                'prices' => [
                                    'elements' => [
                                        [
                                            'amount' => 1,
                                            'price' => 0.23
                                        ],
                                        [
                                            'amount' => 50,
                                            'price' => 0.19
                                        ],
                                        [
                                            'amount' => 100,
                                            'price' => 0.071
                                        ],
                                    ],
                                    'currency' => 'EUR',
                                    'type' => 'GROSS'
                                ]
                            ]
                        ]
                    ]
                ]));
            }
        ]);

        $result = $this->provider->getDetails('SMD0603-5K1-1%');

        $this->assertInstanceOf(PartDetailDTO::class, $result);
        $this->assertSame('SMD0603-5K1-1%', $result->provider_id);
        $this->assertSame('0603SAF5101T5E', $result->name);
        $this->assertSame('Resistor: thick film; SMD; 0603; 5.1kΩ; 0.1W; ±1%; 50V; -55÷155°C', $result->description);
        $this->assertSame('SMD resistors', $result->category);
        $this->assertSame('ROYALOHM', $result->manufacturer);
        $this->assertSame('0603SAF5101T5E', $result->mpn);
        $this->assertSame('https://images.somecdn.net/somepath/smd0603_primary_high_resolution.jpg', $result->preview_image_url);
        $this->assertSame(ManufacturingStatus::ACTIVE, $result->manufacturing_status);
        $this->assertSame('https://www.tme.eu/details/SMD0603-5K1-1%25',$result->provider_url);
        $this->assertSame('0603', $result->footprint);
        $this->assertSame('978020137962', $result->gtin);
        $this->assertSame(10.0, $result->mass);

        $this->assertCount(2, $result->images);
        $this->assertInstanceOf(FileDTO::class, $result->images[0]);
        $this->assertSame('https://images.somecdn.net/somepath/smd0603_additional1_high_resolution.jpg', $result->images[0]->url);
        $this->assertInstanceOf(FileDTO::class, $result->images[1]);
        $this->assertSame('https://images.somecdn.net/somepath/smd0603_additional2_prime.jpg', $result->images[1]->url);

        $this->assertCount(2, $result->datasheets);
        $this->assertInstanceOf(FileDTO::class, $result->datasheets[0]);
        $this->assertSame('https://documents.somecdn.net/somepath/smd0603_datasheet.pdf', $result->datasheets[0]->url);
        $this->assertSame('smd0603.pdf', $result->datasheets[0]->name);
        $this->assertInstanceOf(FileDTO::class, $result->datasheets[1]);
        $this->assertSame('https://documents.somecdn.net/somepath/smd0603_safety.pdf', $result->datasheets[1]->url);
        $this->assertSame('smd0603_safety.pdf', $result->datasheets[1]->name);

        $this->assertCount(2, $result->parameters);
        $this->assertSame('Case', $result->parameters[0]->name);
        $this->assertSame('Resistance', $result->parameters[1]->name);
        // Do not check values, conversion is handled by ParameterDTO

        $this->assertCount(1, $result->vendor_infos);
        $this->assertInstanceOf(PurchaseInfoDTO::class, $result->vendor_infos[0]);
        $this->assertSame('TME', $result->vendor_infos[0]->distributor_name);
        $this->assertSame('SMD0603-5K1-1%', $result->vendor_infos[0]->order_number);
        $this->assertSame('https://www.tme.eu/details/SMD0603-5K1-1%25',$result->vendor_infos[0]->product_url);
        $this->assertCount(3, $result->vendor_infos[0]->prices);
        $this->assertSame(1.0, $result->vendor_infos[0]->prices[0]->minimum_discount_amount);
        $this->assertSame('0.23', $result->vendor_infos[0]->prices[0]->price);
        $this->assertSame('EUR', $result->vendor_infos[0]->prices[0]->currency_iso_code);
        $this->assertTrue($result->vendor_infos[0]->prices[0]->includes_tax);
        $this->assertSame(50.0, $result->vendor_infos[0]->prices[1]->minimum_discount_amount);
        $this->assertSame('0.19', $result->vendor_infos[0]->prices[1]->price);
        $this->assertSame('EUR', $result->vendor_infos[0]->prices[1]->currency_iso_code);
        $this->assertTrue($result->vendor_infos[0]->prices[1]->includes_tax);
        $this->assertSame(100.0, $result->vendor_infos[0]->prices[2]->minimum_discount_amount);
        $this->assertSame('0.071', $result->vendor_infos[0]->prices[2]->price);
        $this->assertSame('EUR', $result->vendor_infos[0]->prices[2]->currency_iso_code);
        $this->assertTrue($result->vendor_infos[0]->prices[2]->includes_tax);

    }

    private function getPartDetailsFootprintTestData(): array
    {
        return [
            new MockResponse(json_encode([
                'status' => 'OK',
                'data'   => [
                    'elements' => [
                        [
                            'product_status' => [],
                            'symbol' => 'FAKE_PRODUCT',
                            'description' => 'Really nice fake product'
                        ]
                    ]
                ]
            ])),
            new MockResponse(json_encode([
                'status' => 'OK',
                'data'   => [
                    'elements' => [
                        [
                            'symbol' => 'FAKE_PRODUCT',
                            'assets' => [
                                'primary_photo' => [
                                    'prime' => '//images.somecdn.net/somepath/fake_product_primary_prime.jpg',
                                ],
                                'additional' => [
                                    'elements' => []
                                ]
                            ],
                            'documents' => [
                                'elements' => []
                            ]
                        ]
                    ]
                ]
            ])),
            new MockResponse(json_encode([
                'status' => 'OK',
                'data'   => [
                    'elements' => [
                        [
                            'symbol' => 'SMD0603-5K1-1%',
                            'parameters' => [
                                'elements' => [
                                    [
                                        'id' => 2932,
                                        'name' => 'Case - inch',
                                        'values' => [
                                            [
                                                'value' => 'footprint_imperial',
                                            ]
                                        ]
                                    ],
                                    [
                                        'id' => 2931,
                                        'name' => 'Case - mm',
                                        'values' => [
                                            [
                                                'value' => 'footprint_metric',
                                            ]
                                        ]
                                    ],
                                ]
                            ]
                        ]
                    ]
                ]
            ])),
            new MockResponse(json_encode([
                'status' => 'OK',
                'data'   => [
                    'elements' => [
                        [
                            'symbol' => 'FAKE_PRODUCT',
                            'prices' => [
                                'elements' => [],
                                'currency' => 'EUR',
                                'type' => 'GROSS'
                            ]
                        ]
                    ]
                ]
            ])),
        ];
    }

    public function testGetPartDetailsImperialFootprint(): void
    {

        $this->httpClient->setResponseFactory($this->getPartDetailsFootprintTestData());

        # Set config option
        $this->settings->preferMetricFootprint = false;

        $result = $this->provider->getDetails('FAKE_PRODUCT');

        $this->assertSame('footprint_imperial', $result->footprint);
    }

    public function testGetPartDetailsMetricFootprint(): void
    {

        $this->httpClient->setResponseFactory($this->getPartDetailsFootprintTestData());

        # Set config option
        $this->settings->preferMetricFootprint = true;

        $result = $this->provider->getDetails('FAKE_PRODUCT');

        $this->assertSame('footprint_metric', $result->footprint);
    }

    public function testNormalizeURLEncodesBarePctSign(): void
    {
        $method = (new \ReflectionClass($this->provider))->getMethod('normalizeURL');

        $this->assertSame(
            'https://www.tme.eu/en/details/smd0603-5k1-1%25/smd-resistors/royalohm/0603saf5101t5e/',
            $method->invoke($this->provider, '//www.tme.eu/en/details/smd0603-5k1-1%/smd-resistors/royalohm/0603saf5101t5e/')
        );
        $this->assertSame(
            'https://www.tme.eu/en/details/smd0603-5k1-1%25/smd-resistors/royalohm/0603saf5101t5e/',
            $method->invoke($this->provider, '//www.tme.eu/en/details/smd0603-5k1-1%25/smd-resistors/royalohm/0603saf5101t5e/')
        );
        $this->assertSame(
            'https://www.tme.eu/en/details/etqp3m6r8kvp/inductors/panasonic/',
            $method->invoke($this->provider, '//www.tme.eu/en/details/etqp3m6r8kvp/inductors/panasonic/')
        );
        $this->assertSame('https://example.com/path', $method->invoke($this->provider, 'https://example.com/path'));
    }
}
