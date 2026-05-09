<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\DTO;

final class Area
{
    public function __construct(
        public readonly string $ref,
        public readonly string $description,
        public readonly ?string $description_ru = null,
        public readonly ?string $center_ref = null,
    ) {}

    /**
     * @param array<string,mixed> $row Single row from AddressGeneral.getAreas response.
     */
    public static function from_api(array $row): self
    {
        return new self(
            ref:            (string) ($row['Ref'] ?? ''),
            description:    (string) ($row['Description'] ?? ''),
            description_ru: isset($row['DescriptionRu']) ? (string) $row['DescriptionRu'] : null,
            center_ref:     isset($row['AreasCenter']) ? (string) $row['AreasCenter'] : null,
        );
    }
}
