<?php

declare(strict_types=1);

namespace Eleph\WordPress\Admin {
    use DateTimeImmutable;
    use DateTimeZone;
    use RuntimeException;

    function current_user_can(string $capability): bool
    {
        return \Eleph\WordPress\Tests\AdminPagesTest::$allowed && 'manage_options' === $capability;
    }

    /** @param array<string, int> $arguments */
    function wp_die(string $message, string $title = '', array $arguments = []): never
    {
        throw new RuntimeException($message, $arguments['response'] ?? 500);
    }

    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    function esc_url(string $value): string
    {
        return esc_html($value);
    }

    function admin_url(string $path): string
    {
        return '/wp-admin/' . $path;
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return \Eleph\WordPress\Tests\AdminPagesTest::$options[$name] ?? $default;
    }

    function wp_date(string $format, int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('America/Los_Angeles'))
            ->format($format);
    }

    function add_menu_page(string $title, string $label, string $capability, string $slug, callable $callback, string $icon): void
    {
        \Eleph\WordPress\Tests\AdminPagesTest::$menus[] = $slug;
    }
}

namespace Eleph\WordPress\Tests {
    use DateTimeImmutable;
    use Eleph\Runtime\Gateway\EntityGateway;
    use Eleph\Runtime\Identity\EntityId;
    use Eleph\Runtime\Policy\AccessDenied;
    use Eleph\Runtime\Query\EntityQuery;
    use Eleph\Runtime\Storage\Offset;
    use Eleph\Runtime\Storage\Page;
    use Eleph\WordPress\Admin\Pages;
    use Eleph\WordPress\Admin\View;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    #[CoversClass(Pages::class)]
    #[CoversClass(View::class)]
    final class AdminPagesTest extends TestCase
    {
        public static bool $allowed = true;

        /** @var list<string> */
        public static array $menus = [];

        /** @var array<string, string> */
        public static array $options = [];

        private string $template;

        protected function setUp(): void
        {
            self::$allowed = true;
            self::$menus = [];
            self::$options = [];
            $_GET = [];
            $file = tempnam(sys_get_temp_dir(), 'eleph-admin-');
            self::assertNotFalse($file);
            $this->template = $file;
            file_put_contents($file, '<?php return static function ($view, $data): void { if (isset($data["record"])) { $view->detail("Items", ["id" => "ID", "name" => "Name"], $data); } else { $view->listing("Items", ["id" => "ID", "name" => "Name"], $data); } };');
        }

        protected function tearDown(): void
        {
            unlink($this->template);
            $_GET = [];
        }

        public function testTemplatesMustExistBeforeRegisteringAMenu(): void
        {
            $gateway = $this->createStub(EntityGateway::class);
            $this->pages($gateway)->register();
            self::assertSame(['eleph-item'], self::$menus);
            self::$menus = [];
            $this->pages($gateway, 'missing-template')->register();
            self::assertSame([], self::$menus);
        }

        public function testNonAdminsCannotLoadRecords(): void
        {
            self::$allowed = false;
            $gateway = $this->createMock(EntityGateway::class);
            $gateway->expects(self::never())->method('all');
            $gateway->expects(self::never())->method('find');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(403);
            $this->pages($gateway)->render('Item');
        }

        public function testListUsesRuntimePaginationAndEscapesValues(): void
        {
            $_GET['offset'] = '50';
            $query = $this->createMock(EntityQuery::class);
            $query->expects(self::once())->method('page')->with(50, (new Offset(50))->toCursor())->willReturn(new Page([$this->record()], (new Offset(100))->toCursor()));
            $gateway = $this->createMock(EntityGateway::class);
            $gateway->expects(self::once())->method('all')->with('Item')->willReturn($query);
            ob_start();
            $this->pages($gateway)->render('Item');
            $html = (string) ob_get_clean();
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('record=7', $html);
            self::assertStringContainsString('offset=100', $html);
        }

