<?php

declare(strict_types=1);

namespace Eleph\WordPress\Admin;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/** Escapes all record values and builds links with Elephentity IDs. */
final readonly class View
{
    public function __construct(private string $slug)
    {
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $data
     */
    public function listing(string $title, array $fields, array $data): void
    {
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1><table class="widefat striped"><thead><tr>';

        foreach ($fields as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }

        echo '</tr></thead><tbody>';
        $records = $data['records'] ?? [];

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!is_object($record)) {
                    continue;
                }

                echo '<tr>';

                foreach ($fields as $field => $label) {
                    $value = $this->value($record, $field);
                    echo '<td>';

                    if ('id' === $field) {
                        echo '<a href="' . esc_url($this->url(['record' => $value])) . '">' . esc_html($value) . '</a>';
                    } else {
                        echo $this->formatted($value);
                    }

                    echo '</td>';
                }

                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        if (is_int($data['next'] ?? null)) {
            echo '<p><a href="' . esc_url($this->url(['offset' => (string) $data['next']])) . '">Next page</a></p>';
        }

        echo '</div>';
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $data
     */
    public function detail(string $title, array $fields, array $data): void
    {
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1><p><a href="' . esc_url($this->url()) . '">All records</a></p><table class="widefat striped">';
        $record = $data['record'] ?? null;

        if (is_object($record)) {
            foreach ($fields as $field => $label) {
                echo '<tr><th>' . esc_html($label) . '</th><td>' . $this->formatted($this->value($record, $field)) . '</td></tr>';
            }
        }

        echo '</table></div>';
    }

    private function value(object $record, string $field): string
    {
        $getter = 'get' . ucfirst($field);
        /** @var mixed $value */
        $value = method_exists($record, $getter) ? $record->{$getter}() : null;

        return match (true) {
            null === $value => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $this->date($value),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private function date(DateTimeInterface $value): string
    {
        $date = get_option('date_format', 'F j, Y');
        $time = get_option('time_format', 'g:i a');
        $format = (is_string($date) ? $date : 'F j, Y') . ' ' . (is_string($time) ? $time : 'g:i a');

        // wp_date localizes the instant using the site's timezone and locale.
        return wp_date($format, $value->getTimestamp()) ?: $value->format(DATE_ATOM);
    }

    /** Render whole HTTP(S) URL values as links; all other values remain escaped text. */
    private function formatted(string $value): string
    {
        $text = esc_html($value);

        if (false === filter_var($value, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return $text;
        }

        $url = esc_url($value);

        return '' === $url ? $text : '<a href="' . $url . '">' . $text . '</a>';
    }

    /** @param array<string, string> $parameters */
    private function url(array $parameters = []): string
    {
        return admin_url('admin.php?' . http_build_query(['page' => $this->slug, ...$parameters]));
    }
}
