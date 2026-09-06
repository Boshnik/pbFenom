<?php

namespace pbFenom;


class AccessorTest  extends TestCase
{
    public static function providerGetVar()
    {
        return array(
            array("get"),
            array("post"),
            array("cookie"),
            array("request"),
            array("files"),
            array("globals"),
            array("server"),
            array("session"),
            array("env"),
        );
    }

    /**
     * @dataProvider providerGetVar
     * @backupGlobals
     * @param string $var
     */
    public function testGetVar($var)
    {
        $_GET['one']     = 'get1';
        $_POST['one']    = 'post1';
        $_COOKIE['one']  = 'cookie1';
        $_REQUEST['one'] = 'request1';
        $_FILES['one']   = 'files1';
        $GLOBALS['one']  = 'globals1';
        $_SERVER['one']  = 'server1';
        $_SESSION['one'] = 'session1';
        $_ENV['one']     = 'env1';
        $this->exec('{$.'.$var.'.one}', self::getVars(), "{$var}1");
        $this->exec('{$.'.$var.'.undefined}', self::getVars(), "");
    }

    public static function providerTpl()
    {
        return array(
            array("name"),
            array("scm"),
            array("basename"),
            array("options"),
            array("time"),
        );
    }

    /**
     * @dataProvider providerTpl
     * @param string $name
     */
    public function testTpl($name)
    {
        $this->tpl("accessor.tpl", '{$.tpl.'.$name.'}');
        $tpl = $this->fenom->setOptions(\pbFenom::FORCE_VERIFY)->getTemplate('accessor.tpl');
        $this->assertSame(strval($tpl->{"get$name"}()), $tpl->fetch(self::getVars()));
    }

    public function testVersion()
    {
        $this->assertRender('{$.version}', \pbFenom::VERSION);
    }

    public static function providerConst()
    {
        return array(
            array("$.const.PHP_VERSION_ID", PHP_VERSION_ID),
            array('$.const.UNDEFINED', ''),
            array("$.const.FENOM_RESOURCES", FENOM_RESOURCES),
            array("$.const.pbFenom.HELPER_CONSTANT", HELPER_CONSTANT),
            array("$.const.pbFenom.UNDEFINED", ''),
            array("$.const.pbFenom::VERSION", \pbFenom::VERSION),
            array("$.const.pbFenom::UNDEFINED", ''),
            array("$.const.pbFenom.Helper::CONSTANT", Helper::CONSTANT),
            array("$.const.pbFenom.Helper::UNDEFINED", ''),
        );
    }

    /**
     * @dataProvider providerConst
     * @param $tpl
     * @param $value
     * @group const
     */
    public function testConst($tpl, $value)
    {
        $this->assertRender('{'.$tpl.'}', strval($value));
    }


