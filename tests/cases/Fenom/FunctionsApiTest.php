<?php

namespace pbFenom;

class ApiCallables
{
    public function pad(string $s, int $n = 3): string { return str_pad($s, $n, '.'); }
    public static function up(string $s): string { return strtoupper($s); }
    public function __invoke(string $s): string { return "[$s]"; }
}

/**
 * addFunctionSmart() called strpos() on the callback, so it only ever accepted string
 * callables — a closure was a TypeError. addFunction() now defaults to that parser.
 */
class FunctionsApiTest extends TestCase
{
    private string $sandbox;

    public function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/pbfenom_universal_' . getmypid();
        Provider::rm($this->sandbox);
        @mkdir($this->sandbox . '/tpl', 0777, true);
        @mkdir($this->sandbox . '/cache', 0777, true);
    }

    public function tearDown(): void
    {
        Provider::rm($this->sandbox);
        parent::tearDown();
    }

    private function fenom(int|array $options = 0): \pbFenom
    {
        return \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache', $options);
    }

    public static function providerCallableForms(): array
    {
        return array(
            'closure'          => array(fn(string $s, int $n = 3): string => str_pad($s, $n, '.')),
            'array callable'   => array(array(new ApiCallables, 'pad')),
            'static string'    => array('pbFenom\ApiCallables::up'),
            'invokable object' => array(new ApiCallables),
        );
    }

    /**
     * @dataProvider providerCallableForms
     */
    public function testSmartFunctionAcceptsEveryCallableForm(callable $cb)
    {
        $fenom = $this->fenom();
        $fenom->addFunctionSmart('probe', $cb);
        $this->assertNotSame('', $fenom->compileCode('{probe s="ab"}')->fetch(array()));
    }

    /**
     * Named arguments are the callable's *parameter names*, so a native function
     * exposes PHP's own naming.
     */
    public function testNativeFunctionUsesItsOwnParameterNames()
    {
        $fenom = $this->fenom();
        $fenom->addFunctionSmart('shout', 'strtoupper');
        $this->assertSame('AB', $fenom->compileCode('{shout string="ab"}')->fetch(array()));
        $this->assertSame('AB', $fenom->compileCode('{shout "ab"}')->fetch(array()));
    }

    public static function providerBothCallForms(): array
    {
        return array(
            'modifier, defaults'        => array('{$body|excerpt}', 'one two three…'),
            'modifier with an argument' => array('{$body|excerpt:2}', 'one two…'),
            'modifier, two arguments'   => array('{$body|excerpt:2:"..."}', 'one two...'),
            'function, positional'      => array('{excerpt $body}', 'one two three…'),
            'function, positional args' => array('{excerpt $body 2}', 'one two…'),
            'function, named'           => array('{excerpt text=$body words=2}', 'one two…'),
            'function, any order'       => array('{excerpt words=2 text=$body}', 'one two…'),
            'function, all named'       => array('{excerpt text=$body words=2 etc="!"}', 'one two!'),
            'in a modifier chain'       => array('{$body|excerpt:2|upper}', 'ONE TWO…'),
        );
    }

    /**
     * @dataProvider providerBothCallForms
     */
    public function testOneCallableServesBothCallForms(string $tpl, string $expected)
    {
        $excerpt = function (string $text, int $words = 3, string $etc = '…'): string {
            $parts = preg_split('/\s+/u', trim($text));
            return count($parts) <= $words
                ? $text
                : implode(' ', array_slice($parts, 0, $words)) . $etc;
        };
        $fenom = $this->fenom();
        // the same callable registered both ways — two explicit calls, so it is obvious
        // that the second one also claims a tag name
        $fenom->addModifier('excerpt', $excerpt);
        $fenom->addFunctionSmart('excerpt', $excerpt);
        $this->assertSame($expected, $fenom->compileCode($tpl)->fetch(array('body' => 'one two three four')));
    }

    /**
     * The two forms must agree about escaping, or one of them is an XSS hole.
     */
    public function testBothFormsEscapeIdentically()
    {
        $fenom = $this->fenom(\pbFenom::AUTO_ESCAPE);
        $wrap = fn(string $text, string $tag = 'b'): string => "<$tag>$text</$tag>";
        $fenom->addModifier('wrap', $wrap);
        $fenom->addFunctionSmart('wrap', $wrap);
        $vars = array('v' => 'a&b');

        $asModifier = $fenom->compileCode('{$v|wrap}')->fetch($vars);
        $asFunction = $fenom->compileCode('{wrap text=$v}')->fetch($vars);

        $this->assertSame($asModifier, $asFunction);
        $this->assertStringNotContainsString('<b>', $asModifier);
        // and :raw still opts out
        $this->assertSame('<b>a&b</b>', $fenom->compileCode('{wrap:raw text=$v}')->fetch($vars));
    }

    public function testMissingRequiredArgumentIsReported()
    {
        $fenom = $this->fenom();
        $fenom->addFunctionSmart('needs', fn(string $a, string $b): string => $a . $b);

        $this->expectException(Error\CompileException::class);
        $this->expectExceptionMessageMatches("/requires the 'b' argument/");
        $fenom->compileCode('{needs a="x"}');
    }

    /**
     * Registering a function must invalidate the compile-cache signature, exactly as
     * registering a modifier does — otherwise two instances share one artifact.
     */
    public function testFunctionRegistrationChangesTheCacheSignature()
    {
        $base = $this->fenom()->getSignature();

        $withFunction = $this->fenom();
        $withFunction->addFunctionSmart('f', 'strtoupper');
        $this->assertNotSame($base, $withFunction->getSignature());

        $withModifier = $this->fenom();
        $withModifier->addModifier('u', 'strtoupper');
        $this->assertNotSame($base, $withModifier->getSignature());
    }

    public function testTwoInstancesWithDifferentFunctionsDoNotShareCache()
    {
        file_put_contents($this->sandbox . '/tpl/f.tpl', '{myfunc n=2}');

        $a = $this->fenom(); $a->addFunctionSmart('myfunc', fn(int $n): string => "A$n");
        $b = $this->fenom(); $b->addFunctionSmart('myfunc', fn(int $n): string => "B$n");

        $this->assertSame('A2', $a->fetch('f.tpl', array()));
        $this->assertSame('B2', $b->fetch('f.tpl', array()));
    }

    /* ------------------------------------------------ addFunction() default */

    /**
     * addFunctionSmart() maps the tag's arguments onto the callable's signature.
     */
    public function testSmartFunctionUsesTheCallableSignature()
    {
        $fenom = $this->fenom();
        $fenom->addFunctionSmart('greet', fn(string $name, string $greeting = 'Hello'): string
            => "$greeting, $name!");

        $this->assertSame('Hello, World!', $fenom->compileCode('{greet name="World"}')->fetch(array()));
        $this->assertSame('Hi, World!', $fenom->compileCode('{greet name="World" greeting="Hi"}')->fetch(array()));
        $this->assertSame('Hello, World!', $fenom->compileCode('{greet "World"}')->fetch(array()));
    }

    /**
     * addFunction() keeps its long-standing contract: the callback gets the raw
     * ($params, $tpl, $var) triple. Consumers pass user-supplied callbacks straight
     * through to it, so changing this default breaks third-party code silently.
     */
    public function testAddFunctionKeepsTheRawContract()
    {
        $fenom = $this->fenom();
        $fenom->addFunction('probe', function ($params) {
            return get_debug_type($params) . ':' . json_encode($params);
        });

        $this->assertSame(
            'array:{"a":"b"}',
            $fenom->compileCode('{probe a="b"}')->fetch(array())
        );
    }

    /**
     * The old behaviour is still available, explicitly.
     */
    public function testRawFuncParserCanBeRequestedExplicitly()
    {
        $fenom = $this->fenom();
        $fenom->addFunction('raw_probe', function ($params, $tpl) {
            return get_class($tpl) . ':' . json_encode($params);
        }, \pbFenom::RAW_FUNC_PARSER);

        $this->assertSame(
            'pbFenom\Template:{"a":"b"}',
            $fenom->compileCode('{raw_probe a="b"}')->fetch(array())
        );
    }

    /**
     * addModifier() deliberately does NOT auto-register a function: `escape` and
     * `strip` exist as both a modifier and a block tag, so doing so would overwrite
     * {strip}...{/strip} and {escape}...{/escape}.
     */
    public function testAddModifierDoesNotClobberBlockTags()
    {
        $fenom = $this->fenom();
        $fenom->addModifier('strip', 'pbFenom\Modifier::strip');

        $this->assertSame(' a b ', $fenom->compileCode('{strip true}  a   b  {/strip}')->fetch(array()));
        $this->assertSame(
            '&lt;i&gt;',
            $fenom->compileCode('{escape true}{$v}{/escape}')->fetch(array('v' => '<i>'))
        );
    }
}
