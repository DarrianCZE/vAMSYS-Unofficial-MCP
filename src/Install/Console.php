<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

/** Minimal interactive prompts for the CLI installer/updater. */
final class Console
{
    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(private $in = \STDIN, private $out = \STDOUT)
    {
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    public function ask(string $question, string $default = ''): string
    {
        fwrite($this->out, $question . ($default !== '' ? " [{$default}]" : '') . ': ');
        $answer = trim((string) fgets($this->in));

        return $answer === '' ? $default : $answer;
    }

    /** Ask without echoing the input where the terminal allows it. */
    public function secret(string $question): string
    {
        fwrite($this->out, $question . ': ');
        $hide = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && stream_isatty($this->in);
        if ($hide) {
            shell_exec('stty -echo');
        }
        $answer = rtrim((string) fgets($this->in), "\r\n");
        if ($hide) {
            shell_exec('stty echo');
            fwrite($this->out, PHP_EOL);
        }

        return $answer;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $answer = strtolower($this->ask($question . ($default ? ' (Y/n)' : ' (y/N)')));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }
}
