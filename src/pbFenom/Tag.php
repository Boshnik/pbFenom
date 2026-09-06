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


class Tag extends \ArrayObject
{
    const COMPILER = 1;
    const FUNC     = 2;
    const BLOCK    = 4;



    /**
     * @var Template
     */
    public Template $tpl;
    public string $name;
    public array $options = [];
    public int $line = 0;
    public int $level = 0;
    public mixed $callback;
    public bool $escape;

    private int $_offset = 0;
    private bool $_closed = true;
    /** @var string[] chunks of the template's body, shared by reference with Template */
    private array $_body;
    private int $_type = 0;
    private mixed $_open;
    private mixed $_close;
    private array $_tags = [];
    private array $_floats = [];
    private array $_changed = [];

    /**
     * Create tag entity
     * @param string $name the tag name
     * @param Template $tpl current template
     * @param array $info tag's information
     * @param string[] $body template's code, as a list of chunks
     */
    public function __construct(string $name, Template $tpl, array $info, array &$body)
    {
        parent::__construct();
        $this->tpl     = $tpl;
        $this->name    = $name;
        $this->line    = $tpl->getLine();
        $this->level   = $tpl->getStackSize();
        $this->_body   = & $body;
        $this->_offset = count($body);
        $this->_type   = $info["type"];
        $this->escape  = (bool)($tpl->getOptions() & \pbFenom::AUTO_ESCAPE);

        if ($this->_type & self::BLOCK) {
            $this->_open   = $info["open"];
            $this->_close  = $info["close"];
            $this->_tags   = $info["tags"] ?? [];
            $this->_floats = $info["float_tags"] ?? [];
            $this->_closed = false;
        } else {
            $this->_open = $info["parser"];
        }

        if ($this->_type & self::FUNC) {
            $this->callback = $info["function"];
        }
    }

    /**
     * Set tag option
     * @param string $option
     * @throws \RuntimeException
     */
    public function tagOption(string $option)
    {
        if (method_exists($this, 'opt' . $option)) {
            $this->options[] = $option;
        } else {
            throw new \RuntimeException("Unknown tag option $option");
        }
    }

    /**
     * Rewrite template option for tag. When tag will be closed option will be reverted.
     * @param int $option option constant
     * @param bool $value true — add option, false — remove option
     */
    public function setOption(int $option, bool $value)
    {
        $actual = (bool)($this->tpl->getOptions() & $option);
        if ($actual != $value) {
            $this->_changed[$option] = $actual;
            // was hardcoded to AUTO_ESCAPE: {strip} then silently toggled escaping
            // instead of stripping, and restore() never undid it because it restores
            // the option it recorded ($option), not the one it wrote.
            $this->tpl->setOption($option, $value);
        }
    }

    /**
     * Restore the option
     * @param int $option
     */
    public function restore(int $option)
    {
        if (isset($this->_changed[$option])) {
            $this->tpl->setOption($option, $this->_changed[$option]);
            unset($this->_changed[$option]);
        }
    }

    public function restoreAll()
    {
        foreach ($this->_changed as $option => $value) {
            $this->tpl->setOption($option, $this->_changed[$option]);
            unset($this->_changed[$option]);
        }
    }

    /**
     * Check, if the tag closed
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->_closed;
    }

    /**
     * Open callback
     *
     * @param Tokenizer $tokenizer
     * @return mixed
     */
    public function start(Tokenizer $tokenizer): mixed
    {
        foreach ($this->options as $option) {
            $option = 'opt' . $option;
            $this->$option();
        }
        return call_user_func($this->_open, $tokenizer, $this);
    }

    /**
     * Check, has the block this tag
     *
     * @param string $tag
     * @param int $level
     * @return bool
     */
    public function hasTag(string $tag, int $level): bool
    {
        if (isset($this->_tags[$tag])) {
            if ($level) {
                return isset($this->_floats[$tag]);
            } else {
                return true;
            }
        }
        return false;
    }


    /**
     * Call tag callback
     *
     * @param string $tag
     * @param Tokenizer $tokenizer
     * @return string
     * @throws \LogicException
     */
    public function tag(string $tag, Tokenizer $tokenizer): string
    {
        if (isset($this->_tags[$tag])) {
            return call_user_func($this->_tags[$tag], $tokenizer, $this);
        } else {
            throw new \LogicException("The block tag {$this->name} no have tag {$tag}");
        }
    }

    /**
     * Close callback
     *
     * @param Tokenizer $tokenizer
     * @return string
     * @throws \LogicException
     */
    public function end(Tokenizer $tokenizer): string
    {
        if ($this->_closed) {
            throw new \LogicException("Tag {$this->name} already closed");
        }
        if ($this->_close) {
            foreach ($this->options as $option) {
                $option = 'opt' . $option . 'end';
                if (method_exists($this, $option)) {
                    $this->$option();
                }
            }
            $code = call_user_func($this->_close, $tokenizer, $this);
            $this->restoreAll();
            return (string)$code;
        } else {
            throw new \LogicException("Can not use a inline tag {$this->name} as a block");
        }
    }

    /**
     * Forcefully close the tag
     */
    public function close()
    {
        $this->_closed = true;
    }

    /**
     * Returns tag's content
     *
     * @throws \LogicException
     * @return string
     */
    public function getContent(): string
    {
        return implode('', array_slice($this->_body, $this->_offset));
    }

    /**
     * Cut tag's content
     *
     * @return string
     * @throws \LogicException
     */
    public function cutContent(): string
    {
        $content = '';
        while (count($this->_body) > $this->_offset) {
            $content = array_pop($this->_body) . $content;
        }
        return $content;
    }

    /**
     * Replace tag's content
     *
     * @param $new_content
     */
    public function replaceContent($new_content)
    {
        while (count($this->_body) > $this->_offset) {
            array_pop($this->_body);
        }
        $this->_body[] = $new_content;
    }

    /**
     * Generate output code
     * @param string $code
     * @return string
     */
    public function out(string $code): string
    {
        return $this->tpl->out($code, $this->escape);
    }

    /**
     * Enable escape option for the tag
     */
    public function optEscape()
    {
        $this->escape = true;
    }

    /**
     * Disable escape option for the tag
     */
    public function optRaw()
    {
        $this->escape = false;
    }

    /**
     * Enable strip spaces option for the tag
     */
    public function optStrip()
    {
        $this->setOption(\pbFenom::AUTO_STRIP, true);
    }

    /**
     * Enable ignore for body of the tag
     */
    public function optIgnore()
    {
        if(!$this->isClosed()) {
            $this->tpl->ignore($this->name);
        }
    }

    public function optIgnoreEnd()
    {
        $this->tpl->ignore(null);
    }
}