    public static function providerCall() {
        return array(
            array('$.call.strrev("string")', strrev("string")),
            array('$.call.strrev("string")', strrev("string"), 'str*'),
            array('$.call.strrev("string")', strrev("string"), 'strrev'),
            array('$.call.get_current_user', get_current_user()),
            array('$.call.pbFenom.helper_func("string", 12)', helper_func("string", 12)),
            array('$.call.pbFenom.helper_func("string", 12)', helper_func("string", 12), 'pbFenom\\*'),
            array('$.call.pbFenom.helper_func("string", 12)', helper_func("string", 12), 'pbFenom\helper_func'),
            array('$.call.pbFenom.helper_func("string", 12)', helper_func("string", 12), '*helper_func'),
            array('$.call.pbFenom.helper_func("string", 12)', helper_func("string", 12), '*'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string")),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\*'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\TestCase*'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\TestCase::*'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\*::dots'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\*::*'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), 'pbFenom\TestCase::dots'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), '*::dots'),
            array('$.call.pbFenom.TestCase::dots("string")', TestCase::dots("string"), '*'),
        );
    }

    /**
     * @dataProvider providerCall
     * @group php
     */
    public function testCall($tpl, $result, $mask = null) {
        if($mask) {
            $this->fenom->addCallFilter($mask);
        }
        $this->assertRender('{'.$tpl.'}', $result);
    }

    /**
     * @group issue260
     */
    public function testBug260() {
        $t = $this->fenom->compileCode('{$.php.pbFenom::factory()->addModifier("intval", "intval")}');
        $this->assertInstanceOf(Template::class, $t);
    }


    public static function providerPHPInvalid() {
        return array(
            array('$.call.aaa("string")', 'pbFenom\Error\CompileException', 'PHP method aaa does not exists'),
            array('$.call.strrev("string")', 'pbFenom\Error\SecurityException', 'Callback strrev is not available by settings', 'strrevZ'),
            array('$.call.strrev("string")', 'pbFenom\Error\SecurityException', 'Callback strrev is not available by settings', 'str*Z'),
            array('$.call.strrev("string")', 'pbFenom\Error\SecurityException', 'Callback strrev is not available by settings', '*Z'),
            array('$.call.pbFenom.aaa("string")', 'pbFenom\Error\CompileException', 'PHP method pbFenom.aaa does not exists'),
            array('$.call.pbFenom.helper_func("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.helper_func is not available by settings', 'Reflection\*'),
            array('$.call.pbFenom.helper_func("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.helper_func is not available by settings', 'pbFenom\*Z'),
            array('$.call.pbFenom.helper_func("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.helper_func is not available by settings', 'pbFenom\*::*'),
            array('$.call.TestCase::aaa("string")', 'pbFenom\Error\CompileException', 'PHP method TestCase::aaa does not exists'),
            array('$.call.pbFenom.TestCase::aaa("string")', 'pbFenom\Error\CompileException', 'PHP method pbFenom.TestCase::aaa does not exists'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', 'Reflection\*'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', 'pbFenom\*Z'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', 'pbFenom\*::get*'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', 'pbFenom\TestCase::get*'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', 'pbFenom\TestCase::*Z'),
            array('$.call.pbFenom.TestCase::dots("string")', 'pbFenom\Error\SecurityException', 'Callback pbFenom.TestCase::dots is not available by settings', '*::*Z'),
        );
    }

    /**
     * @dataProvider providerPHPInvalid
     * @group php
     */
    public function testPHPInvalid($tpl, $exception, $message, $methods = null) {
        if($methods) {
            $this->fenom->addCallFilter($methods);
        }
        $this->execError('{'.$tpl.'}', $exception, $message);
    }


    public static function providerAccessor()
    {
        return array(
            array('{$.get.one}', 'get1'),
            array('{$.post.one}', 'post1'),
            array('{$.request.one}', 'request1'),
            array('{$.session.one}', 'session1'),
            array('{$.files.one}', 'files1'),
            array('{$.globals.one}', 'globals1'),
            array('{$.cookie.one}', 'cookie1'),
            array('{$.server.one}', 'server1'),
            array('{"string"|append:"_":$.get.one}', 'string_get1'),
            array('{$.get.one?}', '1'),
            array('{$.get.one is set}', '1'),
            array('{$.get.two is empty}', '1'),
            array('{$.version}', \pbFenom::VERSION),
            array('{$.tpl.name}', 'runtime.tpl'),
            array('{$.tpl.time}', '0'),
            array('{$.tpl.schema}', ''),
        );
    }

    public static function providerAccessorInvalid()
    {
        return array(
            array('{$.nope.one}', 'pbFenom\Error\CompileException', "Unexpected token 'nope'"),
            array('{$.get.one}', 'pbFenom\Error\SecurityException', 'Accessor are disabled', \pbFenom::DENY_ACCESSOR),
        );
    }

    public static function providerFetch()
    {
        return array(
            array('{$.fetch("welcome.tpl")}'),
            array('{set $tpl = "welcome.tpl"}{$.fetch($tpl)}'),
            array('{$.fetch("welcome.tpl", ["username" => "Bzick", "email" => "bzick@dev.null"])}'),
            array('{set $tpl = "welcome.tpl"}{$.fetch($tpl, ["username" => "Bzick", "email" => "bzick@dev.null"])}'),
        );
    }

    /**
     * @group fetch
     * @dataProvider providerFetch
     */
    public function testFetch($code)
    {
        $this->tpl('welcome.tpl', '<b>Welcome, {$username} ({$email})</b>');
        $values = array('username' => 'Bzick', 'email' => 'bzick@dev.null');
        $this->assertRender($code, $this->fenom->fetch('welcome.tpl', $values), $values);
    }

    public static function providerFetchInvalid()
    {
        return array(
            array('{$.fetch("welcome_.tpl")}', 'pbFenom\Error\CompileException', "Template welcome_.tpl not found"),
            array('{$.fetch("welcome_.tpl", [])}', 'pbFenom\Error\CompileException', "Template welcome_.tpl not found"),
        );
    }

    /**
     * @group fetchInvalid
     * @dataProvider providerFetchInvalid
     */
    public function testFetchInvalidTpl($tpl, $exception, $message) {
        $this->execError($tpl, $exception, $message);
    }

    public static function getThree(): int
    {
        return 3;
    }

    public static function getThreeArray(): array
    {
        return ["three" => 3];
    }

    public static function getThreeCb(): callable
    {
        return fn() => 3;
    }

    public static int $three = 3;

    public static function providerSmartAccessor() {
        return array(
            array('acc', '\pbFenom\AccessorTest::getThreeArray()', \pbFenom::ACCESSOR_VAR, '{$.acc.three}', '3'),
            array('acc', '\pbFenom\AccessorTest::getThreeCb()', \pbFenom::ACCESSOR_CALL, '{$.acc()}', '3'),
            array('acc', 'prop', \pbFenom::ACCESSOR_PROPERTY, '{$.acc}', 'something'),
            array('acc', 'templateExists', \pbFenom::ACCESSOR_METHOD, '{$.acc("persist:pipe.tpl")}', '1')
        );
    }

    /**
     * @group testSmartAccessor
     * @dataProvider providerSmartAccessor
     * @param $name
     * @param $accessor
     * @param $type
     * @param $code
     * @param $result
     */
    public function testSmartAccessor($name, $accessor, $type, $code, $result) {
        $this->fenom->prop = "something";
        $this->fenom->addAccessorSmart($name, $accessor, $type);
        $this->assertRender($code, $result, $this->getVars());
    }

    /**
     *
     */
    public function testCallbackAccessor() {
        $index = 1;
        $test = $this;
        $this->fenom->addAccessorCallback('index', function($name, $template, $vars) use (&$index, $test) {
            $test->assertInstanceOf('pbFenom\Render', $template);
            $test->assertSame(1, $vars['one']);
            $test->assertSame('index', $name);

            return $index++;
        });

        $this->assertRender('{$.index}, {$.index}, {$.index}', '1, 2, 3', $this->getVars());
    }
} 