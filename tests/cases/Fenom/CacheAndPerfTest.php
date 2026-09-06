<?php

namespace pbFenom;

/**
 * Regression tests for the cache-key signature and the codegen optimisations.
 */
class CacheAndPerfTest extends TestCase
{
    private string $sandbox;

    public function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/pbfenom_cacheperf_' . getmypid();
        Provider::rm($this->sandbox);
        @mkdir($this->sandbox . '/tpl', 0777, true);
        @mkdir($this->sandbox . '/cache', 0777, true);
    }

    public function tearDown(): void
    {
        Provider::rm($this->sandbox);
        parent::tearDown();
    }

    private function fenom(): \pbFenom
    {
        return \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');
    }

    /* ------------------------------------------------- cache-key signature */

    /**
     * The cache key was name + option mask only, so two instances sharing a compile
     * directory but registering different modifiers served each other's artifacts.
     */
    public function testInstancesWithDifferentModifiersDoNotShareCache()
    {
        file_put_contents($this->sandbox . '/tpl/m.tpl', '[{$x|mymod}]');

        $a = $this->fenom(); $a->addModifier('mymod', 'strtoupper');
        $b = $this->fenom(); $b->addModifier('mymod', 'strrev');

        $this->assertSame('[ABC]', $a->fetch('m.tpl', array('x' => 'abc')));
        $this->assertSame('[cba]', $b->fetch('m.tpl', array('x' => 'abc')));
        $this->assertCount(2, glob($this->sandbox . '/cache/*.php'));
    }

    public function testSignatureChangesWithRegistryAndCharset()
    {
        $a = $this->fenom();
        $base = $a->getSignature();

        $a->addModifier('extra', 'strrev');
        $this->assertNotSame($base, $a->getSignature(), 'a new modifier must change the signature');

        $b = $this->fenom();
        $this->assertSame($base, $b->getSignature(), 'an identical configuration must match');

        $charset = \pbFenom::$charset;
        try {
            \pbFenom::$charset = 'ISO-8859-1';
            $this->assertNotSame($base, $this->fenom()->getSignature(), 'charset is baked into the artifact');
        } finally {
            \pbFenom::$charset = $charset;
        }
    }

    /**
     * Closures compile to a runtime call_user_func(), so they are not baked into the
     * artifact and must not destabilise the key across processes.
     */
    public function testClosureModifiersDoNotDestabiliseTheSignature()
    {
        $a = $this->fenom(); $a->addModifier('cl', fn($v) => $v);
        $b = $this->fenom(); $b->addModifier('cl', fn($v) => strrev($v));
        $this->assertSame($a->getSignature(), $b->getSignature());
    }

    /* ----------------------------------------------------- debug comments */

    public function testDebugCommentsAreOffByDefault()
    {
        $body = $this->fenom()->compileCode('a{$x}b')->getBody();
        $this->assertStringNotContainsString('/*', $body);
    }

    public function testDebugCommentsCanBeEnabled()
    {
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            array('debug_comments' => true)
        );
        $body = $fenom->compileCode('a{$x}b')->getBody();
        $this->assertStringContainsString('/*', $body);
        $this->assertStringContainsString('{$x}', $body);
    }

    /**
     * The comment must still survive a tag source containing the comment terminator.
     */
    public function testDebugCommentsEscapeCommentTerminator()
    {
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            array('debug_comments' => true, 'force_compile' => true)
        );
        $this->assertSame('aXb', $fenom->compileCode('{$css|replace:"*/":"X"}')->fetch(array('css' => 'a*/b')));
    }

    /* -------------------------------------------------- include memoisation */

    /**
     * {include} with a static name used to re-enter getTemplate() on every iteration.
     */
    public function testStaticIncludeIsResolvedOncePerRender()
    {
        file_put_contents($this->sandbox . '/tpl/leaf.tpl', '<i>{$row}</i>');
        file_put_contents($this->sandbox . '/tpl/loop.tpl',
            '{foreach $rows as $row}{include "leaf.tpl"}{/foreach}');

        $fenom = $this->fenom();
        $body  = $fenom->compile('loop.tpl', false)->getBody();
        $this->assertMatchesRegularExpression('/\?\?=.*getTemplate/', $body, 'the lookup must be memoised');

        $this->assertSame('<i>a</i><i>b</i>', $fenom->fetch('loop.tpl', array('rows' => array('a', 'b'))));
    }

    /**
     * A dynamic name resolves to a different template per iteration and must not be
     * memoised.
     */
    public function testDynamicIncludeIsNotMemoised()
    {
        file_put_contents($this->sandbox . '/tpl/one.tpl', '1');
        file_put_contents($this->sandbox . '/tpl/two.tpl', '2');
        file_put_contents($this->sandbox . '/tpl/dyn.tpl',
            '{foreach $names as $n}{include $n}{/foreach}');

        $fenom = $this->fenom();
        $body  = $fenom->compile('dyn.tpl', false)->getBody();
        $this->assertStringNotContainsString('??=', $body);
        $this->assertSame('12', $fenom->fetch('dyn.tpl', array('names' => array('one.tpl', 'two.tpl'))));
    }

    /* ------------------------------------------------------ AUTO_RELOAD path */

    public function testAutoReloadPicksUpAChangedTemplate()
    {
        $tpl = $this->sandbox . '/tpl/live.tpl';
        file_put_contents($tpl, 'v1:{$x}');
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            \pbFenom::AUTO_RELOAD
        );
        $this->assertSame('v1:a', $fenom->fetch('live.tpl', array('x' => 'a')));

        file_put_contents($tpl, 'v2:{$x}');
        touch($tpl, time() + 2);
        clearstatcache(true, $tpl);
        $fenom->flush();

        $this->assertSame('v2:a', $fenom->fetch('live.tpl', array('x' => 'a')));
    }

    /**
     * isValid() now always goes through verify(), which compares each dependency
     * against its own recorded mtime — including the parent of an {extends} chain.
     */
    public function testAutoReloadTracksExtendsDependencies()
    {
        file_put_contents($this->sandbox . '/tpl/b.tpl', '{block "x"}base{/block}');
        file_put_contents($this->sandbox . '/tpl/c.tpl', '{extends "b.tpl"}');
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            \pbFenom::AUTO_RELOAD
        );
        $this->assertSame('base', $fenom->fetch('c.tpl', array()));

        file_put_contents($this->sandbox . '/tpl/b.tpl', '{block "x"}changed{/block}');
        touch($this->sandbox . '/tpl/b.tpl', time() + 2);
        clearstatcache();
        $fenom->flush();

        $this->assertSame('changed', $fenom->fetch('c.tpl', array()));
    }

    /* ------------------------------------------- {foreach} variable aliasing */

    /**
     * The loop value is bound to a local *reference*, so the body reads one hash
     * level instead of two while $var[...] stays in sync for everything that
     * observes the context.
     */
    public function testForeachBindsLoopVariableToALocal()
    {
        $body = $this->fenom()->compileCode('{foreach $rows as $row}{$row.n}{/foreach}')->getBody();
        $this->assertMatchesRegularExpression('/\$t\w+ = &\$var\["row"\]/', $body);
        $this->assertStringNotContainsString('$var["row"]["n"]', $body, 'the body should use the local');
    }

    public static function providerAliasSafety(): array
    {
        return array(
            'include still sees $var' => array(
                '{foreach $rows as $row}<i>{include "leaf.tpl"}</i>{/foreach}', '<i>a</i><i>b</i>'),
            'nested loop, same name' => array(
                '{foreach $rows as $row}[{foreach $rows as $row}{$row.n}{/foreach}]{/foreach}', '[ab][ab]'),
            'variable survives the loop' => array(
                '{foreach $rows as $row}{$row.n}{/foreach}|{$row.n}', 'ab|b'),
            'assignment writes through' => array(
                '{foreach $rows as $row}{set $row.n = "X"}{$row.n}{/foreach}', 'XX'),
            'used as an array key' => array(
                '{foreach $rows as $row}{$m[$row.n]}{/foreach}', '12'),
            'string interpolation' => array(
                '{foreach $rows as $row}{"v={$row.n}"}{/foreach}', 'v=av=b'),
            'foreachelse' => array(
                '{foreach $e as $row}{$row.n}{foreachelse}none{/foreach}', 'none'),
            'key and value' => array(
                '{foreach $rows as $k => $row}{$k}{$row.n}{/foreach}', '0a1b'),
            'macro call inside the loop' => array(
                '{macro m(v)}<{$v}>{/macro}{foreach $rows as $row}{macro.m v=$row.n}{/foreach}', '<a><b>'),
            'last= property' => array(
                '{foreach $rows as $row last=$l}{$row.n}{if $l}!{/if}{/foreach}', 'ab!'),
        );
    }

    /**
     * @dataProvider providerAliasSafety
     */
    public function testAliasingKeepsSemantics(string $tpl, string $expected)
    {
        file_put_contents($this->sandbox . '/tpl/leaf.tpl', '{$row.n}');
        $fenom = $this->fenom();
        $vars  = array(
            'rows' => array(array('n' => 'a'), array('n' => 'b')),
            'e'    => array(),
            'm'    => array('a' => 1, 'b' => 2),
        );
        $this->assertSame($expected, $fenom->compileCode($tpl)->fetch($vars));
    }

    /**
     * A recursive macro body becomes its own closure, where an enclosing loop's
     * local does not exist — aliases must be suspended while compiling it.
     */
    public function testRecursiveMacroDefinedInsideALoop()
    {
        $tpl = '{foreach $rows as $row}'
             . '{macro tree(items)}{foreach $items as $i}[{$i.n}{if $i.c}{macro.tree items=$i.c}{/if}]{/foreach}{/macro}'
             . '{macro.tree items=$row.c}{/foreach}';
        $vars = array('rows' => array(array('c' => array(array('n' => 'a', 'c' => array(array('n' => 'b', 'c' => null)))))));
        $this->assertSame('[a[b]]', $this->fenom()->compileCode($tpl)->fetch($vars));
    }
}
