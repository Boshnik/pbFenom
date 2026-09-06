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
use pbFenom\Error\CompileException;
use pbFenom\Provider;
use pbFenom\ProviderInterface;
use pbFenom\Render;
use pbFenom\Template;

/**
 * pbFenom Template Engine
 *
 *
 * @author     Ivan Shalganov <a.cobest@gmail.com>
 */
class pbFenom
{
    const VERSION = '3.1';
    const REV = 0;
    /* Actions */
    const INLINE_COMPILER = 1;
    const BLOCK_COMPILER  = 5;
    const INLINE_FUNCTION = 2;
    const BLOCK_FUNCTION  = 7;

    /* Options */
    const DENY_ACCESSOR     = 0x8;
    const DENY_METHODS      = 0x10;
    const DENY_NATIVE_FUNCS = 0x20;
    const FORCE_INCLUDE     = 0x40;
    const AUTO_RELOAD       = 0x80;
    const FORCE_COMPILE     = 0x100;
    const AUTO_ESCAPE       = 0x200;
    const DISABLE_CACHE     = 0x400;
    const FORCE_VERIFY      = 0x800;
    const DENY_PHP_CALLS    = 0x2000;
    const AUTO_STRIP        = 0x4000;
    /**
     * Emit a `/* name:line: {tag} *\/` comment before every compiled tag.
     * Useful when reading the cache; off by default because it is ~45% of the
     * generated file and costs opcache memory for no runtime benefit.
     */
    const DEBUG_COMMENTS    = 0x8000;
    /**
     * Use DENY_PHP_CALLS
     * @deprecated
     */
    const DENY_STATICS      = 0x2000;

    /* Default parsers */
    const DEFAULT_CLOSE_COMPILER = 'pbFenom\Compiler::stdClose';
    const DEFAULT_FUNC_PARSER    = 'pbFenom\Compiler::stdFuncParser';
    const DEFAULT_FUNC_OPEN      = 'pbFenom\Compiler::stdFuncOpen';
    const DEFAULT_FUNC_CLOSE     = 'pbFenom\Compiler::stdFuncClose';
    const SMART_FUNC_PARSER      = 'pbFenom\Compiler::smartFuncParser';
    /**
     * Hands the callback the raw ($params, $tpl, $var) triple instead of mapping the
     * tag's arguments onto its signature. Was the default before 1.1.0; pass it
     * explicitly to addFunction() if you want it.
     */
    const RAW_FUNC_PARSER        = 'pbFenom\Compiler::stdFuncParser';

    const MAX_MACRO_RECURSIVE = 32;

    /**
     * Bumped whenever the shape of a compiled template changes, so that stale
     * artifacts from an older pbFenom are not loaded. Part of getSignature().
     */
    const CACHE_FORMAT = 2;

    /**
     * @var int maximum depth of {extends}/{include} nesting before compilation aborts.
     * Without it a cyclic or self-referencing {extends} spins forever at 100% CPU,
     * below every PHP limit, and never returns.
     */
    public static int $max_template_depth = 32;

    const ACCESSOR_CUSTOM   = null;
    const ACCESSOR_VAR      = 'pbFenom\Accessor::parserVar';
    const ACCESSOR_CALL     = 'pbFenom\Accessor::parserCall';
    const ACCESSOR_PROPERTY = 'pbFenom\Accessor::parserProperty';
    const ACCESSOR_METHOD   = 'pbFenom\Accessor::parserMethod';
    const ACCESSOR_CHAIN    = 'pbFenom\Accessor::parserChain';

    public static string $charset = "UTF-8";

    /**
     * @var int maximum length of compiled filename (use sha1 of name if bigger)
     */
    public static int $filename_length = 200;

    /**
     * @var int[] of possible options, as associative array
     * @see setOptions
     */
    private static array $_options_list = [
        "disable_accessor"     => self::DENY_ACCESSOR,
        "disable_methods"      => self::DENY_METHODS,
        "disable_native_funcs" => self::DENY_NATIVE_FUNCS,
        "disable_cache"        => self::DISABLE_CACHE,
        "force_compile"        => self::FORCE_COMPILE,
        "auto_reload"          => self::AUTO_RELOAD,
        "force_include"        => self::FORCE_INCLUDE,
        "auto_escape"          => self::AUTO_ESCAPE,
        "force_verify"         => self::FORCE_VERIFY,
        "disable_php_calls"    => self::DENY_PHP_CALLS,
        "disable_statics"      => self::DENY_STATICS,
        "strip"                => self::AUTO_STRIP,
        "debug_comments"       => self::DEBUG_COMMENTS,
    ];

    /**
     * @var callable[]
     */
    protected array $pre_filters = [];

    /**
     * @var callable[]
     */
    protected array $filters = [];

    /**
     * @var callable[]
     */
    protected array $tag_filters = [];

    /**
     * @var string[]
     */
    protected array $call_filters = [];

    /**
     * @var callable[]
     */
    protected array $post_filters = [];

    /**
     * @var pbFenom\Render[] Templates storage
     */
    protected array $_storage = [];

    /**
     * @var string compile directory
     */
    protected string $_compile_dir = "";

