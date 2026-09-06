<?php

namespace pbFenom;

use pbFenom\Error\SecurityException;

/**
 * Regression tests for the security fixes.
 *
 * Every case here failed on upstream 3.1.0; see docs/en/upstream-diff.md.
 */
class SecurityFixesTest extends TestCase
{
    /* ----------------------------------------------------------- S-2: DENY_ACCESSOR */

    public static function providerAccessorsUnderDenyAccessor(): array
    {
        return array(
            array('{$.server.HTTP_HOST}', 'Accessor $.server is disabled'),
            array('{$.const.PHP_VERSION}', 'Accessor $.const is disabled'),
            array('{$.get.one}', 'Accessor $.get is disabled'),
            array('{$.php.phpversion()}', 'Accessor $.php is disabled'),
            array('{$.version}', 'Accessor $.version is disabled'),
        );
    }

    /**
     * DENY_ACCESSOR was declared and mapped in setOptions() but never checked,
     * so `disable_accessor` enforced nothing at all.
     * @dataProvider providerAccessorsUnderDenyAccessor
     */
    public function testDenyAccessorActuallyDenies(string $code, string $message)
    {
        $this->execError($code, SecurityException::class, $message, \pbFenom::DENY_ACCESSOR);
    }

    public function testAccessorsStillWorkByDefault()
    {
        $_SERVER['fenom_probe'] = 'ok';
        $this->exec('{$.server.fenom_probe}', array(), 'ok');
    }

    /* --------------------------------------------- S-1: $.php / $.call bypassed policy */

    public static function providerPhpCalls(): array
    {
        return array(
            array('{$.php.phpversion()}'),
            array('{$.call.phpversion()}'),
            array('{$.php.count([1,2])}'),
        );
    }

    /**
     * Accessor::call() reached call_user_func_array() without consulting any option,
     * so {$.php.foo()} ran while the equivalent {foo()} was blocked.
     * @dataProvider providerPhpCalls
     */
    public function testDenyPhpCallsBlocksAccessorCalls(string $code)
    {
        $this->execError($code, SecurityException::class, 'Callback ', \pbFenom::DENY_PHP_CALLS);
    }

    /**
     * DENY_NATIVE_FUNCS must apply to $.php exactly as it applies to a plain call.
     */
    public function testDenyNativeFuncsAppliesToAccessorCalls()
    {
        $this->execError(
            '{$.php.phpversion()}',
            SecurityException::class,
            'Callback phpversion is not allowed',
            \pbFenom::DENY_NATIVE_FUNCS
        );
    }

    /**
     * ...but a whitelisted function must still go through, otherwise the option
     * would be a blanket ban rather than a whitelist.
     */
    public function testWhitelistedFunctionStillAllowedViaAccessor()
    {
        $this->exec('{$.php.count([1,2,3])}', array(), '3', \pbFenom::DENY_NATIVE_FUNCS);
    }

    /**
     * Call filters were combined with AND, so registering two of them made every
     * callback unreachable. Any single matching filter must allow the call.
     */
    public function testCallFiltersAreCombinedWithOr()
    {
        $this->fenom->addCallFilter('Reflection\*');
        $this->fenom->addCallFilter('pbFenom\*');
        $this->exec('{$.call.pbFenom.helper_func("string", 12)}', array(), 'string......');
    }

    /* ------------------------------------------------------ S-5: {strip} broke escaping */

    /**
     * Tag::setOption() hardcoded AUTO_ESCAPE, so {strip} did not strip and instead
     * flipped escaping — permanently, because restore() restores the recorded option.
     */
    public function testStripActuallyStrips()
    {
        $this->exec('{strip true}  a   b  {/strip}', array(), ' a b ');
    }

    public function testStripDoesNotLeakEscapingChange()
    {
        // auto_escape is off: the value must stay raw both inside and after the block
        $this->exec(
            '{strip true}in:{$v}{/strip}|out:{$v}',
            array('v' => '<i>'),
            'in:<i>|out:<i>'
        );
    }

    public function testStripDoesNotDisableEscapingWhenAutoEscapeIsOn()
    {
        $this->exec(
            '{strip true}in:{$v}{/strip}|out:{$v}',
            array('v' => '<i>'),
            'in:&lt;i&gt;|out:&lt;i&gt;',
            \pbFenom::AUTO_ESCAPE
        );
    }

    /* ------------------------------------------------------------- S-3/S-4: escaping */

    /**
     * ENT_COMPAT leaves `'` alone, which is injectable in single-quoted attributes.
     */
    public function testAutoEscapeEscapesSingleQuotes()
    {
        $this->exec(
            "<a href='{\$v}'>",
            array('v' => "' onmouseover=alert(1) x='"),
            "<a href='&apos; onmouseover=alert(1) x=&apos;'>",
            \pbFenom::AUTO_ESCAPE
        );
    }

    /**
     * Without ENT_SUBSTITUTE, malformed UTF-8 collapses the whole value to "".
     */
    public function testAutoEscapeSubstitutesMalformedUtf8()
    {
        $out = $this->fenom->compileCode('{$v}')->fetch(array('v' => "a\xC3(b"));
        $this->assertNotSame('', $out, 'malformed UTF-8 must not blank the value');
        $this->assertStringContainsString('a', $out);
        $this->assertStringContainsString('b', $out);
    }

    /**
     * JSON_UNESCAPED_SLASHES let </script> close the element it was embedded in.
     */
    public function testJsEscapeCannotBreakOutOfScriptElement()
    {
        $out = Modifier::escape('</script><img src=x>', 'js');
        $this->assertStringNotContainsString('</script', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertSame('</script><img src=x>', json_decode($out), 'must still round-trip');
    }

    /**
     * `default: return $text` meant {$x|escape:'attr'} produced zero escaping silently.
     */
    public function testUnknownEscapeStrategyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        Modifier::escape('<b>', 'no-such-strategy');
    }

    public function testUnknownUnescapeStrategyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        Modifier::unescape('<b>', 'no-such-strategy');
    }

    public function testUrlEscapeUsesRfc3986()
    {
        $this->assertSame('a%20b', Modifier::escape('a b', 'url'));
        $this->assertSame('a+b', Modifier::escape('a b', 'query'));
    }

    public function testHtmlEscapeRoundTrip()
    {
        $raw = "O'Brien \"x\" & <b>";
        $this->assertSame($raw, Modifier::unescape(Modifier::escape($raw, 'html'), 'html'));
    }
}
