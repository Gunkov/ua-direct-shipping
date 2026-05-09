<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Logger;

final class Logger
{
    private static ?self $instance = null;

    private bool $debug_enabled;

    private function __construct()
    {
        $this->debug_enabled = (bool) get_option('uads_debug_mode', false);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function info(string $message, array $context = []): void
    {
        if (!$this->debug_enabled) {
            return;
        }
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log(
                strtolower($level),
                $message . (empty($context) ? '' : ' | ' . wp_json_encode($context)),
                ['source' => 'ua-direct-shipping']
            );
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[uads/%s] %s %s', $level, $message, wp_json_encode($context)));
        }
    }
}
