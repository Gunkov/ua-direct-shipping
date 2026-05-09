<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Exception;

class ApiException extends \RuntimeException
{
    /** @var string[] */
    public array $errors;

    /** @var string[] */
    public array $warnings;

    /** @var array<string,mixed> */
    public array $raw_response;

    /**
     * @param string[] $errors
     * @param string[] $warnings
     * @param array<string,mixed> $raw
     */
    public function __construct(string $message, array $errors = [], array $warnings = [], array $raw = [])
    {
        parent::__construct($message);
        $this->errors       = $errors;
        $this->warnings     = $warnings;
        $this->raw_response = $raw;
    }
}
