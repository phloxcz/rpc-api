<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures;

use Tracy\ILogger;

final class RecordingLogger implements ILogger
{
    /** @var list<mixed> */
    public array $logged = [];

    public bool $broken = false;

    public function log(mixed $value, string $level = self::INFO)
    {
        if ($this->broken) {
            throw new \RuntimeException('Log directory is not writable.');
        }
        $this->logged[] = $value;
        return null;
    }
}
