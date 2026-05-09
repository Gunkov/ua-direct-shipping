<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Cache;

final class TransientCache
{
    public function __construct(private readonly string $prefix = 'uads_') {}

    public function get(string $key): mixed
    {
        $value = get_transient($this->prefix . $key);
        return false === $value ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl_seconds): bool
    {
        return set_transient($this->prefix . $key, $value, $ttl_seconds);
    }

    public function remember(string $key, int $ttl_seconds, callable $callback): mixed
    {
        $value = $this->get($key);
        if (null !== $value) {
            return $value;
        }

        $value = $callback();
        if (null !== $value) {
            $this->set($key, $value, $ttl_seconds);
        }
        return $value;
    }

    public function delete(string $key): bool
    {
        return delete_transient($this->prefix . $key);
    }

    /**
     * Flush all transients matching plugin prefix. Returns count deleted.
     */
    public function flush_all(): int
    {
        global $wpdb;

        $like_value   = '_transient_' . $wpdb->esc_like($this->prefix) . '%';
        $like_timeout = '_transient_timeout_' . $wpdb->esc_like($this->prefix) . '%';

        $deleted_value   = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_value));
        $deleted_timeout = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));

        return $deleted_value;
    }
}
