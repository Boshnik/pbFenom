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
 * Base template provider
 * @author Ivan Shalganov
 */
class Provider implements ProviderInterface
{
    private string $_path;

    protected bool $_clear_cache = false;

    /**
     * @var array<string, string|null> memoised name => absolute path (null = rejected)
     */
    private array $_resolved = [];

    /**
     * Clean directory from files
     *
     * @param string $path
     */
    public static function clean(string $path)
    {
        if (is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            $iterator = iterator_to_array(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path,
                        \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                )
            );
            foreach ($iterator as $file) {
                /* @var \splFileInfo $file */
                if ($file->isLink()) {
                    // isFile() is true for a symlink to a regular file and getRealPath()
                    // resolves to its target, so the old code deleted files outside the
                    // directory it was asked to clean. Remove the link itself instead.
                    unlink($file->getPathname());
                } elseif ($file->isFile()) {
                    if (!str_starts_with($file->getBasename(), ".")) {
                        unlink($file->getPathname());
                    }
                } elseif ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }
        }
    }

    /**
     * Recursive remove directory
     *
     * @param string $path
     */
    public static function rm(string $path)
    {
        self::clean($path);
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    /**
     * @param string $template_dir directory of templates
     * @throws \LogicException if directory doesn't exist
     */
    public function __construct(string $template_dir)
    {
        if ($_dir = realpath($template_dir)) {
            $this->_path = $_dir;
        } else {
            throw new \LogicException("Template directory {$template_dir} doesn't exists");
        }
    }

    /**
     * Disable PHP cache for files. PHP cache some operations with files then script works.
     * @see http://php.net/manual/en/function.clearstatcache.php
     * @param bool $status
     */
    public function setClearCachedStats(bool $status = true) {
        $this->_clear_cache = $status;
        $this->_resolved    = [];
    }

    /**
     * Get source and mtime of template by name
     * @param string $tpl
     * @param float|null $time load last modified time
     * @return string
     */
    public function getSource(string $tpl, ?float &$time): string
    {
        $tpl = $this->_getTemplatePath($tpl);
        if($this->_clear_cache) {
            clearstatcache(true, $tpl);
        }
        $mtime = filemtime($tpl);
        $time  = $mtime === false ? null : (float)$mtime;
        return file_get_contents($tpl);
    }

    /**
     * Get last modified of template by name
     * @param string $tpl
     * @return float
     */
    public function getLastModified(string $tpl): float
    {
        $tpl = $this->_getTemplatePath($tpl);
        if($this->_clear_cache) {
            clearstatcache(true, $tpl);
        }
        return (float)filemtime($tpl);
    }

    /**
     * Get all names of templates from provider.
     *
     * @param string $extension all templates must have this extension, default .tpl
     * @return iterable
     */
    public function getList(string $extension = "tpl"): iterable
    {
        $list     = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->_path,
                \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $path_len = strlen($this->_path);
        foreach ($iterator as $file) {
            /* @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() == $extension) {
                $list[] = substr($file->getPathname(), $path_len + 1);
            }
        }
        return $list;
    }

    /**
     * Get template path
     * @param string $tpl
     * @return string
     * @throws \RuntimeException
     */
    protected function _getTemplatePath(string $tpl): string
    {
        if (($path = $this->_resolve($tpl)) !== null) {
            return $path;
        }
        throw new \RuntimeException("Template $tpl not found");
    }

    /**
     * Resolve a template name to an absolute path inside the template root,
     * or null when it escapes the root or does not exist.
     */
    private function _resolve(string $tpl): ?string
    {
        // realpath() is the single most expensive call on the AUTO_RELOAD path, and a
        // template name resolves to the same file for the whole request. Memoising it
        // keeps the containment check off the hot path. setClearCachedStats() opts out.
        if (!$this->_clear_cache && array_key_exists($tpl, $this->_resolved)) {
            return $this->_resolved[$tpl];
        }
        $path = $this->_resolveUncached($tpl);
        if (!$this->_clear_cache) {
            $this->_resolved[$tpl] = $path;
        }
        return $path;
    }

    private function _resolveUncached(string $tpl): ?string
    {
        if (str_contains($tpl, "\0")) {
            // realpath() raises an uncaught ValueError on NUL, turning a 404 into a 500
            return null;
        }
        $path = realpath($this->_path . "/" . $tpl);
        if ($path === false || !is_file($path)) {
            return null;
        }
        // The trailing separator matters: a bare prefix test also accepts sibling
        // directories whose name merely starts with the root's ("/app/tpl_backup"
        // passes a check against "/app/tpl").
        $root = rtrim($this->_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? $path : null;
    }

    /**
     * @param string $tpl
     * @return bool
     */
    public function templateExists(string $tpl): bool
    {
        return $this->_resolve($tpl) !== null;
    }

    /**
     * Verify templates (check change time)
     *
     * @param array $templates [template_name => modified, ...] By conversation, you may trust the template's name
     * @return bool
     */
    public function verify(array $templates): bool
    {
        foreach ($templates as $template => $mtime) {
            // this was the one entry point that concatenated the path directly,
            // so a doctored `depends` list could stat any file on disk
            $path = $this->_resolve($template);
            if ($path === null) {
                return false;
            }
            if($this->_clear_cache) {
                clearstatcache(true, $path);
            }
            if (filemtime($path) != $mtime) {
                return false;
            }
        }
        return true;
    }
}