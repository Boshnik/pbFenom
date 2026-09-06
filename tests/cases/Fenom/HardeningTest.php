<?php

namespace pbFenom;

use pbFenom\Error\CompileException;

/**
 * Regression tests for the filesystem, cache and correctness fixes.
 * Every case here misbehaved on upstream 3.1.0; see docs/en/upstream-diff.md.
 */
class HardeningTest extends TestCase
{
    private string $sandbox;

    public function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/pbfenom_hardening_' . getmypid();
        Provider::clean($this->sandbox);
        @mkdir($this->sandbox . '/tpl', 0777, true);
        @mkdir($this->sandbox . '/cache', 0777, true);
    }

    public function tearDown(): void
    {
        Provider::rm($this->sandbox);
        parent::tearDown();
    }

    /* --------------------------------------------------- S-6: root containment */

    /**
     * str_starts_with($path, $root) without a trailing separator also accepts a
     * sibling directory whose name merely starts with the root's name.
     */
    public function testSiblingDirectoryWithSharedPrefixIsRejected()
    {
        @mkdir($this->sandbox . '/tpl_evil', 0777, true);
        file_put_contents($this->sandbox . '/tpl_evil/pwn.tpl', 'OUTSIDE');
        file_put_contents($this->sandbox . '/tpl/ok.tpl', 'INSIDE');

        $provider = new Provider($this->sandbox . '/tpl');
        $this->assertFalse($provider->templateExists('../tpl_evil/pwn.tpl'));
        $this->assertTrue($provider->templateExists('ok.tpl'));
    }

    public function testClassicTraversalStillRejected()
    {
        file_put_contents($this->sandbox . '/tpl/ok.tpl', 'INSIDE');
        $provider = new Provider($this->sandbox . '/tpl');
        $this->assertFalse($provider->templateExists('../../../../etc/passwd'));
        $this->assertFalse($provider->templateExists('/etc/passwd'));
    }

    /**
     * realpath() throws a ValueError on NUL, turning a 404 into a 500.
     */
    public function testNullByteInNameIsRejectedCleanly()
    {
        $provider = new Provider($this->sandbox . '/tpl');
        $this->assertFalse($provider->templateExists("ok.tpl\0.png"));
    }

    /* ----------------------------------------------- S-8: clean() and symlinks */

    /**
     * isFile() is true for a symlink to a regular file and getRealPath() resolves
     * to the target, so clean() used to delete files outside the directory.
     */
    public function testCleanDoesNotDeleteThroughSymlinks()
    {
        $victim = $this->sandbox . '/victim.txt';
        file_put_contents($victim, 'IMPORTANT');
        symlink($victim, $this->sandbox . '/cache/link.php');

        Provider::clean($this->sandbox . '/cache');

        $this->assertFileExists($victim, 'clean() must not follow symlinks out of the directory');
        $this->assertFalse(is_link($this->sandbox . '/cache/link.php'), 'the link itself should go');
    }

    /* ------------------------------------------------------- C-1 / S-9: cache names */

    /**
     * `if ($tpl > self::$filename_length)` compared a string against an int, so the
     * length guard never did what it says.
     */
    public function testLongNamesAreHashedAndShortOnesAreNot()
    {
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');
        $short = $fenom->getCompileName('page.tpl');
        $this->assertStringStartsWith('page.tpl.', $short, 'short names stay readable');

        $long = $fenom->getCompileName(str_repeat('a', 300) . '.tpl');
        $this->assertLessThan(255, strlen($long), 'long names must fit in NAME_MAX');
    }

    /**
     * crc32 is 32 bits: two different templates used to share one compiled file.
     */
    public function testCacheNamesDoNotCollideOnCrc32Pairs()
    {
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');
        // this pair has an identical crc32 and an identical length
        $this->assertNotSame(
            $fenom->getCompileName('c91741a7b570/x.tpl'),
            $fenom->getCompileName('f7d8a6ac62ac/x.tpl')
        );
    }

    /* ---------------------------------------------------- S-13: comment breakout */

    /**
     * The tag source is embedded verbatim in a /* *\/ comment, so `*\/` in a
     * perfectly ordinary template closed it early and broke the generated PHP.
     */
    public function testTagSourceContainingCommentTerminatorCompiles()
    {
        $this->exec('{$css|replace:"*/":"X"}', array('css' => 'a*/b'), 'aXb');
    }

    /* -------------------------------------------------------- assorted defects */

    /**
     * Block names were emitted unquoted: array(name) -> Undefined constant.
     */
    public function testBlockListAccessorEmitsQuotedNames()
    {
        $out = $this->fenom->compileCode('{block "alpha"}x{/block}{$.block|join:","}')->fetch(array());
        $this->assertStringContainsString('alpha', $out);
    }

    /**
     * The hand-rolled UTF-8 counter had no case for 4-byte sequences.
     */
    public function testLengthCountsAstralCharacters()
    {
        $this->assertSame(1, Modifier::length('😀'));
        $this->assertSame(6, Modifier::length('привет'));
        $this->assertSame(3, Modifier::length('abc'));
    }

    /**
     * count() rejects a Generator even though the {foreach} guard accepts Traversable.
     */
    public function testForeachLastWorksOnGenerators()
    {
        $gen = (function () { yield 'a'; yield 'b'; yield 'c'; })();
        $this->exec(
            '{foreach $g as $x last=$l}{$x}{if $l}|END{/if}{/foreach}',
            array('g' => $gen),
            'abc|END'
        );
    }

    /**
     * A typed property with no default fatals on first read.
     */
    public function testExtendedPropertyIsInitialised()
    {
        $this->assertNull($this->fenom->getRawTemplate()->extended);
    }

    /* -------------------------------------------------------- S-12: recursion */

    /**
     * A cyclic {extends} used to exhaust 0.5 GB, and the dynamic form span forever
     * at full CPU below every PHP limit.
     */
    public function testCyclicExtendsIsAborted()
    {
        file_put_contents($this->sandbox . '/tpl/a.tpl', '{extends "b.tpl"}{block "x"}A{/block}');
        file_put_contents($this->sandbox . '/tpl/b.tpl', '{extends "a.tpl"}{block "x"}B{/block}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/too deep/');
        $fenom->compile('a.tpl', false);
    }

    /* ------------------------------------------------------- S-14: AUTO_STRIP */

    /**
     * preg_replace('/\s+/uS') returns null on malformed UTF-8, and the null used to
     * flow straight into the body, blanking the whole template.
     */
    public function testStripDoesNotBlankNonUtf8Templates()
    {
        file_put_contents($this->sandbox . '/tpl/latin.tpl', "HELLO \xC3( WORLD");
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            array('strip' => true, 'force_compile' => true)
        );
        $out = $fenom->fetch('latin.tpl', array());
        $this->assertNotSame('', $out, 'a non-UTF-8 template must not render as nothing');
        $this->assertStringContainsString('HELLO', $out);
        $this->assertStringContainsString('WORLD', $out);
    }

    /* ------------------------------------------------- previously untested tags */

    /**
     * {for} was registered in $_actions but its compiler methods were deleted by the
     * 3.0 PHP-8 migration, so the documented tag fatalled from 3.0.0 onwards.
     */
    public function testForTagExists()
    {
        $this->assertTrue(method_exists(Compiler::class, 'forOpen'));
        $this->exec('{for $i=0 to=2}{$i},{/for}', array(), '0,1,2,');
    }

    /**
     * tagPaste() chopped a character off each end of already-compiled PHP, so
     * {use}+{paste} emitted a syntax error and cached it.
     */
    public function testUseAndPasteProduceValidPhp()
    {
        file_put_contents($this->sandbox . '/tpl/donor.tpl', '{block "d"}DONOR:{$x}{/block}');
        file_put_contents($this->sandbox . '/tpl/user.tpl', '{use "donor.tpl"}{paste "d"}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');
        $this->assertSame('DONOR:42', $fenom->fetch('user.tpl', array('x' => 42)));
    }

    /**
     * {$.fetch("x",)} left $vars undefined and emitted `fetch("x", )`.
     */
    public function testFetchAccessorRejectsAnEmptyArgument()
    {
        $this->expectException(\pbFenom\Error\CompileException::class);
        $this->fenom->compileCode('{$.fetch("leaf.tpl",)}');
    }

    public function testRangeIterator()
    {
        $this->exec('{foreach 1..4 as $i}{$i},{/foreach}', array(), '1,2,3,4,');
        $this->assertCount(4, iterator_to_array(new RangeIterator(1, 4)));
        $this->assertCount(4, iterator_to_array((new RangeIterator(1, 4))->setStep(-1)));
        // Known upstream limitation, documented rather than changed: the constructor
        // does not normalise its bounds, so a descending literal range yields nothing
        // (valid() requires current >= min). Use setStep(-1) explicitly.
        $this->exec('{foreach 4..1 as $i}{$i},{/foreach}', array(), '');
    }

    public function testRawTag()
    {
        $this->exec('{raw $v}|{$v}', array('v' => '<i>'), '<i>|&lt;i&gt;', \pbFenom::AUTO_ESCAPE);
    }

    /* ------------------------------------------ S-12: recursion depth, part 2 */

    /**
     * A cyclic {include} recursed until PHP exhausted the call stack — a *fatal*
     * error, so uncatchable, with no context and a blank 500.
     */
    public function testCyclicIncludeIsAborted()
    {
        file_put_contents($this->sandbox . '/tpl/a.tpl', '{include "b.tpl"}');
        file_put_contents($this->sandbox . '/tpl/b.tpl', '{include "a.tpl"}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->expectException(\pbFenom\Error\TemplateException::class);
        $this->expectExceptionMessageMatches('/too deep/');
        $fenom->fetch('a.tpl', array());
    }

    public function testSelfIncludeIsAborted()
    {
        file_put_contents($this->sandbox . '/tpl/s.tpl', '{include "s.tpl"}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->expectException(\pbFenom\Error\TemplateException::class);
        $this->expectExceptionMessageMatches('/too deep/');
        $fenom->fetch('s.tpl', array());
    }

    /**
     * {insert} inlines at compile time, so it exhausted memory rather than the stack.
     */
    public function testCyclicInsertIsAborted()
    {
        file_put_contents($this->sandbox . '/tpl/c.tpl', '{insert "d.tpl"}');
        file_put_contents($this->sandbox . '/tpl/d.tpl', '{insert "c.tpl"}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/too deep/');
        $fenom->compile('c.tpl', false);
    }

    /**
     * pbFenom::MAX_MACRO_RECURSIVE has been declared and unused since 2013; a runaway
     * macro relied on PHP's stack guard, which depends on zend.max_allowed_stack_size
     * and names neither the macro nor the template.
     */
    public function testRunawayMacroIsAborted()
    {
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->expectException(\pbFenom\Error\TemplateException::class);
        $this->expectExceptionMessageMatches("/Macro 'r' recursed deeper than 32/");
        $fenom->compileCode('{macro r(n)}{if $n > 0}{macro.r n=$n}{/if}{/macro}{macro.r n=1}')
              ->fetch(array());
    }

    /**
     * Legitimate nesting below the limit must be untouched, and neither a normal
     * render nor a failing one may leak depth into the next one.
     */
    public function testDepthCounterDoesNotLeak()
    {
        for ($i = 0; $i < 20; $i++) {
            $next = $i < 19 ? '{include "n' . ($i + 1) . '.tpl"}' : 'END';
            file_put_contents($this->sandbox . "/tpl/n$i.tpl", $next);
        }
        file_put_contents($this->sandbox . '/tpl/boom.tpl', '{$obj->nope()}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        $this->assertSame('END', $fenom->fetch('n0.tpl', array()));
        for ($i = 0; $i < 50; $i++) {
            $fenom->fetch('n0.tpl', array());
        }
        for ($i = 0; $i < 50; $i++) {
            try { $fenom->fetch('boom.tpl', array('obj' => new \stdClass)); } catch (\Throwable $e) { /* expected */ }
        }
        $this->assertSame('END', $fenom->fetch('n0.tpl', array()), 'depth must not accumulate');
    }

    /**
     * The old handler re-wrapped at every {include} level, producing
     * "unhandled exception in `a`: unhandled exception in `b`: ...".
     */
    public function testNestedRenderErrorIsNotWrappedRepeatedly()
    {
        file_put_contents($this->sandbox . '/tpl/outer.tpl', '{include "inner.tpl"}');
        file_put_contents($this->sandbox . '/tpl/inner.tpl', '{$obj->nope()}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');

        try {
            $fenom->fetch('outer.tpl', array('obj' => new \stdClass));
            $this->fail('expected a TemplateException');
        } catch (\pbFenom\Error\TemplateException $e) {
            $this->assertSame(1, substr_count($e->getMessage(), 'unhandled exception'));
            $this->assertStringContainsString('inner.tpl', $e->getMessage());
        }
    }

    /* ------------------------------------------------------- strict_types */

    public function testEverySourceFileDeclaresStrictTypes()
    {
        $missing = array();
        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src')) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                if (!str_contains(file_get_contents($file->getPathname()), 'declare(strict_types=1)')) {
                    $missing[] = $file->getFilename();
                }
            }
        }
        $this->assertSame(array(), $missing, 'these files lost their strict_types declaration');
    }

    /**
     * strict_types is per-file: the compiled artifact deliberately does *not* declare
     * it, so template data keeps being coerced instead of fatalling on the first
     * integer handed to a string-typed modifier.
     */
    public function testTemplateDataIsStillCoercedInGeneratedCode()
    {
        $this->assertSame('42', $this->fenom->compileCode('{$x|upper}')->fetch(array('x' => 42)));
        $this->assertSame('123...', $this->fenom->compileCode('{$x|truncate:3}')->fetch(array('x' => 1234567)));
    }

    /**
     * strstr() returns false, not null, when there is no schema separator; under
     * strict_types that was a TypeError on every single template load.
     */
    public function testTemplateWithoutSchemaLoads()
    {
        file_put_contents($this->sandbox . '/tpl/plain.tpl', 'ok');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache');
        $this->assertSame('ok', $fenom->fetch('plain.tpl', array()));
    }

    /**
     * is_numeric() accepts a numeric string, but date() wants an int — both date
     * modifiers passed the string straight through.
     */
    public function testDateModifiersAcceptNumericStrings()
    {
        $this->assertSame('2012', Modifier::date('1343323616', 'Y'));
        $this->assertSame('2012', Modifier::date(1343323616, 'Y'));
        $this->assertSame('2012', Modifier::dateFormat('1343323616', 'Y'));
        $this->assertSame('2012', Modifier::dateFormat(1343323616, 'Y'));
    }

    /**
     * The artifact wrote 'provider' and 'options' while the constructor read 'scm'
     * and ignored 'options', so getScm() was always "" and getOptions() always 0.
     */
    public function testScmAndOptionsSurviveTheCompileCache()
    {
        file_put_contents($this->sandbox . '/tpl/s.tpl', '{$x}');
        $fenom = \pbFenom::factory($this->sandbox . '/tpl', $this->sandbox . '/cache', \pbFenom::AUTO_ESCAPE);
        $fenom->addProvider('db', new Provider($this->sandbox . '/tpl'));
        $fenom->fetch('db:s.tpl', array('x' => 1));

        $tpl = $fenom->getTemplate('db:s.tpl');
        $this->assertInstanceOf(Render::class, $tpl, 'must come back from the cache file');
        $this->assertSame('db', $tpl->getScm());
        $this->assertSame(\pbFenom::AUTO_ESCAPE, $tpl->getOptions() & \pbFenom::AUTO_ESCAPE);
    }

    /* ------------------------------------------------------------- no eval() */

    /**
     * Detected with the tokenizer rather than a text search: the source legitimately
     * *mentions* eval() in comments, and a grep-based test would fail on those.
     */
    public function testNoSourceFileCallsEval()
    {
        $offenders = array();
        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src')) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_EVAL) {
                    $offenders[] = $file->getFilename() . ':' . $token[2];
                }
            }
        }
        $this->assertSame(array(), $offenders, 'templates are include()d, never eval()d');
    }

    /**
     * The two modes that have no file on disk still have to work.
     */
    public function testRenderingWithoutACompiledFile()
    {
        file_put_contents($this->sandbox . '/tpl/t.tpl', 'hi {$x}{foreach $r as $i}{$i}{/foreach}');
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            \pbFenom::DISABLE_CACHE
        );
        $this->assertSame('hi a12', $fenom->fetch('t.tpl', array('x' => 'a', 'r' => array(1, 2))));
        $this->assertCount(0, glob($this->sandbox . '/cache/*.php'), 'DISABLE_CACHE must write nothing');

        $this->assertSame('b', $this->fenom->compileCode('{$y}')->fetch(array('y' => 'b')));
    }

    /**
     * Recursive macros live in the same artifact, so they must survive the stream load.
     */
    public function testMacrosSurviveTheEvalFreePath()
    {
        $tpl = '{macro tree(items)}{foreach $items as $i}[{$i.n}{if $i.c}{macro.tree items=$i.c}{/if}]{/foreach}{/macro}'
             . '{macro.tree items=$rows}';
        $vars = array('rows' => array(array('n' => 'a', 'c' => array(array('n' => 'b', 'c' => null)))));
        $this->assertSame('[a[b]]', $this->fenom->compileCode($tpl)->fetch($vars));
    }

    /**
     * eval()'d code reported errors as "eval()'d code on line N", naming neither the
     * template nor anything actionable. The stream URL carries the name.
     */
    public function testRuntimeErrorNamesTheTemplate()
    {
        file_put_contents($this->sandbox . '/tpl/broken.tpl', '{$obj->missing()}');
        $fenom = \pbFenom::factory(
            $this->sandbox . '/tpl',
            $this->sandbox . '/cache',
            \pbFenom::DISABLE_CACHE
        );
        try {
            $fenom->fetch('broken.tpl', array('obj' => new \stdClass));
            $this->fail('expected a TemplateException');
        } catch (Error\TemplateException $e) {
            $this->assertStringContainsString('broken.tpl', $e->getMessage());
            $this->assertStringContainsString('broken.tpl', $e->getPrevious()->getFile());
            $this->assertStringNotContainsString("eval()'d", $e->getPrevious()->getFile());
        }
    }
}
