<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\DTO;

final class City
{
    public function __construct(
        public readonly string $ref,
        public readonly string $description,
        public readonly ?string $description_ru = null,
        public readonly ?string $area_description = null,
        public readonly ?string $area_ref = null,
        public readonly ?string $delivery_city_ref = null,
    ) {}

    /**
     * Address.searchSettlements row (Addresses[]).
     *
     * @param array<string,mixed> $row
     */
    public static function from_settlement_api(array $row): self
    {
        return new self(
            ref:               (string) ($row['Ref'] ?? ''),
            description:       (string) ($row['MainDescription'] ?? $row['Present'] ?? ''),
            description_ru:    null,
            area_description:  isset($row['Area']) ? (string) $row['Area'] : null,
            area_ref:          null,
            delivery_city_ref: isset($row['DeliveryCity']) ? (string) $row['DeliveryCity'] : null,
        );
    }

    /**
     * Address.getCities row.
     *
     * @param array<string,mixed> $row
     */
    public static function from_cities_api(array $row): self
    {
        return new self(
            ref:              (string) ($row['Ref'] ?? ''),
            description:      (string) ($row['Description'] ?? ''),
            description_ru:   isset($row['DescriptionRu']) ? (string) $row['DescriptionRu'] : null,
            area_description: isset($row['AreaDescription']) ? (string) $row['AreaDescription'] : null,
            area_ref:         isset($row['Area']) ? (string) $row['Area'] : null,
        );
    }

    /**
     * The Ref to use for getWarehouses lookup. searchSettlements returns DeliveryCity
     * for settlements that map to a NP city; for plain getCities the ref itself is the city.
     */
    public function city_ref_for_warehouses(): string
    {
        return $this->delivery_city_ref ?: $this->ref;
    }

    public function to_select2_option(): array
    {
        $label = $this->description;
        if ($this->area_description) {
            $label .= ', ' . $this->area_description . ' обл.';
        }
        return [
            'id'       => $this->ref,
            'text'     => $label,
            'ref'      => $this->ref,
            'city_ref' => $this->city_ref_for_warehouses(),
            'name'     => $this->description,
            'area'     => $this->area_description,
        ];
    }
}
