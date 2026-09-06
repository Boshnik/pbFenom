<?php
declare(strict_types=1);
/*
 * This file is part of pbFenom.
 *
 * (c) 2013 Ivan Shalganov
 *
 * For the full copyright and license information, please view the license.md
 * file that was distributed with this source code.
 */
namespace pbFenom;

/**
 * Lets generated code be include()d without ever touching the filesystem, so the engine
 * needs no eval() at all.
 *
 * Two reasons this is worth a stream wrapper rather than eval():
 *  - eval()'d code reports errors as "Template.php(NNN) : eval()'d code on line 4",
 *    which names neither the template nor anything useful. An include carries the URL,
 *    so the template name lands in the message and the stack trace.
 *  - eval() is what audits, hardened hosts and WAFs look for. A wrapper include needs no
 *    allow_url_include (verified) and runs exactly the same engine-generated code.
 *
 * The wrapper only ever serves strings this class was handed; nothing user-supplied
 * reaches the path.
 */
final class CodeStream
{
    public const PROTOCOL = 'pbfenom';

    /** @var array<string, string> id => PHP source */
    private static array $sources = [];

    private static int $seq = 0;
    private static bool $registered = false;

    /** @var resource|null set by PHP */
    public $context;

    private string $code = '';
    private int $pos = 0;

    /**
     * Evaluate generated PHP without eval() and without a temporary file.
     *
     * @param string $code PHP source, without the opening tag
     * @param string $name template name, used in error messages
     * @return mixed whatever the code returns
     */
    public static function evaluate(string $code, string $name): mixed
    {
        self::register();
        // the id keeps entries apart; the readable suffix is what shows up in errors
        $id = ++self::$seq . '_' . preg_replace('~[^\w.-]+~', '_', $name);
        self::$sources[$id] = "<?php " . $code;
        try {
            return include self::PROTOCOL . '://' . $id;
        } finally {
            unset(self::$sources[$id]);
        }
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            // someone else owns the scheme; refuse rather than fight over it
            throw new \RuntimeException(
                'Stream wrapper "' . self::PROTOCOL . '" is already registered by something else'
            );
        }
        if (!stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new \RuntimeException('Could not register the "' . self::PROTOCOL . '" stream wrapper');
        }
        self::$registered = true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $id = substr($path, strlen(self::PROTOCOL . '://'));
        if (!isset(self::$sources[$id])) {
            return false;
        }
        $this->code = self::$sources[$id];
        $this->pos  = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->code, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->code);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->code)];
    }

    public function url_stat(string $path, int $flags): array
    {
        $id = substr($path, strlen(self::PROTOCOL . '://'));
        return ['size' => strlen(self::$sources[$id] ?? ''), 'mode' => 0100444];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
