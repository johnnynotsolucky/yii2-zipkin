# Regex Filter for Monolog

[Monolog](https://github.com/Seldaek/monolog) handler for filtering out logs
with regular expressions.

## Requirements

- PHP ^7.2 or ^8
- Monolog ^2.0

## Installation

```bash
composer require johnnynotsolucky/monolog-regex-handler:^1.0
```

## Motivation

> But why do I need to filter out logs when I control the logs?

Sometimes you don't 😢.

## Usage

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use johnnynotsolucky\RegexHandler\Handler as RegexHandler;

$handler = new RegexHandler([
    '/^spam log$/', // Match on the message
    ['level_name', '/^(INFO|DEBUG)$/'], // Match on the level_name
    [['context', 'email'], '/@domain\.com$/'] // Match on context->email
]);

$log = new Logger('test');

$log->pushHandler(new StreamHandler('php://stdout'));
$log->pushHandler($handler);

$log->warning('spam log');  // Discarded
$log->info('message'); // INFO and DEBUG logs are discarded
$log->warning('message', ['email' => 'someone@domain.com']); // Discarded on email
```

## License

This project is licensed under [the Parity License](LICENSE-PARITY.md).
Third-party contributions are licensed under [Apache-2.0](LICENSE-APACHE.md)
and belong to their respective authors.

The Parity License is a copyleft license that, unlike the GPL family, allows
you to license derivative and connected works under permissive licenses like
MIT or Apache-2.0.
