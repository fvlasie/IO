<?php
namespace IO\Support;

class Logger
{
    public function __construct(?string $logFile = null)
    {
        // Parameter kept for backward compatibility.
        // Ignored entirely — we use PHP's native error_log().
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function debug(string $message): void
    {
        $this->write('DEBUG', $message);
    }

    private function write(string $level, string $message): void
    {
        error_log(sprintf("[IO %s] %s", $level, $message));
    }
}