        public function testDetailUsesEntityIdThroughTheRuntime(): void
        {
            $_GET['record'] = '7';
            $gateway = $this->createMock(EntityGateway::class);
            $gateway->expects(self::once())->method('find')->with('Item', EntityId::of('7'))->willReturn($this->record());
            ob_start();
            $this->pages($gateway)->render('Item');
            $html = (string) ob_get_clean();
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringContainsString('All records', $html);
        }

        public function testReadPolicyDenialDoesNotExposeARecord(): void
        {
            $_GET['record'] = '7';
            $gateway = $this->createMock(EntityGateway::class);
            $gateway->expects(self::once())->method('find')->willThrowException(new AccessDenied('Item', 'private', 'Denied'));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(404);
            $this->pages($gateway)->render('Item');
        }

        public function testTimestampsUseSiteFormatsAndTimezoneInBothViews(): void
        {
            $record = new class () {
                public function getCreatedAt(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-21T06:30:00Z');
                }
            };

            foreach (['listing', 'detail'] as $method) {
                self::$options = ['date_format' => 'F j, Y', 'time_format' => 'g:i a'];
                $html = $this->renderView($method, $record, ['createdAt' => 'Created at']);
                self::assertStringContainsString('September 20, 2026 11:30 pm', $html);
                self::assertStringNotContainsString('2026-09-21T06:30:00', $html);

                self::$options = ['date_format' => 'd/m/Y', 'time_format' => 'H:i'];
                self::assertStringContainsString('20/09/2026 23:30', $this->renderView($method, $record, ['createdAt' => 'Created at']));
            }
        }

        public function testWebUrlsAreLinkedAndUnsafeValuesRemainTextInBothViews(): void
        {
            $record = new class () {
                public function getWebsite(): string
                {
                    return 'https://example.com/watch?a=1&b=2';
                }

                public function getLegacyWebsite(): string
                {
                    return 'http://example.com/';
                }

                public function getUnsafe(): string
                {
                    return 'javascript:alert(1)';
                }

                public function getMarkup(): string
                {
                    return '<a href="https://example.com/">Untrusted</a>';
                }

                public function getDescription(): string
                {
                    return 'See https://example.com/ for details';
                }
            };
            $fields = ['website' => 'Website', 'legacyWebsite' => 'Legacy', 'unsafe' => 'Unsafe', 'markup' => 'Markup', 'description' => 'Description'];

            foreach (['listing', 'detail'] as $method) {
                $html = $this->renderView($method, $record, $fields);
                self::assertStringContainsString('<a href="https://example.com/watch?a=1&amp;b=2">https://example.com/watch?a=1&amp;b=2</a>', $html);
                self::assertStringContainsString('<a href="http://example.com/">http://example.com/</a>', $html);
                self::assertStringContainsString('javascript:alert(1)', $html);
                self::assertStringNotContainsString('href="javascript:', $html);
                self::assertStringContainsString('&lt;a href=', $html);
                self::assertStringNotContainsString('>Untrusted</a>', $html);
                self::assertStringContainsString('See https://example.com/ for details', $html);
            }
        }

        /** @param array<string, string> $fields */
        private function renderView(string $method, object $record, array $fields): string
        {
            ob_start();

            try {
                $view = new View('eleph-item');

                if ('listing' === $method) {
                    $view->listing('Items', $fields, ['records' => [$record]]);
                } else {
                    $view->detail('Item', $fields, ['record' => $record]);
                }

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        }

        private function pages(EntityGateway $gateway, ?string $detail = null): Pages
        {
            return new Pages($gateway, dirname($this->template), ['Item' => ['label' => 'Items', 'slug' => 'eleph-item', 'parent' => null, 'list' => basename($this->template), 'detail' => $detail ?? basename($this->template)]]);
        }

        private function record(): object
        {
            return new class () {
                public function getId(): EntityId
                {
                    return EntityId::of(7);
                }

                public function getName(): string
                {
                    return '<script>';
                }
            };
        }
    }
}
