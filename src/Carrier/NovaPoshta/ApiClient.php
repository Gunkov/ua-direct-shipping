<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta;

use Gunkov\UAShipping\Common\Exception\ApiException;
use Gunkov\UAShipping\Common\Exception\HttpException;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;

final class ApiClient
{
    private const ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct(
        private readonly string $api_key,
        private readonly HttpClient $http,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array<int,array<string,mixed>> List of areas.
     * @throws ApiException
     */
    public function get_areas(): array
    {
        return $this->request('AddressGeneral', 'getAreas');
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function search_settlements(string $city_name, int $limit = 30): array
    {
        $response = $this->request('Address', 'searchSettlements', [
            'CityName' => $city_name,
            'Limit'    => $limit,
        ]);

        // searchSettlements returns nested Addresses[]
        return $response[0]['Addresses'] ?? [];
    }

    /**
     * Real-time rate calculation via InternetDocumentGeneral.getDocumentPrice.
     *
     * @param array{
     *   CitySender:string, CityRecipient:string, Weight:float|string, ServiceType?:string,
     *   Cost:float|string, CargoType?:string, SeatsAmount?:int|string, RedeliveryCalculate?:array
     * } $properties
     * @return array{cost:float,assessed_cost:float,cost_redelivery:float,raw:array<string,mixed>}
     * @throws ApiException
     */
    public function get_document_price(array $properties): array
    {
        $defaults = [
            'ServiceType' => 'WarehouseWarehouse',
            'CargoType'   => 'Cargo',
            'SeatsAmount' => '1',
        ];
        $rows = $this->request('InternetDocumentGeneral', 'getDocumentPrice', array_merge($defaults, $properties));
        if (empty($rows)) {
            throw new ApiException('NP getDocumentPrice returned empty result');
        }
        $first = $rows[0];
        return [
            'cost'            => (float) ($first['Cost'] ?? 0.0),
            'assessed_cost'   => (float) ($first['AssessedCost'] ?? 0.0),
            'cost_redelivery' => (float) ($first['CostRedelivery'] ?? 0.0),
            'raw'             => $first,
        ];
    }

    /**
     * Search streets in a city by name. Used to resolve street UUID before Address.save.
     *
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function get_streets(string $city_ref, string $find_by_string, int $limit = 10): array
    {
        return $this->request('Address', 'getStreet', [
            'CityRef'      => $city_ref,
            'FindByString' => $find_by_string,
            'Limit'        => (string) $limit,
        ]);
    }

    /**
     * Create a counterparty address (for door delivery). Returns the new address row.
     *
     * @param array{CounterpartyRef:string,StreetRef:string,BuildingNumber:string,Flat?:string,Note?:string} $properties
     * @throws ApiException
     */
    public function save_counterparty_address(array $properties): array
    {
        $rows = $this->request('Address', 'save', $properties);
        if (empty($rows[0]['Ref'])) {
            throw new ApiException('NP Address.save returned no Ref');
        }
        return $rows[0];
    }

    /**
     * Track multiple TTNs at once.
     *
     * @param array<int,array{DocumentNumber:string,Phone?:string}> $documents
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function get_status_documents(array $documents): array
    {
        return $this->request('TrackingDocumentGeneral', 'getStatusDocuments', [
            'Documents' => $documents,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function get_warehouses(string $city_ref, string $find = '', int $page = 1, int $limit = 50): array
    {
        $properties = [
            'CityRef' => $city_ref,
            'Page'    => (string) $page,
            'Limit'   => (string) $limit,
        ];
        if ('' !== $find) {
            $properties['FindByString'] = $find;
        }

        return $this->request('Address', 'getWarehouses', $properties);
    }

    /**
     * @param array<string,mixed> $properties
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function request(string $model_name, string $called_method, array $properties = []): array
    {
        $payload = [
            'apiKey'           => $this->api_key,
            'modelName'        => $model_name,
            'calledMethod'     => $called_method,
            'methodProperties' => empty($properties) ? new \stdClass() : $properties,
        ];

        $start = microtime(true);

        try {
            $body = $this->http->post_json(self::ENDPOINT, $payload);
        } catch (HttpException $e) {
            $this->logger->error('NP API HTTP error', [
                'model'  => $model_name,
                'method' => $called_method,
                'error'  => $e->getMessage(),
            ]);
            throw new ApiException('NP API unreachable: ' . $e->getMessage());
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        if (empty($body['success'])) {
            $errors   = is_array($body['errors'] ?? null) ? array_values(array_filter(array_map('strval', $body['errors']))) : ['Unknown NP error'];
            $warnings = is_array($body['warnings'] ?? null) ? array_values(array_filter(array_map('strval', $body['warnings']))) : [];

            $this->logger->error('NP API returned error', [
                'model'    => $model_name,
                'method'   => $called_method,
                'errors'   => $errors,
                'warnings' => $warnings,
                'ms'       => $ms,
            ]);

            throw new ApiException(implode('; ', $errors), $errors, $warnings, $body);
        }

        if ($ms > 2000) {
            $this->logger->warning('NP API slow response', [
                'model'  => $model_name,
                'method' => $called_method,
                'ms'     => $ms,
            ]);
        } else {
            $this->logger->info('NP API call', [
                'model'  => $model_name,
                'method' => $called_method,
                'ms'     => $ms,
            ]);
        }

        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }
}