    /**
     * @var bool skip the world-writable check on the compile directory
     */
    protected bool $_allow_shared_compile_dir = false;

    /**
     * @var string compile prefix ID template
     */
    protected string $_compile_id = "";

    /**
     * @var string[] compile directory for custom provider
     */
    protected array $_compiles = [];

    /**
     * @var int masked options
     */
    protected int $_options = 0;

    /**
     * @var string|null memoised getSignature() result
     */
    private ?string $_signature = null;

    /**
     * @var int nesting level of the current render. A cyclic {include} otherwise
     * recurses until PHP exhausts the call stack, which is a *fatal* error:
     * uncatchable, no context, blank 500. Kept as a plain counter rather than a
     * stack of names — this is on the per-{include} hot path.
     */
    private int $_render_depth = 0;

    /**
     * @var int nesting level of compile(), for cyclic {insert}
     */
    private int $_compile_depth = 0;

    /**
     * @var ProviderInterface
     */
    private ProviderInterface $_provider;
    /**
     * @var pbFenom\ProviderInterface[]
     */
    protected array $_providers = [];

    /**
     * @var string[] list of modifiers [modifier_name => callable]
     */
    protected array $_modifiers = [
        "upper"       => 'strtoupper',
        "up"          => 'strtoupper',
        "lower"       => 'strtolower',
        "low"         => 'strtolower',
        "date_format" => 'pbFenom\Modifier::dateFormat',
        "date"        => 'pbFenom\Modifier::date',
        "truncate"    => 'pbFenom\Modifier::truncate',
        "escape"      => 'pbFenom\Modifier::escape',
        "e"           => 'pbFenom\Modifier::escape', // alias of escape
        "unescape"    => 'pbFenom\Modifier::unescape',
        "strip"       => 'pbFenom\Modifier::strip',
        "length"      => 'pbFenom\Modifier::length',
        "iterable"    => 'pbFenom\Modifier::isIterable',
        "replace"     => 'pbFenom\Modifier::replace',
        "ereplace"    => 'pbFenom\Modifier::ereplace',
        "match"       => 'pbFenom\Modifier::match',
        "ematch"      => 'pbFenom\Modifier::ematch',
        "split"       => 'pbFenom\Modifier::split',
        "esplit"      => 'pbFenom\Modifier::esplit',
        "join"        => 'pbFenom\Modifier::join',
        "in"          => 'pbFenom\Modifier::in',
        "range"       => 'pbFenom\Modifier::range',
    ];

    /**
     * @var array of allowed PHP functions
     */
    protected array $_allowed_funcs = [
        "count"       => 1,
        "is_string"   => 1,
        "is_array"    => 1,
        "is_numeric"  => 1,
        "is_int"      => 1,
        'constant'    => 1,
        "is_object"   => 1,
        "strtotime"   => 1,
        "gettype"     => 1,
        "is_double"   => 1,
        "json_encode" => 1,
        "json_decode" => 1,
        "ip2long"     => 1,
        "long2ip"     => 1,
        "strip_tags"  => 1,
        "nl2br"       => 1,
        "explode"     => 1,
        "implode"     => 1
    ];

    /**
     * @var string[] the disabled functions by `disable_functions` PHP's option
     */
    protected array $_disabled_funcs = [];

