<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\DTO;

final class Warehouse
{
    public function __construct(
        public readonly string $ref,
        public readonly string $city_ref,
        public readonly string $number,
        public readonly string $description,
        public readonly ?string $description_ru = null,
        public readonly ?float $max_weight_allowed = null,
        public readonly bool $is_poshtomat = false,
        public readonly ?string $category_of_warehouse = null,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function from_api(array $row): self
    {
        $category = isset($row['CategoryOfWarehouse']) ? (string) $row['CategoryOfWarehouse'] : null;

        return new self(
            ref:                   (string) ($row['Ref'] ?? ''),
            city_ref:              (string) ($row['CityRef'] ?? ''),
            number:                (string) ($row['Number'] ?? ''),
            description:           (string) ($row['Description'] ?? ''),
            description_ru:        isset($row['DescriptionRu']) ? (string) $row['DescriptionRu'] : null,
            max_weight_allowed:    isset($row['TotalMaxWeightAllowed']) ? (float) $row['TotalMaxWeightAllowed'] : null,
            is_poshtomat:          'Postomat' === $category,
            category_of_warehouse: $category,
        );
    }

    public function to_select2_option(): array
    {
        return [
            'id'             => $this->ref,
            'text'           => $this->description,
            'ref'            => $this->ref,
            'number'         => $this->number,
            'is_poshtomat'   => $this->is_poshtomat,
            'max_weight_kg'  => $this->max_weight_allowed,
        ];
    }
}
