<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\DTO;

final class TrackingStatus
{
    public function __construct(
        public readonly string $document_number,
        public readonly int $status_code,
        public readonly string $status,
        public readonly ?string $actual_delivery_date = null,
        public readonly ?string $estimated_delivery_date = null,
        public readonly ?string $warehouse_recipient = null,
        public readonly ?string $city_recipient = null,
        public readonly ?float $document_cost = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function from_api(array $row): self
    {
        return new self(
            document_number:         (string) ($row['Number'] ?? $row['DocumentNumber'] ?? ''),
            status_code:             (int)    ($row['StatusCode'] ?? 0),
            status:                  (string) ($row['Status'] ?? ''),
            actual_delivery_date:    isset($row['ActualDeliveryDate']) ? (string) $row['ActualDeliveryDate'] : null,
            estimated_delivery_date: isset($row['ScheduledDeliveryDate']) ? (string) $row['ScheduledDeliveryDate'] : null,
            warehouse_recipient:     isset($row['WarehouseRecipient']) ? (string) $row['WarehouseRecipient'] : null,
            city_recipient:          isset($row['CityRecipient']) ? (string) $row['CityRecipient'] : null,
            document_cost:           isset($row['DocumentCost']) ? (float) $row['DocumentCost'] : null,
            raw:                     $row,
        );
    }
}