    /**
     * @var array[] of compilers and functions
     */
    protected array $_actions = [
        'foreach'    => [ // {foreach ...} {break} {continue} {foreachelse} {/foreach}
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'pbFenom\Compiler::foreachOpen',
            'close'      => 'pbFenom\Compiler::foreachClose',
            'tags'       => [
                'foreachelse' => 'pbFenom\Compiler::foreachElse',
                'break'       => 'pbFenom\Compiler::tagBreak',
                'continue'    => 'pbFenom\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'if'         => [ // {if ...} {elseif ...} {else} {/if}
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::ifOpen',
            'close' => 'pbFenom\Compiler::stdClose',
            'tags'  => [
                'elseif' => 'pbFenom\Compiler::tagElseIf',
                'else'   => 'pbFenom\Compiler::tagElse'
            ]
        ],
        'switch'     => [ // {switch ...} {case ..., ...}  {default} {/switch}
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'pbFenom\Compiler::switchOpen',
            'close'      => 'pbFenom\Compiler::switchClose',
            'tags'       => [
                'case'    => 'pbFenom\Compiler::tagCase',
                'default' => 'pbFenom\Compiler::tagDefault'
            ],
            'float_tags' => ['break' => 1]
        ],
        'for'        => [ // {for ...} {break} {continue} {/for}
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'pbFenom\Compiler::forOpen',
            'close'      => 'pbFenom\Compiler::forClose',
            'tags'       => [
                'forelse'  => 'pbFenom\Compiler::forElse',
                'break'    => 'pbFenom\Compiler::tagBreak',
                'continue' => 'pbFenom\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'while'      => [ // {while ...} {break} {continue} {/while}
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'pbFenom\Compiler::whileOpen',
            'close'      => 'pbFenom\Compiler::stdClose',
            'tags'       => [
                'break'    => 'pbFenom\Compiler::tagBreak',
                'continue' => 'pbFenom\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'include'    => [ // {include ...}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagInclude'
        ],
        'insert'     => [ // {include ...}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagInsert'
        ],
        'var'       => [ // {var ...}
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::setOpen',
            'close' => 'pbFenom\Compiler::setClose'
        ],
        'set'       => [ // {set ...}
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::setOpen',
            'close' => 'pbFenom\Compiler::setClose'
        ],
        'add'       => [ // {add ...}
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::setOpen',
            'close' => 'pbFenom\Compiler::setClose'
        ],
        'do'     => [ // {do ...}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagDo'
        ],
        'block'      => [ // {block ...} {parent} {/block}
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'pbFenom\Compiler::tagBlockOpen',
            'close'      => 'pbFenom\Compiler::tagBlockClose',
            'tags'       => ['parent' => 'pbFenom\Compiler::tagParent'],
            'float_tags' => ['parent' => 1]
        ],
        'extends'    => [ // {extends ...}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagExtends'
        ],
        'use'        => [ // {use}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagUse'
        ],
        'filter'     => [ // {filter} ... {/filter}
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::filterOpen',
            'close' => 'pbFenom\Compiler::filterClose'
        ],
        'macro'      => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::macroOpen',
            'close' => 'pbFenom\Compiler::macroClose'
        ],
        'import'     => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagImport'
        ],
        'cycle'      => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagCycle'
        ],
        'raw'        => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagRaw'
        ],
        'autoescape' => [ // deprecated
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::escapeOpen',
            'close' => 'pbFenom\Compiler::nope'
        ],
        'escape' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::escapeOpen',
            'close' => 'pbFenom\Compiler::nope'
        ],
        'strip' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::stripOpen',
            'close' => 'pbFenom\Compiler::nope'
        ],
        'ignore' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'pbFenom\Compiler::ignoreOpen',
            'close' => 'pbFenom\Compiler::nope'
        ],
        'unset'  => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagUnset'
        ],
        'paste'  => [ // {include ...}
            'type'   => self::INLINE_COMPILER,
            'parser' => 'pbFenom\Compiler::tagPaste'
        ],
    ];

    /**
     * List of tests
     * @see https://github.com/bzick/fenom/blob/develop/docs/operators.md#test-operator
     * @var array
     */
    protected array $_tests = [
        'integer'  => 'is_int(%s)',
        'int'      => 'is_int(%s)',
        'float'    => 'is_float(%s)',
        'double'   => 'is_float(%s)',
        'decimal'  => 'is_float(%s)',
        'string'   => 'is_string(%s)',
        'bool'     => 'is_bool(%s)',
        'boolean'  => 'is_bool(%s)',
        'number'   => 'is_numeric(%s)',
        'numeric'  => 'is_numeric(%s)',
        'scalar'   => 'is_scalar(%s)',
        'object'   => 'is_object(%s)',
        'callable' => 'is_callable(%s)',
        'callback' => 'is_callable(%s)',
        'array'    => 'is_array(%s)',
        'iterable' => '\pbFenom\Modifier::isIterable(%s)',
        'const'    => 'defined(%s)',
        'template' => '$tpl->getStorage()->templateExists(%s)',
        'empty'    => 'empty(%s)',
        'set'      => 'isset(%s)',
        '_empty'   => '!%s', // for none variable
        '_set'     => '(%s !== null)', // for none variable
        'odd'      => '(%s & 1)',
        'even'     => '!(%s %% 2)',
        'third'    => '!(%s %% 3)'
    ];

    protected array $_accessors = [
        'get'     => 'pbFenom\Accessor::getVar',
        'env'     => 'pbFenom\Accessor::getVar',
        'post'    => 'pbFenom\Accessor::getVar',
        'request' => 'pbFenom\Accessor::getVar',
        'cookie'  => 'pbFenom\Accessor::getVar',
        'globals' => 'pbFenom\Accessor::getVar',
        'server'  => 'pbFenom\Accessor::getVar',
        'session' => 'pbFenom\Accessor::getVar',
        'files'   => 'pbFenom\Accessor::getVar',
        'tpl'     => 'pbFenom\Accessor::tpl',
        'version' => 'pbFenom\Accessor::version',
        'const'   => 'pbFenom\Accessor::constant',
        'php'     => 'pbFenom\Accessor::call',
        'call'    => 'pbFenom\Accessor::call',
        'fetch'   => 'pbFenom\Accessor::fetch',
        'block'   => 'pbFenom\Accessor::block',
    ];

    /**
     * Just factory
     *
     * @param string|pbFenom\ProviderInterface $source path to templates or custom provider
     * @param string $compile_dir path to compiled files
     * @param int|array $options
     * @throws InvalidArgumentException
     * @return static
     */
    public static function factory(
        string|pbFenom\ProviderInterface $source,
        string $compile_dir,
        int|array $options = 0
    ): static
    {
        if (is_string($source)) {
            $provider = new pbFenom\Provider($source);
        } else {
            $provider = $source;
        }
        $fenom = new static($provider);
        $fenom->setCompileDir($compile_dir);
        if ($options) {
            $fenom->setOptions($options);
        }
        return $fenom;
    }

    /**
     * Subclasses may add behaviour but must keep this signature: factory() calls
     * new static() and cannot know about a widened constructor.
     *
     * @final
     * @param pbFenom\ProviderInterface $provider
     */
    public function __construct(pbFenom\ProviderInterface $provider)
    {
        $this->_provider = $provider;
    }

    /**
     * Set compile directory
     *
     * @param string $dir directory to store compiled templates in
     * @throws LogicException
     * @return $this
     */
    public function setCompileDir(string $dir): static
    {
        if (!is_writable($dir)) {
            throw new LogicException("Cache directory $dir is not writable");
        }
        // Compiled templates are PHP that gets include()d unconditionally, so the
        // compile dir is executable code. A world-writable one (the old '/tmp'
        // default) lets any local user drop in a file under a fully predictable
        // name and have it run as the web user.
        if (!$this->_allow_shared_compile_dir
            && ($perms = @fileperms($dir)) !== false && ($perms & 0002)) {
            throw new LogicException(
                "Cache directory $dir is world-writable; compiled templates are executed as PHP. "
                . "Use a private directory, or call allowSharedCompileDir() if this is intentional."
            );
        }
        $this->_compile_dir = $dir;
        return $this;
    }

    /**
     * Opt out of the world-writable compile-directory check.
     */
    public function allowSharedCompileDir(bool $allow = true): static
    {
        $this->_allow_shared_compile_dir = $allow;
        return $this;
    }

    /**
     * Set compile prefix ID template
     *
     * @param string $id prefix ID to store compiled templates
     * @return $this
     */
    public function setCompileId(string $id): static
    {
        $this->_compile_id = $id;
        return $this;
    }

    /**
     *
     * @param callable $cb
     * @return $this
     */
    public function addPreFilter(callable $cb): static
    {
        $this->pre_filters[] = $cb;
        return $this;
    }

    /**
     * @return callable[]
     */
    public function getPreFilters(): array
    {
        return $this->pre_filters;
    }

    /**
     *
     * @param callable $cb
     * @return $this
     */
    public function addPostFilter(callable $cb): static
    {
        $this->post_filters[] = $cb;
        return $this;
    }

    /**
     * @return callable[]
     */
    public function getPostFilters(): array
    {
        return $this->post_filters;
    }

    /**
     * @param callable $cb
     * @return static
     */
    public function addFilter(callable $cb): static
    {
        $this->filters[] = $cb;
        return $this;
    }


    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @param callable $cb
     * @return static
     */
    public function addTagFilter(callable $cb): static
    {
        $this->tag_filters[] = $cb;
        return $this;
    }


    public function getTagFilters(): array
    {
        return $this->tag_filters;
    }

    /**
     * Add modifier
     *
     * @param string $modifier the modifier name
     * @param callable $callback the modifier callback
     * @return static
     */
    public function addModifier(string $modifier, callable $callback): static
    {
        $this->_resetSignature();
        $this->_modifiers[$modifier] = $callback;
        return $this;
    }

    /**
     * Add inline tag compiler
     *
     * @param string $compiler
     * @param callable $parser
     * @return static
     */
    public function addCompiler(string $compiler, callable $parser): static
    {
        $this->_resetSignature();
        $this->_actions[$compiler] = array(
            'type'   => self::INLINE_COMPILER,
            'parser' => $parser
        );
        return $this;
    }

    /**
     * @param string $compiler
     * @param string|object $storage
     * @return $this
     */
    public function addCompilerSmart(string $compiler, string|object $storage): static
    {
        if (method_exists($storage, "tag" . $compiler)) {
            $this->_resetSignature();
        $this->_actions[$compiler] = array(
                'type'   => self::INLINE_COMPILER,
                'parser' => array($storage, "tag" . $compiler)
            );
        }
        return $this;
    }

    /**
     * Add block compiler
     *
     * @param string $compiler
     * @param callable $open_parser
     * @param callable $close_parser
     * @param array $tags
     * @return static
     */
    public function addBlockCompiler(
        string $compiler,
        callable $open_parser,
        callable $close_parser = self::DEFAULT_CLOSE_COMPILER,
        array $tags = []
    ): static
    {
        $this->_resetSignature();
        $this->_actions[$compiler] = array(
            'type'  => self::BLOCK_COMPILER,
            'open'  => $open_parser,
            'close' => $close_parser ? : self::DEFAULT_CLOSE_COMPILER,
            'tags'  => $tags,
        );
        return $this;
    }

    /**
     * @param string $compiler
     * @param string|object $storage
     * @param array $tags
     * @param array $floats
     * @throws LogicException
     * @return static
     */
    public function addBlockCompilerSmart(
        string $compiler,
        string|object $storage,
        array $tags,
        array $floats = []
    ): static
    {
        $c = array(
            'type'       => self::BLOCK_COMPILER,
            "tags"       => array(),
            "float_tags" => array()
        );
        if (method_exists($storage, $compiler . "Open")) {
            $c["open"] = array($storage, $compiler . "Open");
        } else {
            throw new \LogicException("Open compiler {$compiler}Open not found");
        }
        if (method_exists($storage, $compiler . "Close")) {
            $c["close"] = array($storage, $compiler . "Close");
        } else {
            throw new \LogicException("Close compiler {$compiler}Close not found");
        }
        foreach ($tags as $tag) {
            if (method_exists($storage, "tag" . $tag)) {
                $c["tags"][$tag] = array($storage, "tag" . $tag);
                if ($floats && in_array($tag, $floats)) {
                    $c['float_tags'][$tag] = 1;
                }
            } else {
                throw new \LogicException("Tag compiler $tag (tag{$compiler}) not found");
            }
        }
        $this->_resetSignature();
        $this->_actions[$compiler] = $c;
        return $this;
    }

    /**
     * @param string $function
     * @param callable $callback
     * @param callable $parser
     * @return static
     */
    public function addFunction(string $function, callable $callback, ?callable $parser = null): static
    {
        $this->_resetSignature();
        $this->_actions[$function] = array(
            'type'     => self::INLINE_FUNCTION,
            // Default to the smart parser: the callback's own signature is its template
            // API, which is what everyone expects. The old default handed the callback
            // ($params, $tpl, $var) and made writing a function needlessly awkward —
            // ask for it explicitly with pbFenom::RAW_FUNC_PARSER.
            'parser'   => $parser ?? self::SMART_FUNC_PARSER,
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param string $function
     * @param callable $callback
     * @return static
     */
    public function addFunctionSmart(string $function, callable $callback): static
    {
        $this->_resetSignature();
        $this->_actions[$function] = array(
            'type'     => self::INLINE_FUNCTION,
            'parser'   => self::SMART_FUNC_PARSER,
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param string $function
     * @param callable $callback
     * @param callable $parser_open
     * @param callable $parser_close
     * @return static
     */
    public function addBlockFunction(
        string $function,
        callable $callback,
        callable $parser_open = self::DEFAULT_FUNC_OPEN,
        callable $parser_close = self::DEFAULT_FUNC_CLOSE
    ): static
    {
        $this->_resetSignature();
        $this->_actions[$function] = array(
            'type'     => self::BLOCK_FUNCTION,
            'open'     => $parser_open,
            'close'    => $parser_close,
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param array $funcs
     * @return static
     */
    public function addAllowedFunctions(array $funcs): static
    {
        $this->_allowed_funcs = $this->_allowed_funcs + array_flip($funcs);
        return $this;
    }

    /**
     * Add custom test
     * @param string $name test name
     * @param string $code test PHP code. Code may contain placeholder %s, which will be replaced by test-value. For example: is_callable(%s)
     */
    public function addTest(string $name, string $code): static
    {
        $this->_resetSignature();
        $this->_tests[$name] = $code;
        return $this;
    }

    /**
     * Get test code by name
     * @param string $name
     * @return string
     */
    public function getTest(string $name): string
    {
        return $this->_tests[$name] ?? "";
    }

    /**
     * Return modifier function
     *
     * @param string $modifier
     * @param Template|null $template
     * @return callable|null
     */
    public function getModifier(string $modifier, ?pbFenom\Template $template = null): ?callable
    {
        if (isset($this->_modifiers[$modifier])) {
            return $this->_modifiers[$modifier];
        } elseif ($this->isAllowedFunction($modifier)) {
            return $modifier;
        } else {
            return $this->_loadModifier($modifier, $template);
        }
    }

    /**
     * Modifier autoloader
     * @param string $modifier
     * @param Template $template
     * @return string|null
     */
    protected function _loadModifier(string $modifier, ?pbFenom\Template $template): ?string
    {
        return null;
    }

    /**
     * Returns tag info
     *
     * @param string $tag
     * @param Template|null $template
     * @return array|null
     */
    public function getTag(string $tag, ?Template $template = null): ?array
    {
        if (isset($this->_actions[$tag])) {
            return $this->_actions[$tag];
        } else {
            return $this->_loadTag($tag, $template);
        }
    }

    /**
     * Tags autoloader
     * @param string $tag
     * @param pbFenom\Template $template
     * @return array|null
     */
    protected function _loadTag(string $tag, ?Template $template): ?array
    {
        return null;
    }

    /**
     * Checks if is allowed PHP function for using in templates.
     *
     * @param string $function the function name
     * @return bool
     */
    public function isAllowedFunction(string $function): bool
    {
        $allow = ($this->_options & self::DENY_NATIVE_FUNCS)
            ? isset($this->_allowed_funcs[$function])
            : function_exists($function);
        return $allow && !in_array($function, $this->_getDisabledFuncs(), true);
    }

    /**
     * Returns the disabled PHP functions.
     *
     * @return string[]
     */
    protected function _getDisabledFuncs(): array
    {
        return $this->_disabled_funcs;
    }

    /**
     * @param string $tag
     * @return array
     */
    public function getTagOwners(string $tag): array
    {
        $tags = [];
        foreach ($this->_actions as $owner => $params) {
            if (isset($params["tags"][$tag])) {
                $tags[] = $owner;
            }
        }
        return $tags;
    }

    /**
     * Add source template provider by scheme
     *
     * @param string $scm scheme name
     * @param pbFenom\ProviderInterface $provider provider object
     * @param string|null $compile_path
     * @return $this
     */
    public function addProvider(string $scm, ProviderInterface $provider, ?string $compile_path = null): static
    {
        $this->_providers[$scm] = $provider;
        if ($compile_path) {
            $this->_compiles[$scm] = $compile_path;
        }
        return $this;
    }

    /**
     * Set options
     * @param int|array $options
     * @return $this
     */
    public function setOptions(int|array $options): static
    {
        if (is_array($options)) {
            $options = self::_makeMask($options, self::$_options_list, $this->_options);
        }
        $this->_storage = array();
        $this->_options = $options;
        return $this;
    }

    /**
     * Get options as bits
     * @return int
     */
    public function getOptions(): int
    {
        return $this->_options;
    }

    /**
     * Add global accessor ($.)
     * @param string $name
     * @param callable $parser
     * @return static
     */
    public function addAccessor(string $name, callable $parser): static
    {
        $this->_resetSignature();
        $this->_accessors[$name] = $parser;
        return $this;
    }

    /**
     * Add global accessor as PHP code ($.)
     * @param string $name
     * @param mixed $accessor
     * @param string $parser
     * @return static
     */
    public function addAccessorSmart(string $name, mixed $accessor, string $parser = self::ACCESSOR_VAR): static
    {
        $this->_accessors[$name] = array(
            "accessor" => $accessor,
            "parser" => $parser,
        );
        return $this;
    }

    /**
     * Add global accessor handler as callback ($.X)
     * @param string $name
     * @param callable $callback
     * @return static
     */
    public function addAccessorCallback(string $name, callable $callback): static
    {
        $this->_accessors[$name] = array(
            "callback" => $callback
        );
        return $this;
    }

    /**
     * Remove accessor
     * @param string $name
     * @return static
     */
    public function removeAccessor(string $name): static
    {
        $this->_resetSignature();
        unset($this->_accessors[$name]);
        return $this;
    }

    /**
     * Get an accessor
     * @param string $name
     * @param string|null $key
     * @return callable|array|null
     */
    public function getAccessor(string $name, ?string $key = null): mixed
    {
        if(isset($this->_accessors[$name])) {
            if($key) {
                return $this->_accessors[$name][$key];
            } else {
                return $this->_accessors[$name];
            }
        } else {
            return null;
        }
    }

    /**
     * Add filter for $.php accessor.
     * Uses glob syntax.
     * @param string $pattern
     * @return $this
     */
    public function addCallFilter(string $pattern): static
    {
        $this->call_filters[] = $pattern;
        return $this;
    }

    /**
     * @param string|null $scm
     * @return ProviderInterface
     * @throws InvalidArgumentException
     */
    public function getProvider(?string $scm = null): pbFenom\ProviderInterface
    {
        if ($scm) {
            if (isset($this->_providers[$scm])) {
                return $this->_providers[$scm];
            } else {
                throw new InvalidArgumentException("Provider for '$scm' not found");
            }
        } else {
            return $this->_provider;
        }
    }

    /**
     * Return empty template
     *
     * @return pbFenom\Template
     */
    public function getRawTemplate(?Template $parent = null): Template
    {
        return new Template($this, $this->_options, $parent);
    }

    /**
     * Execute template and write result into stdout
     *
     * @param string|array $template name of template.
     * If it is array of names of templates they will be extended from left to right.
     * @param array $vars array of data for template
     * @return array
     * @throws CompileException
     */
    public function display(string|array $template, array $vars = array()): array
    {
        return $this->getTemplate($template)->display($vars);
    }

    /**
     *
     * @param array|string $template name of template.
     * If it is array of names of templates they will be extended from left to right.
     * @param array $vars array of data for template
     * @return mixed
     * @throws Exception
     */
    public function fetch(array|string $template, array $vars = array()): mixed
    {
        return $this->getTemplate($template)->fetch($vars);
    }

    /**
     * Creates pipe-line of template's data to callback
     * @note Method not works correctly in old PHP 5.3.*
     * @param array|string $template name of the template.
     * If it is array of names of templates they will be extended from left to right.
     * @param callable $callback template's data handler
     * @param array $vars
     * @param int $chunk amount of bytes of chunk
     * @return array
     * @throws CompileException
     */
    public function pipe(array|string $template, callable $callback, array $vars = array(), int $chunk = 1_000_000): array
    {
        ob_start($callback, $chunk, PHP_OUTPUT_HANDLER_STDFLAGS);
        $data = $this->getTemplate($template)->display($vars);
        ob_end_flush();
        return $data;
    }

    /**
     * Get template by name
     *
     * @param string|array $template template name with schema
     * @param int $options additional options and flags
     * @return pbFenom\Render
     * @throws CompileException
     */
    public function getTemplate(string|array $template, int $options = 0): Render
    {
        $options |= $this->_options;
        if (is_array($template)) {
            $key = $options . "@" . implode(",", $template);
        } else {
            $key = $options . "@" . $template;
        }
        if (isset($this->_storage[$key])) {
            /** @var pbFenom\Template $tpl */
            $tpl = $this->_storage[$key];
            if (($this->_options & self::AUTO_RELOAD) && !$tpl->isValid()) {
                $compiled = $this->compile($template, true, $options);
                return $this->_storage[$key] = $this->_load($template, $options, $compiled);
            } else {
                return $tpl;
            }
        } elseif ($this->_options & (self::FORCE_COMPILE | self::DISABLE_CACHE)) {
            $store    = !($this->_options & self::DISABLE_CACHE);
            $compiled = $this->compile($template, $store, $options);
            if ($store) {
                // FORCE_COMPILE writes the artifact and then used to ignore it, rendering
                // through eval() instead. Including the file we just wrote skips the eval
                // and lets opcache keep the compiled opcodes. Only DISABLE_CACHE, which
                // by definition has no file, still needs eval().
                return $this->_load($template, $options, $compiled);
            }
            return $compiled;
        } else {
            return $this->_storage[$key] = $this->_load($template, $options);
        }
    }

    /**
     * Check if template exists
     * @param string $template
     * @return bool
     */
    public function templateExists(string $template): bool
    {
        $key = $this->_options . "@" . $template;
        if (isset($this->_storage[$key])) { // already loaded
            return true;
        }
        if ($provider = strstr($template, ":", true)) {
            if (isset($this->_providers[$provider])) {
                return $this->_providers[$provider]->templateExists(substr($template, strlen($provider) + 1));
            }
        } else {
            return $this->_provider->templateExists($template);
        }
        return false;
    }

    /**
     * Load template from cache or create cache if it doesn't exists.
     *
     * @param string[]|string $template
     * @param int $opts
     * @return pbFenom\Render
     * @throws CompileException
     */
    protected function _load(array|string $template, int $opts, ?Template $compiled = null): Render
    {
        $scm = null;
        if (is_string($template)) {
            if ($provider = strstr($template, ':', true)) {
                $scm = $provider;
            }
        } elseif (is_array($template) && ($provider = strstr($template[0], ':', true))) {
            $scm = $provider;
        }
        $compile_dir = $this->getCompileDir($scm);
        $file_name   = $this->getCompileName($template, $opts);
        $path        = $compile_dir . "/" . $file_name;
        $auto_reload = (bool)($this->_options & self::AUTO_RELOAD);

        if (is_file($path)) {
            $fenom = $this; // used in template
            $_tpl  = include($path);
            /* @var pbFenom\Render $_tpl */
            if ($_tpl instanceof pbFenom\Render && (!$auto_reload || $_tpl->isValid())) {
                return $_tpl;
            }
            // Stale (or unreadable) cache. This used to fall through to the exception
            // below unless the caller happened to pass an already-compiled template,
            // so under AUTO_RELOAD the first request after a template edit died with
            // "failed to store cache" and kept dying until the cache was cleared.
            if ($compiled) {
                return $compiled;
            }
        }

        $tpl = $this->compile($template, true, $opts);
        if (is_file($path)) {
            $fenom = $this;
            $_tpl  = include($path);
            if ($_tpl instanceof pbFenom\Render) {
                return $_tpl;
            }
        }
        return $tpl;
    }

    /**
     * Enter a template at render time, refusing to nest deeper than
     * pbFenom::$max_template_depth.
     *
     * @throws pbFenom\Error\TemplateException
     */
    public function enterRender(string $name): void
    {
        if (++$this->_render_depth > self::$max_template_depth) {
            $this->_render_depth = 0;
            throw new pbFenom\Error\TemplateException(
                "Template nesting is too deep (" . self::$max_template_depth . ") at `$name`, "
                . "probably a cyclic {include}"
            );
        }
    }

    /**
     * Leave the innermost template entered by enterRender().
     */
    public function leaveRender(): void
    {
        if ($this->_render_depth > 0) {
            $this->_render_depth--;
        }
    }

    /**
     * Fingerprint of everything that can change generated code.
     *
     * The compile cache used to be keyed on name + option mask only, so two Fenom
     * instances sharing a compile directory but configured with different modifiers,
     * tags, accessors or charset silently served each other's compiled templates.
     * Only string callables are included: closures compile to a runtime
     * call_user_func() and therefore do not end up baked into the artifact
     * (and could not be hashed stably across processes anyway).
     *
     * @return string
     */
    public function getSignature(): string
    {
        if ($this->_signature === null) {
            $parts = ['charset' => self::$charset, 'format' => self::CACHE_FORMAT];
            foreach (['_modifiers' => $this->_modifiers, '_tests' => $this->_tests] as $key => $set) {
                $parts[$key] = array_map(
                    fn($cb) => is_string($cb) ? $cb : '~closure~',
                    $set
                );
            }
            $parts['_actions']   = array_keys($this->_actions);
            $parts['_accessors'] = array_keys($this->_accessors);
            ksort($parts);
            $this->_signature = substr(hash('sha256', serialize($parts)), 0, 12);
        }
        return $this->_signature;
    }

    /**
     * Drop the memoised signature after a registry change.
     */
    protected function _resetSignature(): void
    {
        $this->_signature = null;
    }

    /**
     * Generate unique name of compiled template
     *
     * @param string|string[] $tpl
     * @param int $options additional options
     * @return string
     */
    public function getCompileName(array|string $tpl, int $options = 0): string
    {
        $options = $this->_options | $options;
        if (is_array($tpl)) {
            $hash = implode(".", $tpl) . ":" . $options . ":" . $this->getSignature();
            foreach ($tpl as &$t) {
                $t = urlencode(str_replace(":", "_", basename($t)));
            }
            $tpl = implode("~", $tpl);
        } else {
            $hash = $tpl . ":" . $options . ":" . $this->getSignature();
            $tpl = urlencode(str_replace(":", "_", basename($tpl)));
        }
        if (strlen($tpl) > self::$filename_length) { // was `$tpl > ...`: a string/int compare
            $tpl = sha1($tpl);
        }
        // crc32 is 32 bits, so two template names collide after ~65k tries and one
        // compiled template gets served for the other. The readable prefix is kept
        // for debuggability; uniqueness comes from the digest.
        return $this->_compile_id . $tpl . "." . substr(hash('sha256', $hash), 0, 32) . ".php";
    }

    /**
     * Get compile directory for a provider or default
     *
     * @param string|null $scm provider schema, null for default
     * @return string compile directory path
     */
    public function getCompileDir(?string $scm = null): string
    {
        if ($scm && isset($this->_compiles[$scm])) {
            return $this->_compiles[$scm];
        }
        return $this->_compile_dir;
    }

    /**
     * Compile and save template
     *
     * @param array|string $tpl
     * @param bool $store store template on disk
     * @param int $options
     * @return Template
     * @throws CompileException
     */
    public function compile(array|string $tpl, bool $store = true, int $options = 0): Template
    {
        $scm = null;
        if (is_string($tpl)) {
            if ($provider = strstr($tpl, ':', true)) {
                $scm = $provider;
            }
        } elseif (is_array($tpl) && ($provider = strstr($tpl[0], ':', true))) {
            $scm = $provider;
        }
        $compile_dir = $this->getCompileDir($scm);

        if ($this->_compile_depth >= self::$max_template_depth) {
            throw new CompileException(
                "Template nesting is too deep (" . self::$max_template_depth . "), probably a cyclic "
                . "{insert} at " . var_export($tpl, true)
            );
        }
        $this->_compile_depth++;
        try {
            return $this->_compile($tpl, $store, $options, $compile_dir);
        } finally {
            $this->_compile_depth--;
        }
    }

    /**
     * @param array|string $tpl
     * @param bool $store
     * @param int $options
     * @param string $compile_dir
     * @return Template
     * @throws CompileException
     */
    private function _compile(array|string $tpl, bool $store, int $options, string $compile_dir): Template
    {
        if (is_string($tpl)) {
            $template = $this->getRawTemplate()->load($tpl);
        } else {
            $template = $this->getRawTemplate()->load($tpl[0], false);
            for($i = 1; $i < count($tpl); $i++) {
                $template->extend($tpl[ $i ]);
            }
        }
        if ($store) {
            $cache_name   = $this->getCompileName($tpl, $options);
            $compile_path = $compile_dir . "/" . $cache_name . "." . mt_rand(0, 100000) . ".tmp";
            if(!file_put_contents($compile_path, $template->getTemplateCode())) {
                throw new CompileException("Can't to write to the file $compile_path. Directory " . $compile_dir . " is writable?");
            }
            @chmod($compile_path, 0640);
            $cache_path = $compile_dir . "/" . $cache_name;
            if (!rename($compile_path, $cache_path)) {
                unlink($compile_path);
                throw new CompileException("Can't to move the file $compile_path -> $cache_path");
            }
        }
        return $template;
    }

    /**
     * Flush internal template in-memory-cache
     */
    public function flush(): void
    {
        $this->_storage = [];
    }

    /**
     * Remove all compiled templates
     */
    public function clearAllCompiles(): void
    {
        Provider::clean($this->_compile_dir);
        foreach ($this->_compiles as $compile_dir) {
            if ($compile_dir !== $this->_compile_dir) {
                Provider::clean($compile_dir);
            }
        }
        $this->flush();
    }

    /**
     * Compile code to template
     *
     * @param string $code
     * @param string $name
     * @return pbFenom\Template
     */
    public function compileCode(string $code, string $name = 'Runtime compile'): Template
    {
        return $this->getRawTemplate()->source($name, $code);
    }


    /**
     * Create bit-mask from associative array use fully associative array possible keys with bit values
     * @static
     * @param array $values custom assoc array, ["a" => true, "b" => false]
     * @param array $options possible values, ["a" => 0b001, "b" => 0b010, "c" => 0b100]
     * @param int $mask the initial value of the mask
     * @return int result, ( $mask | a ) & ~b
     * @throws \RuntimeException if key from custom assoc doesn't exist into possible values
     */
    private static function _makeMask(array $values, array $options, int $mask = 0): int
    {
        foreach ($values as $key => $value) {
            if (isset($options[$key])) {
                if ($value) {
                    $mask |= $options[$key];
                } else {
                    $mask &= ~$options[$key];
                }
            } else {
                throw new \RuntimeException("Undefined option '$key'");
            }
        }
        return $mask;
    }

    /**
     * @return array
     */
    public function getCallFilters(): array
    {
        return $this->call_filters;
    }
}
