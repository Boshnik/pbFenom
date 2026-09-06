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

use pbFenom;
use pbFenom\Error\TemplateException;

/**
 * Primitive template
 * @author     Ivan Shalganov <a.cobest@gmail.com>
 */
class Render extends \ArrayObject
{
    private static array $_props = [
        "name"      => "runtime",
        "base_name" => "",
        "scm"       => null,
        "options"   => 0,
        "time"      => 0.0,
        "depends"   => [],
        "macros"    => []
    ];
    /**
     * @var \Closure|null
     */
    protected ?\Closure $_code = null;
    /**
     * Template name
     * @var string
     */
    protected mixed $_name = 'runtime';
    /**
     * Provider's schema
     * @var string|null
     */
    protected ?string $_scm = null;
    /**
     * Basic template name
     * @var string
     */
    protected string $_base_name = 'runtime';
    /**
     * @var pbFenom
     */
    protected pbFenom $_fenom;
    /**
     * Timestamp of compilation
     * @var float
     */
    protected float $_time = 0.0;

    /**
     * @var array depends list
     */
    protected array $_depends = [];

    /**
     * @var int template options (see pbFenom options)
     */
    protected int $_options = 0;

    /**
     * Template provider
     * @var ProviderInterface
     */
    protected ProviderInterface $_provider;

    /**
     * @var \Closure[]
     */
    protected array $_macros;

    /**
     * @var int current recursion depth of callMacro()
     */
    private int $_macro_depth = 0;

    /**
     * @param pbFenom $fenom
     * @param \Closure $code template body
     * @param array $props
     */
    public function __construct(pbFenom $fenom, \Closure $code, array $props = array())
    {
        parent::__construct();
        $this->_fenom = $fenom;
        $props += self::$_props;
        $this->_name      = $props["name"];
        $this->_base_name = $props["base_name"];
        $this->_scm       = $props["scm"];
        $this->_options   = $props["options"];
        $this->_time      = (float)$props["time"];
        $this->_depends   = $props["depends"];
        $this->_macros    = $props["macros"];
        $this->_code      = $code;
    }

    /**
     * Get template storage
     * @return \pbFenom
     */
    public function getStorage(): pbFenom
    {
        return $this->_fenom;
    }

    /**
     * Get list of dependencies.
     * @return array
     */
    public function getDepends(): array
    {
        return $this->_depends;
    }

    /**
     * Get schema name
     * @return string|null
     */
    public function getScm(): ?string
    {
        return $this->_scm;
    }

    /**
     * Get provider of template source
     * @return ProviderInterface
     */
    public function getProvider(): ProviderInterface
    {
        return $this->_fenom->getProvider($this->_scm);
    }

    /**
     * Get name without schema
     * @return string
     */
    public function getBaseName(): string
    {
        return $this->_base_name;
    }

    /**
     * Get parse options
     * @return int
     */
    public function getOptions(): int
    {
        return $this->_options;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->_name;
    }

    /**
     * Get template name
     * @return string
     */
    public function getName(): string
    {
        return $this->_name;
    }

    public function getTime()
    {
        return $this->_time;
    }


    /**
     * Validate template
     * @return bool
     */
    public function isValid(): bool
    {
        foreach ($this->_depends as $scm => $templates) {
            // The common single-dependency case used to take the *slower* path:
            // getLastModified() re-resolves the path on every call, while verify()
            // batches. verify() is also stricter — it compares each dependency
            // against its own recorded mtime instead of this template's compile time.
            if (!$this->_fenom->getProvider($scm)->verify($templates)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get internal macro
     * @param $name
     * @throws \RuntimeException
     * @return mixed
     */
    public function getMacro($name): mixed
    {
        if (empty($this->_macros[$name])) {
            throw new \RuntimeException('macro ' . $name . ' not found');
        }
        return $this->_macros[$name];
    }

    /**
     * Invoke a recursive macro, enforcing pbFenom::MAX_MACRO_RECURSIVE.
     *
     * Without this a runaway macro recursed until PHP's stack guard fired, which
     * depends on zend.max_allowed_stack_size and reports a bare
     * "Maximum call stack size reached" with no hint of which macro.
     *
     * @throws TemplateException
     */
    public function callMacro(string $name, array $vars): void
    {
        if ($this->_macro_depth >= pbFenom::MAX_MACRO_RECURSIVE) {
            $this->_macro_depth = 0;
            throw new TemplateException(
                "Macro '$name' recursed deeper than " . pbFenom::MAX_MACRO_RECURSIVE
                . " levels in the template `{$this->getName()}`"
            );
        }
        $macro = $this->getMacro($name);
        $this->_macro_depth++;
        try {
            $macro($vars, $this);
        } finally {
            $this->_macro_depth--;
        }
    }

    /**
     * Execute template and write into output
     * @param array $values for template
     * @return array
     * @throws TemplateException
     */
    public function display(array $values): array
    {
        $this->_fenom->enterRender($this->getName());
        try {
            ($this->_code)($values, $this);
        } catch (TemplateException $e) {
            // already carries a template name; re-wrapping once per {include} level
            // produced "unhandled exception in `a`: unhandled exception in `b`: ..."
            throw $e;
        } catch (\Throwable $e) {
            throw new TemplateException("unhandled exception in the template `{$this->getName()}`: {$e->getMessage()}", 0, $e);
        } finally {
            $this->_fenom->leaveRender();
        }
        return $values;
    }

    /**
     * Execute template and return result as string
     * @param array $values for template
     * @return string
     * @throws \Exception
     */
    public function fetch(array $values): string
    {
        ob_start();
        try {
            $this->display($values);
            return ob_get_clean();
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Stub
     * @param string $method
     * @param mixed $args
     * @throws \BadMethodCallException
     */
    public function __call(string $method, mixed $args)
    {
        throw new \BadMethodCallException("Unknown method " . $method);
    }
}
