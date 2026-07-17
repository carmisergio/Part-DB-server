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

use App\Entity\Parts\ManufacturingStatus;
use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Settings\InfoProviderSystem\TMESettings;

class TMEProvider implements InfoProviderInterface, URLHandlerInfoProviderInterface
{

    private const VENDOR_NAME = 'TME';

    public function __construct(private readonly TMEClient $tmeClient, private readonly TMESettings $settings)
    {
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'TME',
            'description' => 'This provider uses the API of TME (Transfer Multipart).',
            'url' => 'https://tme.eu/',
            'disabled_help' => 'Configure the API Token and secret in provider settings to use this provider.',
            'settings_class' => TMESettings::class
        ];
    }

    public function getProviderKey(): string
    {
        return 'tme';
    }

    public function isActive(): bool
    {
        return $this->tmeClient->isUsable();
    }

    public function searchByKeyword(string $keyword, array $options = []): array
    {
        $response = $this->tmeClient->makeRequest('/products/search', [
            'country' => $this->settings->country,
            'scope' => ['products'],
            'phrase' => $keyword,
            'limit' => 100,
        ]);

        $data = $response->toArray()['data'];

        $result = [];

        foreach($data['products']['elements'] ?? [] as $product) {

            $symbol = $product['symbol'];

            $result[] = new SearchResultDTO(
                provider_key: $this->getProviderKey(),
                provider_id: $symbol,
                name: empty($product['manufacturer_symbols']) ? $product['symbol'] : $product['manufacturer_symbols'][0],
                description: $product['description'],
                category: $product['category']['name'] ?? null,
                manufacturer: $product['manufacturer']['name'] ?? null,
                mpn: $product['manufacturer_symbols'][0] ?? null,
                preview_image_url: $this->normalizeURL($product['assets']['primary_photo']['prime'] ?? null),
                manufacturing_status: $this->productStatusArrayToManufacturingStatus($product['product_status'] ?? null),
                provider_url: $this->normalizeURL($this->constructPartInfoUrl($symbol)),
                gtin: $product['ean'] ?? null
            );
        }

        return $result;
    }

    public function getDetails(string $id, array $options = []): PartDetailDTO
    {
        $response = $this->tmeClient->makeRequest('products', [
            'country' => $this->settings->country,
            'symbols' => [$id],
        ]);

        $product = $response->toArray()['data']['elements'][0];

        $product_page = $this->normalizeURL($this->constructPartInfoUrl($id));

        // Get additional product data
        $files = $this->getFiles($id);
        $parameters = $this->getParameters($id, $footprint);
        $vendor_info = $this->getVendorInfo($id, $product_page);

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $id,
            name: empty($product['manufacturer_symbols']) ? $product['symbol'] : $product['manufacturer_symbols'][0],
            description: $product['description'],
            category: $product['category']['name'] ?? null,
            manufacturer: $product['manufacturer']['name'] ?? null,
            mpn: $product['manufacturer_symbols'][0] ?? null,
            preview_image_url: $this->normalizeURL($product['assets']['primary_photo']['high_resolution'] ??
                $product['assets']['primary_photo']['prime'] ?? null),
            manufacturing_status: $this->productStatusArrayToManufacturingStatus($product['product_status'] ?? null),
            provider_url: $this->normalizeURL($this->constructPartInfoUrl($id)),
            gtin: $product['ean'] ?? null,

            footprint: $parameters['footprint'],
            datasheets: $files['datasheets'],
            images: $files['images'],
            parameters: $parameters['parameters'],
            vendor_infos: [$vendor_info],
            mass: ($product['weight']['unit'] ?? null) === 'g' ? $product['weight']['value'] : null,
        );
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::FOOTPRINT,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
        ];
    }

    public function getHandledDomains(): array
    {
        return ['tme.eu'];
    }

    public function getIDFromURL(string $url): ?string
    {
        //Input: https://www.tme.eu/de/details/fi321_se/kuhler/alutronic/
        //The ID is the part after the details segment and before the next slash

        $matches = [];
        if (preg_match('#/details/([^/]+)/#', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Fetches all files for a given product id
     * @param  string  $id
     * @return array<string, list<FileDTO>> An array with the keys 'datasheet'
     * @phpstan-return array{datasheets: list<FileDTO>, images: list<FileDTO>}
     */
    public function getFiles(string $id): array
    {
        $response = $this->tmeClient->makeRequest('products/files', [
            'country' => $this->settings->country,
            'symbols' => [$id],
        ]);

        $files = $response->toArray()['data']['elements'][0];

        //Extract datasheets
        $documents = $files['documents']['elements'];
        $datasheets = [];
        foreach ($documents as $document) {

            // Check document type
            $validDocumentTypes = ['INS', 'DTE', 'KCH', 'GWA', 'INB', 'PRE'];
            if (in_array($document['type'], $validDocumentTypes, true)) {

                $datasheets[] = new FileDTO(
                    url: $this->normalizeURL($document['url']),
                    name: $document['file_name']
                );

            }
        }

        //Extract images
        $images = $files['assets']['additional']['elements'];
        $image_dtos = [];
        foreach($images as $image) {
            $image_url = $this->normalizeURL($image['high_resolution'] ?? $image['prime'] ?? null);
            $image_dtos[] = new FileDTO(url: $image_url);
        }

        return [
            'datasheets' => $datasheets,
            'images' => $image_dtos,
        ];
    }

    /**
     * Fetches the parameters of a product
     * @param  string  $id
     * @return array {footprint: string, parameters: ParameterDTO[]}
     */
    public function getParameters(string $id, string|null &$footprint_name = null): array
    {
        $response = $this->tmeClient->makeRequest('/products/parameters', [
            'country' => $this->settings->country,
            'symbols' => [$id],
        ]);

        $parameters = $response->toArray()['data']['elements'][0]['parameters']['elements'];

        $parameters_dtos = [];

        $footprint = null;
        $footprint_imperial = null;
        $footprint_metric = null;

        foreach($parameters as $parameter) {

            $id = $parameter['id'];
            $value = $parameter['values'][0]['value'];

            $parameters_dtos[] = ParameterDTO::parseValueIncludingUnit($parameter['name'], $value);

            // Check if the parameter is the case/footprint
            // id 35 is Case, id 2932 is Case-inch, id 2931 is Case-mm
            if ($id === 35) {
                $footprint = $value;
            } else if ($id == 2932) {
                $footprint_imperial = $value;
            } else if ($id == 2931) {
                $footprint_metric = $value;
            }
        }

        // Select correct footprint
        $footprint = $this->settings->preferMetricFootprint ?
                ($footprint_metric ?? $footprint ?? $footprint_imperial) :
                ($footprint_imperial ?? $footprint ?? $footprint_metric);

        return [
            'footprint' => $footprint,
            'parameters' => $parameters_dtos
        ];
    }

    /**
     * Fetches the vendor/purchase information for a given product id.
     * @param  string  $id
     * @param  string|null  $productURL
     * @return PurchaseInfoDTO
     */
    public function getVendorInfo(string $id, ?string $productURL = null): PurchaseInfoDTO
    {
        $response = $this->tmeClient->makeRequest('/products/data', [
            'country' => $this->settings->country,
            'currency' => $this->settings->currency,
            'scope' => ['prices'],
            'symbols' => [$id],
        ]);

        $prices = $response->toArray()['data']['elements'][0]['prices'];

        $currency = $prices['currency'];
        $include_tax = $prices['type'] === 'GROSS';

        $prices_dtos = [];
        foreach ($prices['elements'] as $price) {
            $prices_dtos[] = new PriceDTO(
                minimum_discount_amount: $price['amount'],
                price: (string) $price['price'],
                currency_iso_code: $currency,
                includes_tax: $include_tax,
            );
        }

        return new PurchaseInfoDTO(
            distributor_name: self::VENDOR_NAME,
            order_number:  $id,
            prices:  $prices_dtos,
            product_url: $productURL,
        );
    }


    /**
     * Convert the array of product statuses to a single manufacturing status
     * @param  array  $statusArray
     * @return ManufacturingStatus
     */
    private function productStatusArrayToManufacturingStatus(array $statusArray): ManufacturingStatus
    {
        if (in_array('AVAILABLE_WHILE_STOCKS_LAST', $statusArray, true)) {
            return ManufacturingStatus::EOL;
        }

        if (in_array('INVALID', $statusArray, true) ||
            in_array('PRODUCT_BLOCKED', $statusArray, true) ||
            in_array('NOT_IN_OFFER', $statusArray, true)
        ) {
            return ManufacturingStatus::DISCONTINUED;
        }

        //By default we assume that the part is active
        return ManufacturingStatus::ACTIVE;
    }



    private function normalizeURL(?string $url): ?string
    {
        if ($url == null) {
            return null;
        }

        //If a URL starts with // we assume that it is a relative URL and we add the protocol
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        //Encode bare % signs that are not already part of a valid percent-encoded sequence
        //Fixes part numbers with % in them e.g. SMD0603-5K1-1%
        $url = preg_replace('/%(?![0-9A-Fa-f]{2})/', '%25', $url);

        return $url;
    }

    /**
     * Construct the product detail page URL from the symbol.
     * NOTE: the /xx/xx localization part of the URL is omitted, as TME automatically redirects to the correct version of the site
     * @param string $symbol part symbol
     * @return string product detail URL
     */
    private function constructPartInfoUrl(string $symbol): string
    {
        return "https://www.tme.eu/details/{$symbol}";
    }


}
